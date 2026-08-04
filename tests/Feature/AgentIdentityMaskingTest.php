<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sub-fase 5.2 del sprint maestro: clients.show_agent_names (default true,
 * ver migración) controla si el solicitante ve el nombre/email real del
 * agente, o una etiqueta genérica ("Agente de soporte"), en todo lo que
 * expone MyTicketsController -- exclusivo de ese controlador, el lado
 * staff (TicketController) nunca se enmascara.
 */
class AgentIdentityMaskingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Site $site;

    private array $catalog;

    private User $requester;

    private User $agent;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->makeUser('admin@test.local');
        $admin->update(['is_operator' => true]);

        $this->client = Client::create([
            'name' => 'Tenant Masking',
            'operator_user_id' => $admin->id,
            'is_active' => true,
            'portal_slug' => 'tenant-masking-'.uniqid(),
        ]);

        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->site = Site::create(['client_id' => $this->client->id, 'name' => 'Site A', 'type' => 'physical', 'is_active' => true]);
        $this->catalog = $this->makeCatalog();

        $this->requester = $this->makeStaff('solicitante', []);
        $this->agent = $this->makeStaff('agente', [$this->site->id]);

        $this->ticket = Ticket::create([
            'subject' => 'Ticket masking test',
            'folio' => 'MSK-'.uniqid(),
            'area_origin_id' => $this->catalog['area_id'],
            'area_current_id' => $this->catalog['area_id'],
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'requester_id' => $this->requester->id,
            'assigned_user_id' => $this->agent->id,
            'ticket_type_id' => $this->catalog['ticket_type_id'],
            'priority_id' => $this->catalog['priority_id'],
            'ticket_state_id' => $this->catalog['ticket_state_id'],
        ]);

        TicketHistory::create([
            'ticket_id' => $this->ticket->id,
            'actor_id' => $this->agent->id,
            'action' => 'comment',
            'comment' => 'Respuesta del agente',
            'is_internal' => false,
        ]);
    }

    public function test_default_show_agent_names_true_exposes_real_name_and_email(): void
    {
        $this->assertTrue($this->client->fresh()->show_agent_names);

        $response = $this->actingAs($this->requester, 'web')->getJson("/api/my-tickets/{$this->ticket->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('assigned_user.name', $this->agent->name);
        $history = collect($response->json('histories'))->firstWhere('actor_id', $this->agent->id);
        $this->assertSame($this->agent->name, $history['actor']['name']);
        $this->assertSame($this->agent->email, $history['actor']['email']);
    }

    public function test_show_agent_names_false_masks_name_and_email_in_show(): void
    {
        $this->client->update(['show_agent_names' => false]);

        $response = $this->actingAs($this->requester, 'web')->getJson("/api/my-tickets/{$this->ticket->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('assigned_user.name', 'Agente de soporte');

        $history = collect($response->json('histories'))->firstWhere('actor_id', $this->agent->id);
        $this->assertSame('Agente de soporte', $history['actor']['name']);
        $this->assertNull($history['actor']['email']);
    }

    /** El propio comentario del solicitante nunca se enmascara, aunque el flag esté en false. */
    public function test_show_agent_names_false_does_not_mask_requesters_own_history_entries(): void
    {
        $this->client->update(['show_agent_names' => false]);

        TicketHistory::create([
            'ticket_id' => $this->ticket->id,
            'actor_id' => $this->requester->id,
            'action' => 'comment',
            'comment' => 'Respuesta del solicitante',
            'is_internal' => false,
        ]);

        $response = $this->actingAs($this->requester, 'web')->getJson("/api/my-tickets/{$this->ticket->id}");

        $response->assertStatus(200);
        $ownHistory = collect($response->json('histories'))->firstWhere('actor_id', $this->requester->id);
        $this->assertSame($this->requester->name, $ownHistory['actor']['name']);
    }

    public function test_show_agent_names_false_masks_past_agent_comments_in_add_comment_response(): void
    {
        $this->client->update(['show_agent_names' => false]);

        $response = $this->actingAs($this->requester, 'web')->postJson("/api/my-tickets/{$this->ticket->id}/comments", [
            'note' => 'Gracias por la respuesta',
        ]);

        $response->assertStatus(201);

        $agentHistory = collect($response->json('ticket.histories'))->firstWhere('actor_id', $this->agent->id);
        $this->assertSame('Agente de soporte', $agentHistory['actor']['name']);

        // El comentario recién creado es del propio solicitante -- nunca se enmascara.
        $this->assertSame($this->requester->name, $response->json('history.actor.name'));
    }

    /**
     * show_agent_names es exclusivo de "Mis Tickets" -- el lado staff
     * (TicketController, lo que ve un agente/admin) nunca se enmascara,
     * sin importar el valor del flag.
     */
    public function test_masking_does_not_affect_staff_side_ticket_view(): void
    {
        $this->client->update(['show_agent_names' => false]);

        $response = $this->actingAs($this->agent, 'web')->getJson("/api/tickets/{$this->ticket->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('assigned_user.name', $this->agent->name);
    }

    public function test_set_agent_visibility_command_toggles_the_flag(): void
    {
        $this->assertTrue($this->client->fresh()->show_agent_names);

        Artisan::call('tenants:set-agent-visibility', [
            'portal_slug' => $this->client->portal_slug,
            '--hide' => true,
        ]);
        $this->assertFalse($this->client->fresh()->show_agent_names);

        Artisan::call('tenants:set-agent-visibility', [
            'portal_slug' => $this->client->portal_slug,
            '--show' => true,
        ]);
        $this->assertTrue($this->client->fresh()->show_agent_names);
    }

    public function test_set_agent_visibility_command_requires_exactly_one_flag(): void
    {
        $exitCode = Artisan::call('tenants:set-agent-visibility', [
            'portal_slug' => $this->client->portal_slug,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($this->client->fresh()->show_agent_names);
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
}
