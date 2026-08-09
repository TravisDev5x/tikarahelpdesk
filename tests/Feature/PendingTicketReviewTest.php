<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundTicket;
use App\Mail\RegistrationRequiredMail;
use App\Models\Client;
use App\Models\PendingTicketRequest;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Tickets\PendingTicketRequestNotification;
use App\Services\PendingTicketReviewService;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Cola de revisión manual para correos no reconocidos
 * (docs/PENDING_TICKET_REVIEW.md).
 */
class PendingTicketReviewTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private function makeReviewer(int $clientId): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $reviewer = User::create([
            'first_name' => 'Reviewer', 'paternal_last_name' => 'Test',
            'email' => 'reviewer-'.uniqid().'@test.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'client_id' => $clientId, 'status' => 'active', 'onboarding_completed' => true,
        ]);

        setPermissionsTeamId($clientId);
        $this->seed(TenantRoleSeeder::class);
        // assignRole('admin') por nombre es ambiguo -- Role::findByName() de
        // Spatie NO filtra por team activo (confirmado leyendo el paquete),
        // así que si existe más de un role 'admin' (el legacy global de
        // 2026_07_09_000013_seed_permissions_and_roles.php + el de este
        // tenant) puede resolver el equivocado. Mismo bug real que se
        // encontró y corrigió en producción esta sesión (CompanyController/
        // admin@testco.test) -- aquí se evita resolviendo el modelo exacto.
        $role = Role::where('name', 'admin')->where('team_id', $clientId)->firstOrFail();
        $reviewer->syncRoles([$role]);

        return $reviewer;
    }

    /** Catálogos con los IDs fijos que TicketClassifierService asume por defecto (Capa 3: category=general->3, priority=medium->3). */
    private function seedTicketCatalogs(): void
    {
        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function test_unregistered_sender_creates_pending_request_and_notifies_reviewer(): void
    {
        Mail::fake();
        Notification::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $reviewer = $this->makeReviewer($client->id);

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'nadie-conocido@fuera.test',
            'from_name' => 'Desconocido',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle del problema',
            'message_id' => '<pending-1@empresa.test>',
        ]);

        $this->assertSame(0, Ticket::where('client_id', $client->id)->count());

        $request = PendingTicketRequest::where('client_id', $client->id)->first();
        $this->assertNotNull($request);
        $this->assertSame('unregistered', $request->reason);
        $this->assertSame('pending', $request->status);
        $this->assertSame('nadie-conocido@fuera.test', $request->from_email);

        Notification::assertSentTo($reviewer, PendingTicketRequestNotification::class, function ($notification) use ($request) {
            return $notification->pendingTicketRequestId === $request->id;
        });
    }

    public function test_same_pending_sender_retrying_updates_the_row_instead_of_duplicating_and_does_not_renotify(): void
    {
        Mail::fake();
        Notification::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $this->makeReviewer($client->id);

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'reintento@fuera.test',
            'from_name' => 'Reintento',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Primer intento',
            'body_plain' => 'Cuerpo 1',
            'message_id' => '<retry-1@empresa.test>',
        ]);

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'reintento@fuera.test',
            'from_name' => 'Reintento',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Segundo intento',
            'body_plain' => 'Cuerpo 2',
            'message_id' => '<retry-2@empresa.test>',
        ]);

        $this->assertSame(1, PendingTicketRequest::where('client_id', $client->id)->where('from_email', 'reintento@fuera.test')->count());

        $request = PendingTicketRequest::where('client_id', $client->id)->first();
        $this->assertSame('Segundo intento', $request->subject);

        Notification::assertCount(1);
    }

    public function test_approve_with_existing_user_creates_ticket_and_marks_request_approved(): void
    {
        Mail::fake();
        Notification::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $reviewer = $this->makeReviewer($client->id);
        $requester = User::find($fixture['user_id']);
        $this->seedTicketCatalogs();

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'nadie-conocido-2@fuera.test',
            'from_name' => 'Desconocido',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Necesito ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<approve-1@empresa.test>',
        ]);

        $request = PendingTicketRequest::where('client_id', $client->id)->firstOrFail();

        $ticket = app(PendingTicketReviewService::class)->approveWithExistingUser($request, $reviewer, $requester->id);

        $this->assertSame($requester->id, $ticket->requester_id);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertNotEmpty($ticket->folio);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($ticket->id, $request->resulting_ticket_id);
        $this->assertSame($reviewer->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
    }

    public function test_approving_an_already_reviewed_request_throws(): void
    {
        Mail::fake();
        Notification::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $reviewer = $this->makeReviewer($client->id);
        $requester = User::find($fixture['user_id']);
        $this->seedTicketCatalogs();

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'nadie-conocido-3@fuera.test',
            'from_name' => 'Desconocido',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<approve-race@empresa.test>',
        ]);

        $request = PendingTicketRequest::where('client_id', $client->id)->firstOrFail();
        $service = app(PendingTicketReviewService::class);

        $service->approveWithExistingUser($request, $reviewer, $requester->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pending_ticket_request_already_reviewed');
        $service->approveWithExistingUser($request->fresh(), $reviewer, $requester->id);
    }

    public function test_reject_marks_request_rejected_with_note(): void
    {
        Mail::fake();
        Notification::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $reviewer = $this->makeReviewer($client->id);

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'nadie-conocido-4@fuera.test',
            'from_name' => 'Desconocido',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<reject-1@empresa.test>',
        ]);

        $request = PendingTicketRequest::where('client_id', $client->id)->firstOrFail();

        app(PendingTicketReviewService::class)->reject($request, $reviewer, 'No es legítimo');

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('No es legítimo', $request->review_note);
        $this->assertSame($reviewer->id, $request->reviewed_by);
    }

    public function test_reviewer_endpoint_approves_via_http(): void
    {
        Mail::fake();
        Notification::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $reviewer = $this->makeReviewer($client->id);
        $requester = User::find($fixture['user_id']);
        $this->seedTicketCatalogs();

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'nadie-conocido-5@fuera.test',
            'from_name' => 'Desconocido',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<http-approve@empresa.test>',
        ]);

        $request = PendingTicketRequest::where('client_id', $client->id)->firstOrFail();

        $response = $this->actingAs($reviewer, 'web')
            ->postJson("/api/pending-ticket-requests/{$request->id}/approve", ['user_id' => $requester->id]);

        $response->assertStatus(200);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_reviewer_without_permission_gets_403(): void
    {
        Mail::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $requester = User::find($fixture['user_id']);

        setPermissionsTeamId($client->id);
        $this->seed(TenantRoleSeeder::class);
        $plainUser = User::create([
            'first_name' => 'Sin', 'paternal_last_name' => 'Permiso',
            'email' => 'sinpermiso@test.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $fixture['area_id'], 'position_id' => DB::table('positions')->value('id'),
            'site_id' => $fixture['site_id'], 'client_id' => $client->id, 'status' => 'active',
        ]);
        $solicitanteRole = Role::where('name', 'solicitante')->where('team_id', $client->id)->firstOrFail();
        $plainUser->syncRoles([$solicitanteRole]);

        ProcessInboundTicket::dispatchSync($client->id, [
            'from' => 'nadie-conocido-6@fuera.test',
            'from_name' => 'Desconocido',
            'to' => 'soporte@'.$client->id.'.tikara.test',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<http-403@empresa.test>',
        ]);

        $request = PendingTicketRequest::where('client_id', $client->id)->firstOrFail();

        $response = $this->actingAs($plainUser, 'web')
            ->postJson("/api/pending-ticket-requests/{$request->id}/approve", ['user_id' => $requester->id]);

        $response->assertStatus(403);
    }
}
