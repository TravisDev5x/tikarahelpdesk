<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Services\InternalCustomerService;
use App\Services\TicketCreationService;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Fase 2 del sprint maestro: jerarquía Client (tenant) -> Customer (empresa
 * soportada) -> Site. Reemplaza "Opción B" (clients.is_internal) -- ver
 * database/migrations/2026_07_11_000006..000009 y
 * App\Services\InternalCustomerService.
 */
class CustomerHierarchyTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    // ── Customer implícito ──────────────────────────────────────────────

    public function test_internal_customer_service_creates_implicit_customer_and_its_client_together(): void
    {
        $operator = $this->makeOperator();

        $service = app(InternalCustomerService::class);
        $customer = $service->ensureFor($operator, 'Mi Empresa SA de CV');

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertTrue($customer->is_internal);
        $this->assertSame('Mi Empresa SA de CV', $customer->name);

        $client = $customer->client;
        $this->assertNotNull($client);
        $this->assertSame($operator->id, $client->operator_user_id);
        $this->assertSame('Mi Empresa SA de CV', $client->name);
        $this->assertNotNull($client->ticket_prefix);

        // Idempotente: segunda llamada devuelve el mismo Customer, no duplica
        // ni el Customer ni el Client que lo ancla.
        $again = $service->ensureFor($operator, 'Otro Nombre Ignorado');
        $this->assertSame($customer->id, $again->id);
        $this->assertSame(1, Customer::where('is_internal', true)
            ->whereHas('client', fn ($q) => $q->where('operator_user_id', $operator->id))
            ->count());
        $this->assertSame(1, Client::where('operator_user_id', $operator->id)->count());
    }

    /**
     * Site por defecto (2026-08-10, Fase 7.7): ensureForClient() también
     * garantiza un Site bajo el Customer interno, no solo el Customer.
     * Idempotente igual -- reinvocarlo sobre un Client viejo que ya tenía
     * Customer pero ningún Site (dev preexistente) lo rellena sin duplicar,
     * sin necesitar un comando de backfill aparte.
     */
    public function test_ensure_for_client_also_creates_a_default_site_under_the_internal_customer(): void
    {
        $client = Client::create(['name' => 'Sin Sites Todavía', 'portal_slug' => 'sin-sites-'.uniqid(), 'is_active' => true]);

        $service = app(InternalCustomerService::class);
        PgsqlRowLevelSecurity::setBypass(true);
        $customer = Customer::where('client_id', $client->id)->where('is_internal', true)->firstOrFail();
        $sites = Site::where('customer_id', $customer->id)->get();
        PgsqlRowLevelSecurity::clear();

        $this->assertCount(1, $sites, 'Client::booted() ya debía haber creado el Site por defecto al crear el Client.');
        $this->assertSame('Oficina principal', $sites->first()->name);

        // Reinvocarlo (simula correr el backfill sobre un Client viejo) no duplica.
        $service->ensureForClient($client->fresh());
        PgsqlRowLevelSecurity::setBypass(true);
        $count = Site::where('customer_id', $customer->id)->count();
        PgsqlRowLevelSecurity::clear();
        $this->assertSame(1, $count);
    }

    public function test_backfill_internal_customer_command(): void
    {
        $operator = $this->makeOperator();

        $this->artisan('tenants:backfill-internal-customer --dry-run')->assertSuccessful();
        $this->assertSame(0, Customer::where('is_internal', true)->count());

        $this->artisan('tenants:backfill-internal-customer')->assertSuccessful();
        $this->assertSame(1, Customer::where('is_internal', true)->count());

        // Idempotente
        $this->artisan('tenants:backfill-internal-customer')->assertSuccessful();
        $this->assertSame(1, Customer::where('is_internal', true)->count());
    }

    public function test_backfill_internal_customer_command_links_orphan_sites_of_the_internal_client(): void
    {
        // ensureFor() SIEMPRE crea un Client nuevo si no encuentra un
        // Customer interno existente -- no "adopta" un Client legacy
        // preexistente (mismo comportamiento que ya tenía la versión
        // Opción B). Por eso este test simula el caso real: una sede que
        // quedó sin customer_id bajo el client_id del interno (ej. insert
        // directo / seed), corrida DESPUÉS de que el customer ya existe --
        // no la primera corrida que lo crea.
        $operator = $this->makeOperator();
        $customer = app(InternalCustomerService::class)->ensureFor($operator, 'Interno');

        $site = Site::create(['name' => 'Oficina huérfana', 'client_id' => $customer->client_id, 'type' => 'physical', 'is_active' => true]);
        $this->assertNull($site->customer_id);

        $this->artisan('tenants:backfill-internal-customer')->assertSuccessful();

        $this->assertSame($customer->id, $site->fresh()->customer_id);
    }

    // ── Herencia de site desde el requester (sin cambios en Fase 2) ─────

    public function test_ticket_inherits_site_from_requester(): void
    {
        $fixture = $this->createTenantFixtureSet();

        $ticket = app(TicketCreationService::class)->create($this->baseAttributes($fixture));

        $this->assertSame($fixture['site_id'], $ticket->site_id);
    }

    public function test_requester_without_site_creates_ticket_with_null_site(): void
    {
        $fixture = $this->createTenantFixtureSet();
        DB::table('users')->where('id', $fixture['user_id'])->update(['site_id' => null]);

        $ticket = app(TicketCreationService::class)->create($this->baseAttributes($fixture));

        $this->assertNull($ticket->site_id);
        // El tenant no se pierde por la falta de site
        $this->assertSame($fixture['client_id'], $ticket->client_id);
        $this->assertNotNull($ticket->folio);
    }

    public function test_explicit_site_id_is_respected_over_inheritance(): void
    {
        $fixture = $this->createTenantFixtureSet();

        $otherSite = DB::table('sites')->insertGetId([
            'name' => 'Sede alterna', 'code' => 'ALT-'.uniqid(), 'type' => 'physical',
            'is_active' => true, 'client_id' => $fixture['client_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $attrs = $this->baseAttributes($fixture);
        $attrs['site_id'] = $otherSite;

        $ticket = app(TicketCreationService::class)->create($attrs);

        $this->assertSame($otherSite, $ticket->site_id);
    }

    // ── Integridad no-cross-tenant (sin cambios en Fase 2) ──────────────

    public function test_site_of_another_tenant_is_rejected(): void
    {
        $fixture = $this->createTenantFixtureSet();

        $foreignClient = Client::create(['name' => 'Tenant Ajeno', 'is_active' => true]);
        $foreignSite = DB::table('sites')->insertGetId([
            'name' => 'Sede ajena', 'code' => 'FOR-'.uniqid(), 'type' => 'physical',
            'is_active' => true, 'client_id' => $foreignClient->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $attrs = $this->baseAttributes($fixture);
        $attrs['site_id'] = $foreignSite;

        $this->expectException(\InvalidArgumentException::class);
        app(TicketCreationService::class)->create($attrs);
    }

    public function test_global_site_is_accepted_and_does_not_null_the_tenant(): void
    {
        $fixture = $this->createTenantFixtureSet();

        // "Remoto" del seed: site global sin client_id
        $globalSiteId = (int) DB::table('sites')->whereNull('client_id')->value('id');
        $this->assertGreaterThan(0, $globalSiteId);

        $attrs = $this->baseAttributes($fixture);
        $attrs['site_id'] = $globalSiteId;

        $ticket = app(TicketCreationService::class)->create($attrs);

        $this->assertSame($globalSiteId, $ticket->site_id);
        // El hook saving NO anula el client_id del ticket por site global
        $this->assertSame($fixture['client_id'], $ticket->client_id);
    }

    // ── sites.name único por client, ya no global (sin cambios) ─────────

    public function test_two_customers_can_have_sites_with_the_same_name(): void
    {
        $a = Client::create(['name' => 'Customer Uno', 'is_active' => true]);
        $b = Client::create(['name' => 'Customer Dos', 'is_active' => true]);

        $siteA = Site::create(['name' => 'Oficina Central', 'client_id' => $a->id, 'type' => 'physical', 'is_active' => true]);
        $siteB = Site::create(['name' => 'Oficina Central', 'client_id' => $b->id, 'type' => 'physical', 'is_active' => true]);

        $this->assertNotSame($siteA->id, $siteB->id);

        // Pero dentro del MISMO customer sí colisiona
        $this->expectException(\Illuminate\Database\QueryException::class);
        Site::create(['name' => 'Oficina Central', 'client_id' => $a->id, 'type' => 'physical', 'is_active' => true]);
    }

    // ── Consistencia site.client_id <-> customer.client_id (Fase 2 nuevo) ─

    public function test_site_customer_must_belong_to_the_same_client_as_the_site(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'is_active' => true]);
        $clientB = Client::create(['name' => 'Client B', 'is_active' => true]);
        $customerOfB = Customer::create(['client_id' => $clientB->id, 'name' => 'Customer de B', 'is_internal' => false]);

        $this->expectException(\InvalidArgumentException::class);
        Site::create([
            'name' => 'Sede inconsistente', 'client_id' => $clientA->id,
            'customer_id' => $customerOfB->id, 'type' => 'physical', 'is_active' => true,
        ]);
    }

    public function test_site_customer_of_the_same_client_is_accepted(): void
    {
        $client = Client::create(['name' => 'Client C', 'is_active' => true]);
        $customer = Customer::create(['client_id' => $client->id, 'name' => 'Customer de C', 'is_internal' => false]);

        $site = Site::create([
            'name' => 'Sede consistente', 'client_id' => $client->id,
            'customer_id' => $customer->id, 'type' => 'physical', 'is_active' => true,
        ]);

        $this->assertSame($customer->id, $site->customer_id);
    }

    // ── RLS de customers bajo PostgreSQL real ────────────────────────────
    // Verificado contra Postgres real con TENANCY_PGSQL_RLS=true
    // (composer test:pgsql) -- se salta solo si el driver activo no es pgsql.
    //
    // Fase 7 (2026-08-09): ya NO crea los Customer a mano -- Client::booted()
    // ahora crea automáticamente el Customer interno de CUALQUIER Client
    // nuevo (antes solo lo hacía el flujo legacy de operador). Crearlos
    // también a mano aquí duplicaría filas is_internal=true por client_id y
    // rompería el conteo. La creación automática ya pasa por
    // InternalCustomerService::ensureForClient() -> PgsqlRowLevelSecurity::
    // withBypass(), que es justo lo que este test verifica que funciona.

    public function test_rls_isolates_customers_between_tenants(): void
    {
        if (! PgsqlRowLevelSecurity::enabled()) {
            $this->markTestSkipped('Requiere PostgreSQL con TENANCY_PGSQL_RLS=true.');
        }

        $clientA = Client::create(['name' => 'RLS Client A', 'is_active' => true]);
        $clientB = Client::create(['name' => 'RLS Client B', 'is_active' => true]);

        PgsqlRowLevelSecurity::setBypass(true);
        $this->assertSame(2, Customer::query()->count());

        DB::statement("SELECT set_config('app.tenant_client_id', ?, false)", [(string) $clientA->id]);
        DB::statement("SELECT set_config('app.tenant_bypass', 'false', false)");

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($clientA->name, Customer::query()->value('name'));

        PgsqlRowLevelSecurity::clear();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeOperator(): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Operador', 'paternal_last_name' => 'Prueba',
            'email' => 'op-'.uniqid().'@test.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'status' => 'active', 'is_operator' => true, 'onboarding_completed' => true,
        ]);
    }

    private function baseAttributes(array $fixture): array
    {
        return [
            'subject' => 'Jerarquía',
            'area_origin_id' => $fixture['area_id'],
            'area_current_id' => $fixture['area_id'],
            'client_id' => $fixture['client_id'],
            'requester_id' => $fixture['user_id'],
            'ticket_type_id' => $fixture['ticket_type_id'],
            'priority_id' => $fixture['priority_id'],
            'ticket_state_id' => $fixture['ticket_state_id'],
        ];
    }
}
