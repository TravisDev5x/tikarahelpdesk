<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Auditoría de bugs críticos (2026-08-22): TicketAttachmentController,
 * MyTicketsController::storeAttachment e IncidentAttachmentController
 * guardaban en disco 'public' (symlinkeado, servido directo por el
 * webserver, fuera de cualquier middleware) -- cualquiera con la URL
 * descargaba el adjunto sin sesión ni pertenecer al tenant. Mismo fix ya
 * aplicado a InvAssetImageController en la fase Crítico de la auditoría de
 * Inventario: disco 'local' + acción autenticada.
 */
class AttachmentDiskSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $operator = $this->makeUser('operator@test.local');
        $operator->update(['is_operator' => true]);
        $this->client = Client::create(['name' => 'Tenant Attachments', 'operator_user_id' => $operator->id, 'is_active' => true]);

        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->admin = $this->makeUser('admin@test.local');
        $this->admin->update(['client_id' => $this->client->id]);
        $this->admin->assignRole('admin');
    }

    public function test_ticket_attachment_upload_uses_the_local_disk_not_public(): void
    {
        $ticket = $this->makeTicket();
        $file = UploadedFile::fake()->create('evidencia.pdf', 50, 'application/pdf');

        $response = $this->actingAs($this->admin, 'web')->postJson("/api/tickets/{$ticket->id}/attachments", [
            'attachments' => [$file],
        ]);

        $response->assertCreated();
        $path = $response->json('0.file_path');
        $this->assertSame('local', $response->json('0.disk'));
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_ticket_attachment_download_requires_authentication(): void
    {
        $ticket = $this->makeTicket();
        $file = UploadedFile::fake()->create('evidencia.pdf', 50, 'application/pdf');
        $attachmentId = $this->actingAs($this->admin, 'web')
            ->postJson("/api/tickets/{$ticket->id}/attachments", ['attachments' => [$file]])
            ->json('0.id');

        // actingAs() deja al guard "logueado" para el resto del test -- hay
        // que resetearlo para simular una request de verdad sin sesión.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/tickets/{$ticket->id}/attachments/{$attachmentId}/download")
            ->assertUnauthorized();

        $this->actingAs($this->admin, 'web')
            ->get("/api/tickets/{$ticket->id}/attachments/{$attachmentId}/download")
            ->assertOk();
    }

    public function test_incident_attachment_upload_uses_the_local_disk_and_has_an_authenticated_download(): void
    {
        $catalog = $this->makeIncidentCatalog();
        $area = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $site = DB::table('sites')->insertGetId([
            'client_id' => $this->client->id, 'name' => 'S'.uniqid(), 'code' => 'X'.uniqid(),
            'type' => 'physical', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $incident = Incident::create([
            'subject' => 'Incidente test', 'enabled_at' => now(),
            'reporter_id' => $this->admin->id, 'area_id' => $area,
            'client_id' => $this->client->id, 'site_id' => $site,
            'incident_type_id' => $catalog['type_id'], 'incident_severity_id' => $catalog['severity_id'],
            'incident_status_id' => $catalog['status_id'],
        ]);

        $file = UploadedFile::fake()->create('evidencia.pdf', 50, 'application/pdf');
        $response = $this->actingAs($this->admin, 'web')->postJson("/api/incidents/{$incident->id}/attachments", [
            'attachments' => [$file],
        ]);

        $response->assertCreated();
        $path = $response->json('0.file_path');
        $this->assertSame('local', $response->json('0.disk'));
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        $attachmentId = $response->json('0.id');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/incidents/{$incident->id}/attachments/{$attachmentId}/download")->assertUnauthorized();
        $this->actingAs($this->admin, 'web')
            ->get("/api/incidents/{$incident->id}/attachments/{$attachmentId}/download")
            ->assertOk();
    }

    private function makeTicket(): Ticket
    {
        $site = DB::table('sites')->insertGetId([
            'client_id' => $this->client->id, 'name' => 'S'.uniqid(), 'code' => 'X'.uniqid(),
            'type' => 'physical', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalog = $this->makeTicketCatalog();

        return Ticket::create([
            'subject' => 'Adjunto test', 'folio' => 'TK-'.uniqid(),
            'area_origin_id' => $catalog['area_id'], 'area_current_id' => $catalog['area_id'],
            'site_id' => $site, 'client_id' => $this->client->id,
            'requester_id' => $this->admin->id, 'ticket_type_id' => $catalog['ticket_type_id'],
            'priority_id' => $catalog['priority_id'], 'ticket_state_id' => $catalog['ticket_state_id'],
        ]);
    }

    private function makeTicketCatalog(): array
    {
        $now = now();

        return [
            'area_id' => DB::table('areas')->insertGetId(['name' => 'Area'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'priority_id' => DB::table('priorities')->insertGetId(['name' => 'Prio'.uniqid(), 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_state_id' => DB::table('ticket_states')->insertGetId(['name' => 'Estado'.uniqid(), 'code' => 'st'.uniqid(), 'is_active' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_type_id' => DB::table('ticket_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
        ];
    }

    private function makeIncidentCatalog(): array
    {
        $now = now();

        return [
            'type_id' => DB::table('incident_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'severity_id' => DB::table('incident_severities')->insertGetId(['name' => 'Sev'.uniqid(), 'code' => 'sv'.uniqid(), 'level' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'status_id' => DB::table('incident_statuses')->insertGetId(['name' => 'Estado'.uniqid(), 'code' => 'st'.uniqid(), 'is_active' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now]),
        ];
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
}
