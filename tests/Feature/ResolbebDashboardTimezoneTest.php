<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditoría 2026-08-10: ResolbebController calculaba "hoy"/"esta
 * semana"/"este mes" con Carbon::now() -- siempre UTC
 * (config('app.timezone')) -- mientras el negocio opera en hora de México.
 * Confirmado con datos reales de dev: un ticket creado a las 19:00-23:00
 * hora México ya es "el día siguiente" en UTC, y aparecía en el balde
 * equivocado de "Tendencia de resolución". Estos tests reproducen ese bug
 * exacto como regresión, ahora resuelto vía clients.business_timezone.
 */
class ResolbebDashboardTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_ticket_created_at_23h_local_lands_in_todays_bucket_not_tomorrows(): void
    {
        // "Ahora" congelado exactamente al momento de creación del ticket:
        // 2026-03-15 23:00:00 hora México (-06:00) == 2026-03-16 05:00:00 UTC.
        // Bajo el bug viejo (Carbon::today() en UTC), "hoy" habría sido
        // 16/03, y el ticket habría quedado ahí -- coincidiendo con el bug,
        // no revelándolo. Lo que hay que confirmar es que el balde de "hoy"
        // del NEGOCIO es 15/03 (no 16/03) y que el ticket cae ahí.
        $frozen = Carbon::create(2026, 3, 15, 23, 0, 0, 'America/Mexico_City');
        Carbon::setTestNow($frozen);

        [$user, $client] = $this->makeTenant('America/Mexico_City');
        $this->makeTicket($client->id, subject: 'Ticket de las 23h locales');

        $response = $this->actingAs($user, 'web')->getJson('/api/tickets/dashboard-operativo');
        $response->assertOk();

        $tendencia = $response->json('tendencia');
        $hoy = end($tendencia);

        $this->assertSame('2026-03-15', $hoy['fecha'], 'El balde de "hoy" debe ser el día LOCAL del negocio (15/03), no el de UTC (16/03).');
        $this->assertSame(1, $hoy['creados'], 'El ticket creado a las 23:00 hora México debe contarse en el balde de hoy, no perderse ni caer en el de mañana.');

        // El array de 15 días no debe incluir NINGÚN balde fechado 16/03 --
        // ese día, desde la perspectiva del negocio, todavía no empieza.
        $fechas = array_column($tendencia, 'fecha');
        $this->assertNotContains('2026-03-16', $fechas);
    }

    public function test_two_tenants_with_different_business_timezone_compute_their_today_bucket_independently(): void
    {
        // Ancla neutral a mediodía UTC -- ninguno de los 2 timezones cruza
        // medianoche local en este instante, así que cada quien calcula su
        // propio "hoy" de forma independiente sin interferir con el otro.
        Carbon::setTestNow(Carbon::create(2026, 3, 15, 12, 0, 0, 'UTC'));

        [$userMx, $clientMx] = $this->makeTenant('America/Mexico_City');
        [$userTj, $clientTj] = $this->makeTenant('America/Tijuana');

        $mxLocalToday = Carbon::now('America/Mexico_City')->toDateString();
        $tjLocalToday = Carbon::now('America/Tijuana')->toDateString();

        // Cada ticket a las 23:00 de SU PROPIA hora local -- offsets UTC distintos.
        $this->makeTicket($clientMx->id, subject: 'Ticket MX', localTime: Carbon::now('America/Mexico_City')->setTime(23, 0, 0));
        $this->makeTicket($clientTj->id, subject: 'Ticket TJ', localTime: Carbon::now('America/Tijuana')->setTime(23, 0, 0));

        $responseMx = $this->actingAs($userMx, 'web')->getJson('/api/tickets/dashboard-operativo')->assertOk();
        $responseTj = $this->actingAs($userTj, 'web')->getJson('/api/tickets/dashboard-operativo')->assertOk();

        $tendenciaMx = $responseMx->json('tendencia');
        $tendenciaTj = $responseTj->json('tendencia');
        $hoyMx = end($tendenciaMx);
        $hoyTj = end($tendenciaTj);

        $this->assertSame($mxLocalToday, $hoyMx['fecha']);
        $this->assertSame(1, $hoyMx['creados'], 'El tenant de México debe contar su propio ticket en su propio hoy.');

        $this->assertSame($tjLocalToday, $hoyTj['fecha']);
        $this->assertSame(1, $hoyTj['creados'], 'El tenant de Tijuana debe contar su propio ticket en su propio hoy, con SU OFFSET, no el de México.');
    }

    /** @return array{0: User, 1: Client} */
    private function makeTenant(string $businessTimezone): array
    {
        $operator = $this->makeUser('operator-'.uniqid().'@test.local');
        $client = Client::create([
            'name' => 'Tenant TZ '.uniqid(),
            'operator_user_id' => $operator->id,
            'is_active' => true,
            'business_timezone' => $businessTimezone,
        ]);

        $roleName = 'admin-tz-test-'.uniqid();
        setPermissionsTeamId($client->id);
        Permission::firstOrCreate(['name' => 'tickets.manage_all', 'guard_name' => 'web']);
        $role = Role::create([
            'name' => $roleName, 'slug' => $roleName, 'guard_name' => 'web',
            'team_id' => $client->id, 'scope_archetype' => 'admin',
        ]);
        $role->givePermissionTo(['tickets.manage_all']);

        $admin = $this->makeUser('admin-'.uniqid().'@test.local', $client->id);
        $admin->assignRole($role);

        return [$admin, $client];
    }

    private function makeTicket(int $clientId, string $subject, ?Carbon $localTime = null): Ticket
    {
        $requester = $this->makeUser('requester-'.uniqid().'@test.local', $clientId);
        $catalog = $this->makeCatalog();
        $siteId = DB::table('sites')->insertGetId([
            'name' => 'Sede-'.uniqid(), 'client_id' => $clientId, 'type' => 'physical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ticket = Ticket::create([
            'subject' => $subject,
            'folio' => 'TZ-'.uniqid(),
            'area_origin_id' => $catalog['area_id'],
            'area_current_id' => $catalog['area_id'],
            'site_id' => $siteId,
            'client_id' => $clientId,
            'requester_id' => $requester->id,
            'ticket_type_id' => $catalog['ticket_type_id'],
            'priority_id' => $catalog['priority_id'],
            'ticket_state_id' => $catalog['ticket_state_id'],
        ]);

        if ($localTime) {
            $ticket->forceFill(['created_at' => $localTime->copy()->setTimezone('UTC')])->save();
        }

        return $ticket->fresh();
    }

    private function makeUser(string $email, ?int $clientId = null): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'client_id' => $clientId,
            'status' => 'active', 'onboarding_completed' => true,
        ]);
    }

    private function makeCatalog(): array
    {
        $now = now();

        return [
            'area_id' => DB::table('areas')->insertGetId(['name' => 'Area'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'priority_id' => DB::table('priorities')->insertGetId(['name' => 'Prio'.uniqid(), 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_state_id' => DB::table('ticket_states')->insertGetId(['name' => 'Estado'.uniqid(), 'code' => 'st'.uniqid(), 'is_active' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_type_id' => DB::table('ticket_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
        ];
    }
}
