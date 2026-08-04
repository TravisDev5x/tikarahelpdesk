<?php

namespace Tests\Feature;

use App\Events\TicketCreated;
use App\Events\TicketUpdated;
use App\Models\Client;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Tickets\TicketAssignedNotification;
use App\Notifications\TicketActivityNotification;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 5, Sub-fase 5.3 del sprint maestro: SendTicketNotification::recipients()
 * resolvía destinatarios de TicketCreated/TicketUpdated por area_current_id
 * (legacy), no por site_user -- un agente/supervisor vinculado SOLO por
 * site_user (sin area_id coincidente, el caso normal bajo Fase 4) nunca
 * recibía notificación de un ticket que sí podía ver y atender según
 * TicketPolicy. Corregido reusando TicketPolicy::notifiableStaff(), mismo
 * criterio que withinStaffSiteScope() ya aplica en el resto de la policy.
 *
 * También cierra el hueco de cobertura reportado en 5.2: primer test con
 * Notification::fake() para TicketActivityNotification (creación/
 * actualización) y TicketAssignedNotification (take()).
 */
class TicketNotificationRecipientsTest extends TestCase
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

        $this->client = Client::create(['name' => 'Tenant Notif', 'operator_user_id' => $admin->id, 'is_active' => true]);

        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->siteA = Site::create(['client_id' => $this->client->id, 'name' => 'Site A', 'type' => 'physical', 'is_active' => true]);
        $this->siteB = Site::create(['client_id' => $this->client->id, 'name' => 'Site B', 'type' => 'physical', 'is_active' => true]);
        $this->catalog = $this->makeCatalog();
        $this->admin = $admin;
    }

    /** El caso que hoy está roto: agente sin area_id coincidente, vinculado solo por site_user. */
    public function test_agent_linked_only_by_site_user_receives_ticket_created_notification(): void
    {
        Notification::fake();
        Mail::fake();

        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        event(new TicketCreated($ticket));

        Notification::assertSentTo($agentA, TicketActivityNotification::class);
    }

    public function test_agent_linked_only_by_site_user_receives_ticket_updated_notification(): void
    {
        Notification::fake();
        Mail::fake();

        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        event(new TicketUpdated($ticket));

        Notification::assertSentTo($agentA, TicketActivityNotification::class);
    }

    public function test_agent_of_another_site_does_not_receive_notification(): void
    {
        Notification::fake();
        Mail::fake();

        $agentB = $this->makeStaff('agente', [$this->siteB->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        event(new TicketCreated($ticket));

        Notification::assertNotSentTo($agentB, TicketActivityNotification::class);
    }

    /** Agente vinculado a su site pero el ticket está asignado a OTRO agente: fuera de su alcance, no se le notifica. */
    public function test_agent_does_not_receive_notification_for_a_ticket_assigned_to_someone_else(): void
    {
        Notification::fake();
        Mail::fake();

        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $otherAgent = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id, $otherAgent->id);

        event(new TicketUpdated($ticket));

        Notification::assertNotSentTo($agentA, TicketActivityNotification::class);
        Notification::assertSentTo($otherAgent, TicketActivityNotification::class);
    }

    /** Supervisor: cualquier ticket de sus sites, aunque esté asignado a otro. */
    public function test_supervisor_receives_notification_for_any_ticket_of_their_site(): void
    {
        Notification::fake();
        Mail::fake();

        $supervisor = $this->makeStaff('supervisor', [$this->siteA->id]);
        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id, $agentA->id);

        event(new TicketUpdated($ticket));

        Notification::assertSentTo($supervisor, TicketActivityNotification::class);
    }

    /**
     * RBAC v2 (Fase 6): ProcessInboundTicket no pasa por HTTP (no hay
     * ApplyPgsqlTenantRls) -- sin su propio setPermissionsTeamId() dentro
     * del Job, TicketCreated (disparado al final de handle()) llegaría a
     * notifiableStaff() con team_id sin resolver, y ningún agente/
     * supervisor recibiría notificación del flujo de correo entrante. No
     * usa Event::fake() a propósito (a diferencia de TicketNotificationEventsTest,
     * que solo prueba que el evento se dispara) -- este test deja correr
     * el listener real para probar la resolución de destinatarios de
     * punta a punta a través del Job.
     */
    public function test_process_inbound_ticket_job_notifies_site_linked_staff(): void
    {
        Notification::fake();
        Mail::fake();

        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $requester = $this->makeStaff('solicitante', []);
        $requester->update(['site_id' => $this->siteA->id]);

        // Resetea el team_id que dejó setUp() (sembró roles ahí) -- sin
        // esto el test no probaría nada real: pasaría igual aunque
        // ProcessInboundTicket nunca fijara su propio contexto, porque el
        // de setUp() ya coincidiría por casualidad con el del tenant del
        // Job. Forzar null aquí prueba que el Job resuelve su team_id por
        // su cuenta, no que hereda uno que ya estaba puesto.
        setPermissionsTeamId(null);

        // TicketClassifierService::classify() cae al default (sin regla,
        // sin IA en tests) y usa IDs fijos de catálogo (ticket_type_id=3,
        // priority_id=3) -- no los del fixture de esta clase.
        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        \App\Jobs\ProcessInboundTicket::dispatchSync($this->client->id, [
            'from' => $requester->email,
            'from_name' => 'Cliente',
            'to' => 'soporte@'.($this->client->portal_slug ?? 'tenant').'.tikara.mx',
            'subject' => 'Ayuda con mi equipo',
            'body_plain' => 'Detalle del problema',
            'message_id' => '<inbound-'.uniqid().'@empresa.test>',
        ]);

        $ticket = Ticket::where('client_id', $this->client->id)->where('source', 'email')->first();
        $this->assertNotNull($ticket, 'ProcessInboundTicket no creó el ticket -- revisar fixture antes que el aserto de notificación.');
        $this->assertSame($this->siteA->id, $ticket->site_id);

        Notification::assertSentTo($agentA, TicketActivityNotification::class);
    }

    /** Cierra el hueco de cobertura reportado en 5.2: take() envía TicketAssignedNotification in-app. */
    public function test_taking_an_unassigned_ticket_sends_ticket_assigned_notification(): void
    {
        Notification::fake();

        $agentA = $this->makeStaff('agente', [$this->siteA->id]);
        $ticket = $this->makeTicket($this->siteA->id);

        $response = $this->actingAs($agentA, 'web')->postJson("/api/tickets/{$ticket->id}/take");

        $response->assertStatus(200);
        Notification::assertSentTo($agentA, TicketAssignedNotification::class);
    }

    // ── Helpers (mismo patrón que TicketSiteScopingTest) ─────────────────

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
            'subject' => 'Ticket notification test',
            'folio' => 'NTF-'.uniqid(),
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
