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
 * Fase 1 de la consolidación de dashboards (docs/PENDING.md): antes,
 * ResolbebController::applyFilters() aplicaba site_id con un where() crudo,
 * sin pasar por ClientScopeService::applySiteFilter()/assertSiteAccessible()
 * como sí hacía TicketAnalyticsController -- dos implementaciones que ya
 * habían divergido. Ahora ambos comparten TicketQueryFilterService::apply().
 *
 * Este test fija el comportamiento correcto (igual al que ya tenía
 * Analytics): un site_id que NO pertenece al alcance del usuario se
 * IGNORA (silencioso), no vacía los resultados como hacía el where() crudo
 * viejo -- confirma que la unificación realmente cerró la divergencia y no
 * solo movió el código.
 */
class ResolbebDashboardSiteFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_site_id_is_ignored_instead_of_emptying_results(): void
    {
        $operator = $this->makeUser('operator@test.local');

        $clientA = Client::create(['name' => 'Tenant Resolbeb A', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'Tenant Resolbeb B', 'operator_user_id' => $operator->id, 'is_active' => true]);

        $siteA = Site::create(['client_id' => $clientA->id, 'name' => 'Site A', 'type' => 'physical', 'is_active' => true]);
        $siteB = Site::create(['client_id' => $clientB->id, 'name' => 'Site B', 'type' => 'physical', 'is_active' => true]);

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

        $requesterA = $this->makeUser('requester-a@test.local', $clientA->id);
        $catalog = $this->makeCatalog();

        // 3 tickets sin asignar del tenant A, visibles para agentA.
        for ($i = 0; $i < 3; $i++) {
            $this->makeTicket($clientA->id, $siteA->id, $requesterA->id, $catalog, "Ticket A{$i}");
        }

        // site_id de un cliente ajeno (mismo operador, pero fuera del scope de agentA).
        $response = $this->actingAs($agentA, 'web')
            ->getJson('/api/tickets/dashboard-operativo?site_id='.$siteB->id);

        $response->assertOk();
        $this->assertSame(
            3,
            $response->json('kpis.sin_asignar'),
            'Un site_id ajeno al alcance del usuario debe ignorarse (como ya hacía Analytics), no vaciar los resultados del usuario.'
        );
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

    private function makeTicket(int $clientId, int $siteId, int $requesterId, array $catalog, string $subject): Ticket
    {
        return Ticket::create([
            'subject' => $subject,
            'folio' => 'RSB-'.uniqid(),
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
