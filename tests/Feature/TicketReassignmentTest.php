<?php

namespace Tests\Feature;

use App\Mail\TicketReassignedMail;
use App\Models\Client;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Tickets\TicketReassignedNotification;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 5, Pasos 1-3 del sprint maestro: TicketPolicy::assign() se separó en
 * assign() (tomar/asignar un ticket SIN responsable) y reassign() (mover
 * uno YA asignado a otro usuario). Antes de esto, dos obstáculos vivían en
 * TicketController y bloqueaban en la práctica lo que TicketPolicy ya
 * autorizaba: "solo el responsable actual puede reasignar" (Obstáculo A) y
 * newUser->area_id === ticket->area_current_id (Obstáculo B, legacy).
 * Ambos se quitaron del controlador -- la autorización real vive solo en
 * TicketPolicy (incluida la validación del DESTINO vía
 * userLinkedToTicketSite(), que reemplaza al Obstáculo B).
 */
class TicketReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Site $siteA;

    private Site $siteB;

    private array $catalog;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->makeUser('admin@test.local');
        $admin->update(['is_operator' => true]);

        $this->client = Client::create(['name' => 'Tenant Reassign', 'operator_user_id' => $admin->id, 'is_active' => true]);

        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->siteA = Site::create(['client_id' => $this->client->id, 'name' => 'Site A', 'type' => 'physical', 'is_active' => true]);
        $this->siteB = Site::create(['client_id' => $this->client->id, 'name' => 'Site B', 'type' => 'physical', 'is_active' => true]);
        $this->catalog = $this->makeCatalog();
        $this->admin = $admin;
    }

    /**
     * Antes del fix: bloqueado por el Obstáculo A ("solo el responsable
     * actual puede reasignar") aunque TicketPolicy ya lo autorizaba.
     */
    public function test_supervisor_can_reassign_a_ticket_from_one_agent_to_another_of_the_same_site(): void
    {
        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $agentA2 = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id, $agentA->id);

        $response = $this->actingAs($supervisor, 'web')->postJson("/api/tickets/{$ticket->id}/assign", [
            'assigned_user_id' => $agentA2->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame($agentA2->id, $this->freshTicket($ticket)->assigned_user_id);
    }

    /** agente no tiene tickets.reassign -- solo puede assign() tickets sin asignar. */
    public function test_agente_cannot_reassign_a_ticket_already_assigned_to_someone_else(): void
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $agentA2 = $this->makeStaff('agente', [$this->siteA->id]);
        $otherAgent = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id, $agentA->id);

        $response = $this->actingAs($agentA2, 'web')->postJson("/api/tickets/{$ticket->id}/assign", [
            'assigned_user_id' => $otherAgent->id,
        ]);

        $response->assertStatus(403);
        $this->assertSame($agentA->id, $this->freshTicket($ticket)->assigned_user_id);
    }

    /** assign() sigue cubriendo el caso normal de tomar un ticket sin dueño. */
    public function test_agente_can_still_take_an_unassigned_ticket_of_their_site(): void
    {
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        $response = $this->actingAs($agentA, 'web')->postJson("/api/tickets/{$ticket->id}/take");

        $response->assertStatus(200);
        $this->assertSame($agentA->id, $this->freshTicket($ticket)->assigned_user_id);
    }

    /**
     * Reemplaza la validación vieja de area_id (Obstáculo B): el destino de
     * un reassign debe tener site_user en el site del ticket. 403 de
     * autorización (TicketPolicy::reassign()), no 422 de validación de
     * request -- decisión confirmada explícitamente para este sprint.
     */
    public function test_reassign_to_an_agent_without_site_user_on_the_ticket_site_is_denied(): void
    {
        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $outsider = $this->makeStaff('agente', [$this->siteB->id]);
        $ticket = $this->makeTicket($this->siteA->id, $agentA->id);

        $response = $this->actingAs($supervisor, 'web')->postJson("/api/tickets/{$ticket->id}/assign", [
            'assigned_user_id' => $outsider->id,
        ]);

        $response->assertStatus(403);
        $this->assertSame($agentA->id, $this->freshTicket($ticket)->assigned_user_id);
    }

    /** Mismo chequeo de destino, pero para el caso "tomar/asignar" (ticket sin dueño), no solo reassign. */
    public function test_assign_unassigned_ticket_to_an_agent_without_site_user_on_the_ticket_site_is_denied(): void
    {
        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $outsider = $this->makeStaff('agente', [$this->siteB->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        $response = $this->actingAs($supervisor, 'web')->postJson("/api/tickets/{$ticket->id}/assign", [
            'assigned_user_id' => $outsider->id,
        ]);

        $response->assertStatus(403);
        $this->assertNull($this->freshTicket($ticket)->assigned_user_id);
    }

    /**
     * Primer test de Notification::fake()/assertSentTo() del proyecto
     * (cero cobertura de canal in-app hasta este sprint, ver auditoría
     * Paso 0.1). Nuevo responsable: in-app + correo. Responsable anterior:
     * solo in-app -- decisión de Fase 5, no se le manda correo.
     */
    public function test_reassignment_notifies_new_agent_by_mail_and_in_app_and_previous_agent_only_in_app(): void
    {
        Notification::fake();
        Mail::fake();

        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $agentA2 = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id, $agentA->id);

        $response = $this->actingAs($supervisor, 'web')->postJson("/api/tickets/{$ticket->id}/assign", [
            'assigned_user_id' => $agentA2->id,
        ]);
        $response->assertStatus(200);

        Notification::assertSentTo($agentA2, TicketReassignedNotification::class);
        Notification::assertSentTo($agentA, TicketReassignedNotification::class);

        Mail::assertQueued(TicketReassignedMail::class, function (TicketReassignedMail $mail) use ($ticket, $agentA2) {
            return $mail->ticket->id === $ticket->id && $mail->hasTo($agentA2->email);
        });
        Mail::assertNotQueued(TicketReassignedMail::class, function (TicketReassignedMail $mail) use ($agentA) {
            return $mail->hasTo($agentA->email);
        });
    }

    // ── Helpers (mismo patrón que TicketSiteScopingTest) ─────────────────

    /**
     * Se llama fuera de la request HTTP que hizo la reasignación, así que
     * necesita su propio bypass de RLS explícito -- el middleware que fija
     * el contexto de tenant solo corre dentro de una request real (mismo
     * patrón que TicketSiteScopingTest::canView()).
     */
    private function freshTicket(Ticket $ticket): Ticket
    {
        \App\Support\Tenancy\PgsqlRowLevelSecurity::setBypass(true);

        return $ticket->fresh();
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
            'subject' => 'Ticket reassignment test',
            'folio' => 'RSG-'.uniqid(),
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
