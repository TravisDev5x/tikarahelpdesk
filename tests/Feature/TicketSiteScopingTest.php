<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 4 del sprint maestro: TicketPolicy pasa de alcance por área a
 * alcance por site (pivote site_user). admin todo / supervisor sus sites /
 * agente asignados+sin asignar de sus sites / solicitante solo suyos.
 */
class TicketSiteScopingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Site $siteA;

    private Site $siteB;

    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->makeUser('admin@test.local');
        $admin->update(['is_operator' => true]);

        $this->client = Client::create(['name' => 'Tenant Scoping', 'operator_user_id' => $admin->id, 'is_active' => true]);

        // RBAC v2 (Fase 6): las plantillas ahora viven dentro del team_id
        // del tenant (spatie/laravel-permission teams) -- por eso el client
        // se crea ANTES de sembrar roles, no después como antes de Fase 6.
        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->siteA = Site::create(['client_id' => $this->client->id, 'name' => 'Site A', 'type' => 'physical', 'is_active' => true]);
        $this->siteB = Site::create(['client_id' => $this->client->id, 'name' => 'Site B', 'type' => 'physical', 'is_active' => true]);
        $this->catalog = $this->makeCatalog();
        $this->admin = $admin;
    }

    private User $admin;

    // ── admin ────────────────────────────────────────────────────────────

    public function test_admin_sees_tickets_of_any_site(): void
    {
        $ticketA = $this->makeTicket($this->siteA->id);
        $ticketB = $this->makeTicket($this->siteB->id);
        $ticketNoSite = $this->makeTicket(null);

        $ids = $this->indexAs($this->admin);

        $this->assertContains($ticketA->id, $ids);
        $this->assertContains($ticketB->id, $ids);
        $this->assertContains($ticketNoSite->id, $ids);
    }

    // ── supervisor ───────────────────────────────────────────────────────

    public function test_supervisor_sees_all_tickets_of_their_sites_regardless_of_assignment(): void
    {
        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);

        $unassigned = $this->makeTicket($this->siteA->id);
        $assignedToOther = $this->makeTicket($this->siteA->id, $agentA->id);

        $ids = $this->indexAs($supervisor);

        $this->assertContains($unassigned->id, $ids);
        $this->assertContains($assignedToOther->id, $ids);
    }

    public function test_supervisor_does_not_see_tickets_of_a_site_they_are_not_linked_to(): void
    {
        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $ticketB = $this->makeTicket($this->siteB->id);

        $this->assertNotContains($ticketB->id, $this->indexAs($supervisor));

        $response = $this->actingAs($supervisor, 'web')->getJson($this->ticketUrl($ticketB->id));
        $response->assertStatus(403);
    }

    public function test_supervisor_does_not_see_tickets_without_a_site(): void
    {
        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $ticketNoSite = $this->makeTicket(null);

        $this->assertNotContains($ticketNoSite->id, $this->indexAs($supervisor));
    }

    // ── agente ───────────────────────────────────────────────────────────

    public function test_agente_sees_unassigned_and_own_assigned_tickets_of_their_sites(): void
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $otherAgentA = $this->makeStaff('agente', [$this->siteA->id]);

        $unassigned = $this->makeTicket($this->siteA->id);
        $assignedToMe = $this->makeTicket($this->siteA->id, $agentA->id);
        $assignedToOther = $this->makeTicket($this->siteA->id, $otherAgentA->id);

        $ids = $this->indexAs($agentA);

        $this->assertContains($unassigned->id, $ids);
        $this->assertContains($assignedToMe->id, $ids);
        $this->assertNotContains($assignedToOther->id, $ids);
    }

    public function test_agente_does_not_see_tickets_outside_their_sites(): void
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticketB = $this->makeTicket($this->siteB->id);

        $this->assertNotContains($ticketB->id, $this->indexAs($agentA));
    }

    public function test_agente_does_not_see_tickets_without_a_site(): void
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticketNoSite = $this->makeTicket(null);

        $this->assertNotContains($ticketNoSite->id, $this->indexAs($agentA));
    }

    /**
     * "Test de desasignación = pérdida de visibilidad inmediata" (checklist
     * de Fase 4): un ticket asignado a un agente y luego REASIGNADO a otro
     * agente del mismo site desaparece de inmediato de la vista del primero
     * -- sin caché, sin estado intermedio, la siguiente consulta ya no lo trae.
     */
    /**
     * "Test de desasignación = pérdida de visibilidad inmediata" (checklist
     * de Fase 4): un ticket asignado a un agente y luego REASIGNADO a otro
     * agente del mismo site desaparece de inmediato de la vista del primero
     * -- sin caché, sin estado intermedio, la siguiente consulta ya no lo trae.
     *
     * (Que el nuevo asignado SÍ lo vea ya está cubierto por
     * test_agente_sees_unassigned_and_own_assigned_tickets_of_their_sites;
     * no se repite aquí con un segundo actingAs() en el mismo test porque el
     * guard stateful de Sanctum de este proyecto no soporta cambiar de
     * usuario autenticado a mitad de un test -- ver test_two_different_users_...
     * más abajo si hace falta revisar ese límite de la suite.)
     */
    public function test_reassigning_a_ticket_away_removes_visibility_immediately(): void
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $agentA2 = $this->makeStaff('agente', [$this->siteA->id]);

        $ticket = $this->makeTicket($this->siteA->id, $agentA->id);
        $this->assertContains($ticket->id, $this->indexAs($agentA));
        $this->assertTrue($this->canView($agentA, $ticket));

        // La petición HTTP anterior (indexAs) limpia las variables de
        // sesión RLS al terminar (ApplyPgsqlTenantRls, por diseño). Lo que
        // sigue es una operación directa fuera de request HTTP -- necesita
        // bypass explícito bajo Postgres, igual que TicketApiTest.
        \App\Support\Tenancy\PgsqlRowLevelSecurity::setBypass(true);
        $ticket->update(['assigned_user_id' => $agentA2->id]);
        $ticket = $ticket->fresh();

        $this->assertNotContains($ticket->id, $this->indexAs($agentA));
        $this->assertFalse($this->canView($agentA, $ticket));

        // Verificado a nivel de policy (no HTTP) para no depender del
        // límite de actingAs() con dos usuarios en un mismo test: el nuevo
        // asignado SÍ está en el resultado de scopeFor.
        $newAssigneeIds = app(\App\Policies\TicketPolicy::class)
            ->scopeFor($agentA2, \App\Models\Ticket::query())
            ->pluck('id');
        $this->assertContains($ticket->id, $newAssigneeIds);
    }

    // ── solicitante ──────────────────────────────────────────────────────

    public function test_solicitante_sees_only_their_own_tickets(): void
    {
        $requester = $this->makeStaff('solicitante', []);
        $otherRequester = $this->makeStaff('solicitante', []);

        $mine = $this->makeTicket($this->siteA->id, null, $requester->id);
        $theirs = $this->makeTicket($this->siteA->id, null, $otherRequester->id);

        $ids = $this->indexAs($requester);

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // ── legacy sin migrar (riesgo documentado en el commit) ─────────────

    public function test_user_with_only_legacy_role_loses_all_ticket_visibility(): void
    {
        $legacyOnly = $this->makeUser('legacy@test.local');
        $legacyOnly->update(['client_id' => $this->client->id]);
        $legacyOnly->assignRole(\App\Models\Role::firstOrCreate(
            ['name' => 'soporte', 'guard_name' => 'web'],
            ['slug' => 'soporte']
        ));

        $this->makeTicket($this->siteA->id);

        $response = $this->actingAs($legacyOnly, 'web')->getJson('/api/tickets');
        $response->assertStatus(403);
    }

    // ── acciones de mutación unificadas a site scope (corrección del bug) ──
    //
    // Antes de esta corrección, update()/assign()/release()/escalate()/
    // comment() seguían gateados por isCurrentArea() (area_current_id),
    // mientras que view()/scopeFor() ya usaban site_user. Un agente/
    // supervisor con acceso solo por site (sin area_id coincidente, el caso
    // normal bajo Fase 4) podía VER un ticket pero fallaba silenciosamente
    // todos los checks de mutación. Estos tests fijan exactamente ese
    // escenario: makeStaff() ya asigna a cada usuario un area_id propio y
    // aleatorio que nunca coincide con el area_id del ticket (ver
    // makeCatalog()/makeUser()), así que cualquier regresión a
    // isCurrentArea() los haría fallar de inmediato.

    public function test_agente_without_matching_area_can_update_comment_and_escalate_unassigned_ticket_of_their_site(): void
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        $this->assertTrue($this->canView($agentA, $ticket));
        $this->assertTrue($this->authorizes('assign', $agentA, $ticket, $agentA));

        $update = $this->actingAs($agentA, 'web')->patchJson($this->ticketUrl($ticket->id), [
            'note' => 'Atendiendo desde mi site',
        ]);
        $update->assertStatus(200);

        $otherAreaId = $this->makeArea();
        $escalate = $this->actingAs($agentA, 'web')->postJson("/api/tickets/{$ticket->id}/escalate", [
            'area_destino_id' => $otherAreaId,
        ]);
        $escalate->assertStatus(200);
    }

    public function test_supervisor_can_reassign_and_release_tickets_of_their_site_regardless_of_area_current_id(): void
    {
        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $agentA2 = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id, $agentA->id);

        // area_current_id deliberadamente distinta al area_id de nadie
        // involucrado -- si algo todavía dependiera de isCurrentArea(),
        // esto fallaría.
        \App\Support\Tenancy\PgsqlRowLevelSecurity::setBypass(true);
        $ticket->update(['area_current_id' => $this->makeArea()]);

        // Supervisor puede reasignar/liberar un ticket de su site aunque no
        // sea el responsable actual (agentA lo es, no el supervisor) -- a
        // otro agente (agentA2) también vinculado a ese site.
        $this->assertTrue($this->authorizes('reassign', $supervisor, $ticket, $agentA2));
        $this->assertTrue($this->authorizes('release', $supervisor, $ticket));
    }

    public function test_agente_of_another_site_cannot_update_or_comment_even_forcing_the_url(): void
    {
        $agentB = $this->makeStaff('agente', [$this->siteB->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        $response = $this->actingAs($agentB, 'web')->patchJson($this->ticketUrl($ticket->id), [
            'note' => 'No debería poder comentar esto',
        ]);

        $response->assertStatus(403);
        $this->assertFalse($this->authorizes('update', $agentB, $ticket));
        $this->assertFalse($this->authorizes('comment', $agentB, $ticket));
    }

    /**
     * Decisión de producto confirmada explícitamente para este sprint: el
     * solicitante NO comenta (usa alert() como único canal de observación).
     * TenantRoleSeeder no le da tickets.comment. No es una laguna del
     * scoping por site -- es el diseño vigente, que este sprint no cambia.
     */
    public function test_solicitante_can_alert_but_never_comment_or_update_own_ticket(): void
    {
        $requester = $this->makeStaff('solicitante', []);
        $ticket = $this->makeTicket($this->siteA->id, null, $requester->id);

        $this->assertTrue($this->authorizes('alert', $requester, $ticket));
        $this->assertFalse($this->authorizes('comment', $requester, $ticket));
        $this->assertFalse($this->authorizes('update', $requester, $ticket));

        $response = $this->actingAs($requester, 'web')->patchJson($this->ticketUrl($ticket->id), [
            'note' => 'Intento de comentar como solicitante',
        ]);
        $response->assertStatus(403);
    }

    // ── RBAC v2 (Fase 6): scope_archetype, no el nombre del rol ──────────

    /**
     * Prueba real de que el bloqueante #6 de RBAC v2 quedó resuelto:
     * TicketPolicy::siteScopeType() lee scope_archetype (columna fija),
     * NO el nombre del rol -- una plantilla con nombre completamente
     * distinto a los 4 defaults ("Mesa de ayuda L2") pero con
     * scope_archetype='agente' debe comportarse EXACTAMENTE igual que un
     * rol literalmente llamado 'agente'. Antes de Fase 6 esto era
     * imposible de expresar (TicketPolicy comparaba hasRole('agente') por
     * nombre literal).
     */
    public function test_site_scoping_works_with_a_custom_named_template_via_scope_archetype(): void
    {
        setPermissionsTeamId($this->client->id);
        $customRole = \App\Models\Role::create([
            'team_id' => $this->client->id,
            'name' => 'Mesa de ayuda L2',
            'slug' => 'mesa-de-ayuda-l2',
            'guard_name' => 'web',
            'scope_archetype' => 'agente',
        ]);
        $customRole->syncPermissions(['tickets.view_area', 'tickets.comment', 'tickets.assign']);

        $agent = $this->makeUser('mesa-l2-'.uniqid().'@test.local');
        $agent->update(['client_id' => $this->client->id]);
        $agent->assignRole($customRole);
        $agent->sites()->sync([$this->siteA->id]);

        $otherAgent = $this->makeStaff('agente', [$this->siteA->id]);
        $unassigned = $this->makeTicket($this->siteA->id);
        $assignedToOther = $this->makeTicket($this->siteA->id, $otherAgent->id);
        $outsideSite = $this->makeTicket($this->siteB->id);

        // Mismo comportamiento que un 'agente' de nombre literal:
        // sin asignar o suyo dentro de su site, nunca fuera de su site ni
        // lo de otro agente.
        $ids = $this->indexAs($agent);
        $this->assertContains($unassigned->id, $ids);
        $this->assertNotContains($assignedToOther->id, $ids);
        $this->assertNotContains($outsideSite->id, $ids);
        $this->assertTrue($this->authorizes('update', $agent, $unassigned));
    }

    // ── notas internas filtradas en backend ─────────────────────────────

    public function test_internal_notes_are_hidden_from_requester(): void
    {
        [$ticket, $agentA, $requester] = $this->ticketWithInternalNote();

        $response = $this->actingAs($requester, 'web')->getJson($this->ticketUrl($ticket->id));

        $response->assertStatus(200);
        $this->assertEmpty(collect($response->json('histories'))->where('is_internal', true));
    }

    public function test_internal_notes_are_visible_to_agente(): void
    {
        [$ticket, $agentA, $requester] = $this->ticketWithInternalNote();

        $response = $this->actingAs($agentA, 'web')->getJson($this->ticketUrl($ticket->id));

        $response->assertStatus(200);
        $this->assertNotEmpty(collect($response->json('histories'))->where('is_internal', true));
    }

    /** @return array{0: Ticket, 1: User, 2: User} */
    private function ticketWithInternalNote(): array
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $requester = $this->makeStaff('solicitante', []);
        $ticket = $this->makeTicket($this->siteA->id, $agentA->id, $requester->id);

        \App\Models\TicketHistory::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $agentA->id,
            'action' => 'comment',
            'comment' => 'Nota interna, el requester no debe verla',
            'is_internal' => true,
        ]);

        return [$ticket, $agentA, $requester];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function indexAs(User $user): array
    {
        $response = $this->actingAs($user, 'web')->getJson('/api/tickets?per_page=100');
        $response->assertStatus(200);

        return collect($response->json('data'))->pluck('id')->all();
    }

    /**
     * Se llama fuera de cualquier request HTTP, así que necesita su propio
     * bypass de RLS explícito (el middleware que fija el contexto de tenant
     * solo corre dentro de una request real) -- igual razón que
     * TicketApiTest::assertDatabaseHas tras una request.
     */
    private function canView(User $user, Ticket $ticket): bool
    {
        return $this->authorizes('view', $user, $ticket);
    }

    /**
     * Chequeo de policy directo (sin HTTP) para assign()/release(): el
     * endpoint HTTP de assign tiene una validación de negocio aparte (que el
     * nuevo responsable tenga area_id === ticket->area_current_id,
     * TicketController.php:818) que es un uso de area_current_id FUERA de
     * TicketPolicy -- fuera de alcance de este cambio (reportado, no
     * tocado). Probar assign()/release() por HTTP contaminaría el test del
     * bug que se está corrigiendo aquí con ese otro bug sin relación.
     */
    /**
     * $target: destino de assign()/reassign() (Fase 5) -- ambas abilities
     * requieren un tercer argumento ($newUser) desde que se separaron.
     */
    private function authorizes(string $ability, User $user, Ticket $ticket, ?User $target = null): bool
    {
        \App\Support\Tenancy\PgsqlRowLevelSecurity::setBypass(true);

        $policy = app(\App\Policies\TicketPolicy::class);
        $ticket = $ticket->fresh();

        return $target ? $policy->{$ability}($user, $ticket, $target) : $policy->{$ability}($user, $ticket);
    }

    private function ticketUrl(int $id): string
    {
        return "/api/tickets/{$id}";
    }

    private function makeStaff(string $role, array $siteIds): User
    {
        $user = $this->makeUser($role.'-'.uniqid().'@test.local');
        $user->update(['client_id' => $this->client->id]);
        $user->assignRole($role);

        if ($siteIds) {
            $user->sites()->sync($siteIds);
        }

        return $user;
    }

    private function makeUser(string $email): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'status' => 'active', 'onboarding_completed' => true,
        ]);
    }

    private function makeArea(): int
    {
        return DB::table('areas')->insertGetId([
            'name' => 'Area'.uniqid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
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

    private function makeTicket(?int $siteId, ?int $assignedUserId = null, ?int $requesterId = null): Ticket
    {
        return Ticket::create([
            'subject' => 'Ticket scoping test',
            'folio' => 'SCP-'.uniqid(),
            'area_origin_id' => $this->catalog['area_id'],
            'area_current_id' => $this->catalog['area_id'],
            'site_id' => $siteId,
            'client_id' => $this->client->id,
            'requester_id' => $requesterId ?? $this->admin->id,
            'assigned_user_id' => $assignedUserId,
            'ticket_type_id' => $this->catalog['ticket_type_id'],
            'priority_id' => $this->catalog['priority_id'],
            'ticket_state_id' => $this->catalog['ticket_state_id'],
        ]);
    }
}
