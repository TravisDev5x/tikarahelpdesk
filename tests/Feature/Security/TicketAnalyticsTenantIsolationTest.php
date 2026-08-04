<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TicketAnalyticsController agrega conteos (por estado, "quemados", áreas,
 * tipos, top requesters/resolvers) sobre TicketPolicy::scopeFor() -- ningún
 * test existente golpea /api/tickets/analytics con datos de dos tenants
 * distintos para confirmar que las cifras agregadas no se filtran entre
 * ellos. Previene: un reporte/dashboard que sume o liste entidades
 * (requesters, conteos por estado) de un tenant ajeno porque alguna de las
 * ~10 sub-queries de agregación olvidó aplicar el scope base.
 */
class TicketAnalyticsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_counts_and_top_requesters_exclude_foreign_tenant_tickets(): void
    {
        $operator = $this->makeUser('operator@test.local');

        $clientA = Client::create(['name' => 'Tenant Analytics A', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'Tenant Analytics B', 'operator_user_id' => $operator->id, 'is_active' => true]);

        $siteA = Site::create(['client_id' => $clientA->id, 'name' => 'Site A', 'type' => 'physical', 'is_active' => true]);
        $siteB = Site::create(['client_id' => $clientB->id, 'name' => 'Site B', 'type' => 'physical', 'is_active' => true]);

        // Rol creado a mano (sin TenantRoleSeeder) bajo un único team_id que
        // nunca cambia dentro de este test: sembrar TenantRoleSeeder para
        // DOS tenants y alternar setPermissionsTeamId() en el mismo proceso
        // expone un problema de caché de roles de Spatie ajeno a lo que
        // este test busca probar (aislamiento de datos agregados en
        // /api/tickets/analytics, no el cacheo de permisos). TicketPolicy
        // resuelve el alcance por scope_archetype del rol (RBAC v2), no por
        // permisos sueltos -- givePermissionTo() sin rol no basta para que
        // viewAny()/scopeFor() reconozcan a agentA.
        setPermissionsTeamId($clientA->id);
        Permission::firstOrCreate(['name' => 'tickets.view_area', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.filter_by_site', 'guard_name' => 'web']);
        $agenteRole = Role::create([
            'name' => 'agente', 'slug' => 'agente', 'guard_name' => 'web',
            'team_id' => $clientA->id, 'scope_archetype' => 'agente',
        ]);
        $agenteRole->givePermissionTo(['tickets.view_area', 'tickets.filter_by_site']);

        $agentA = $this->makeUser('agent-a@test.local', $clientA->id);
        $agentA->assignRole($agenteRole);
        $agentA->sites()->sync([$siteA->id]);

        $requesterA = $this->makeUser('requester-a@test.local', $clientA->id, 'RequesterA');
        $requesterB = $this->makeUser('requester-b@test.local', $clientB->id, 'RequesterB');

        $catalog = $this->makeCatalog();

        // 3 tickets del tenant A, visibles para agentA.
        for ($i = 0; $i < 3; $i++) {
            $this->makeTicket($clientA->id, $siteA->id, $requesterA->id, $catalog, "Ticket A{$i}");
        }

        // 5 tickets del tenant B -- NO deben aparecer en ningún agregado de agentA.
        for ($i = 0; $i < 5; $i++) {
            $this->makeTicket($clientB->id, $siteB->id, $requesterB->id, $catalog, "Ticket B{$i}");
        }

        $response = $this->actingAs($agentA, 'web')->getJson('/api/tickets/analytics');

        $response->assertOk();

        $totalFromStates = collect($response->json('states'))->sum('value');
        $this->assertSame(3, $totalFromStates, 'El total agregado por estado debe contar solo los 3 tickets del tenant A.');

        $requesterLabels = collect($response->json('top_requesters'))->pluck('label')->all();
        $this->assertContains($requesterA->name, $requesterLabels);
        $this->assertNotContains($requesterB->name, $requesterLabels, 'top_requesters no debe listar solicitantes de otro tenant.');
    }

    private function makeUser(string $email, ?int $clientId = null, string $lastName = 'User'): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => $lastName,
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

    private function makeTicket(int $clientId, int $siteId, int $requesterId, array $catalog, string $subject): Ticket
    {
        return Ticket::create([
            'subject' => $subject,
            'folio' => 'ANA-'.uniqid(),
            'area_origin_id' => $catalog['area_id'],
            'area_current_id' => $catalog['area_id'],
            'site_id' => $siteId,
            'client_id' => $clientId,
            'requester_id' => $requesterId,
            'ticket_type_id' => $catalog['ticket_type_id'],
            'priority_id' => $catalog['priority_id'],
            'ticket_state_id' => $catalog['ticket_state_id'],
        ]);
    }
}
