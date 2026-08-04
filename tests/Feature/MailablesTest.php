<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundReply;
use App\Jobs\ProcessInboundTicket;
use App\Mail\ResetPasswordLink;
use App\Mail\TenantWelcomeMail;
use App\Mail\TicketCreatedConfirmationMail;
use App\Mail\TicketReplyNotificationMail;
use App\Mail\UserInvitation as UserInvitationMail;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesTenantFixtures;
use Tests\TestCase;

class MailablesTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    // ── UserInvitationMail ──────────────────────────────────────────────

    public function test_invitation_creation_sends_mail_with_tenant_name_role_and_72h_link(): void
    {
        Mail::fake();

        Permission::firstOrCreate(['name' => 'users.manage', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['slug' => 'admin']);

        $client = Client::create(['name' => 'Empresa Correo', 'is_active' => true]);
        $role = Role::firstOrCreate(['name' => 'agente', 'guard_name' => 'web'], ['slug' => 'agente']);

        $admin = User::factory()->create(['client_id' => $client->id]);
        setPermissionsTeamId($client->id);
        $admin->assignRole('admin');
        $admin->givePermissionTo('users.manage');

        $before = now();
        $response = $this->actingAs($admin, 'web')->postJson('/api/invitations', [
            'email' => 'nuevo-empleado@empresa.test',
            'role_id' => $role->id,
            'client_id' => $client->id,
        ]);

        $response->assertCreated();

        Mail::assertQueued(UserInvitationMail::class, function (UserInvitationMail $mail) use ($client, $role, $before) {
            $invitation = $mail->invitation;
            $hoursUntilExpiry = abs($invitation->expires_at->diffInHours($before));

            return $invitation->email === 'nuevo-empleado@empresa.test'
                && $invitation->client_id === $client->id
                && $invitation->role_id === $role->id
                && $hoursUntilExpiry <= 72
                && $hoursUntilExpiry >= 71;
        });
    }

    // ── PasswordResetMail (ResetPasswordLink) ───────────────────────────

    public function test_password_reset_sends_tenant_aware_link_for_portal_user(): void
    {
        Mail::fake();
        config(['tenancy.base_domain' => 'tikara.test']);

        $client = Client::create([
            'name' => 'Empresa Reset',
            'portal_slug' => 'reset-corp',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'reset-user@empresa.test',
            'client_id' => $client->id,
        ]);

        Password::sendResetLink(['email' => $user->email]);

        Mail::assertQueued(ResetPasswordLink::class, function (ResetPasswordLink $mail) {
            return str_contains($mail->url, 'reset-corp.tikara.test')
                && str_contains($mail->url, '/reset-password');
        });
    }

    public function test_password_reset_falls_back_to_main_domain_without_client(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'internal-user@empresa.test', 'client_id' => null]);

        Password::sendResetLink(['email' => $user->email]);

        Mail::assertQueued(ResetPasswordLink::class, function (ResetPasswordLink $mail) {
            return ! str_contains($mail->url, '.tikara.') && str_contains($mail->url, '/reset-password');
        });
    }

    // ── TenantWelcomeMail (sin punto de disparo aún — solo renderiza) ───

    public function test_tenant_welcome_mail_renders_client_name_and_portal_link(): void
    {
        config(['tenancy.base_domain' => 'tikara.test']);

        $client = Client::create([
            'name' => 'Empresa Bienvenida',
            'portal_slug' => 'bienvenida-corp',
            'is_active' => true,
        ]);

        $mail = new TenantWelcomeMail($client, 'contacto@empresa.test');
        $rendered = $mail->render();

        $this->assertStringContainsString('Empresa Bienvenida', $rendered);
        $this->assertStringContainsString('bienvenida-corp.tikara.test', $rendered);
    }

    // ── TicketCreatedConfirmationMail + TicketReplyNotificationMail ─────
    // Ambos deben dispararse igual sin importar el canal de origen.

    public function test_ticket_created_confirmation_sent_to_requester_from_email_flow(): void
    {
        Mail::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        DB::table('sites')->where('id', $fixture['site_id'])->update(['client_id' => $client->id, 'is_active' => true]);
        DB::table('users')->where('id', $fixture['user_id'])->update(['client_id' => $client->id]);
        $requesterEmail = User::find($fixture['user_id'])->email;

        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        // Remitente debe ser un User ya registrado del tenant (política nueva).
        ProcessInboundTicket::dispatch($client->id, [
            'from' => $requesterEmail,
            'from_name' => 'Cliente',
            'to' => 'soporte@'.$client->portal_slug.'.tikara.mx',
            'subject' => 'Problema de red',
            'body_plain' => 'Detalle',
            'message_id' => '<y@empresa.test>',
        ]);

        $ticket = Ticket::where('client_id', $client->id)->where('source', 'email')->first();
        $this->assertNotNull($ticket, 'ticket was not created — check requester resolution');

        Mail::assertQueued(TicketCreatedConfirmationMail::class, function (TicketCreatedConfirmationMail $mail) use ($ticket) {
            return $mail->ticket->id === $ticket->id;
        });
        Mail::assertQueued(TicketCreatedConfirmationMail::class, $requesterEmail);
    }

    public function test_ticket_created_confirmation_sent_to_requester_from_portal_flow(): void
    {
        Mail::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);

        $user = User::create([
            'first_name' => 'Portal', 'paternal_last_name' => 'User',
            'email' => 'portal-confirm-'.uniqid().'@test.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $fixture['area_id'], 'position_id' => DB::table('positions')->value('id'),
            'site_id' => $fixture['site_id'], 'client_id' => $client->id, 'status' => 'active',
        ]);
        Permission::firstOrCreate(['name' => 'requester.create.ticket', 'guard_name' => 'web']);
        setPermissionsTeamId($client->id);
        $user->givePermissionTo('requester.create.ticket');

        $response = $this->actingAs($user, 'web')->postJson('/api/my-tickets', [
            'subject' => 'Ticket portal confirmación',
            'area_origin_id' => $fixture['area_id'],
            'area_current_id' => $fixture['area_id'],
            'site_id' => $fixture['site_id'],
            'ticket_type_id' => $fixture['ticket_type_id'],
            'priority_id' => $fixture['priority_id'],
            'ticket_state_id' => $fixture['ticket_state_id'],
            'created_at' => now()->toIso8601String(),
        ]);
        $response->assertCreated();

        Mail::assertQueued(TicketCreatedConfirmationMail::class, $user->email);
    }

    public function test_ticket_reply_notification_sent_to_requester_when_agent_replies_via_email(): void
    {
        Mail::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $requester = User::find($fixture['user_id']);
        DB::table('sites')->where('id', $fixture['site_id'])->update(['client_id' => $client->id]);

        $ticketId = $this->insertTicket($fixture, $client->id);
        DB::table('tickets')->where('id', $ticketId)->update(['folio' => '00001', 'requester_id' => $requester->id]);

        // El actor del email de respuesta NO es el requester, pero SÍ debe
        // ser un User activo registrado del mismo tenant (política nueva) →
        // debe notificarse al solicitante, no al agente.
        $agent = User::create([
            'first_name' => 'Agente', 'paternal_last_name' => 'Externo',
            'email' => 'agente-'.uniqid().'@empresa.test', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $fixture['area_id'], 'position_id' => DB::table('positions')->value('id'),
            'site_id' => $fixture['site_id'], 'client_id' => $client->id, 'status' => 'active',
        ]);

        ProcessInboundReply::dispatch($client->id, '00001', [
            'from' => $agent->email,
            'body_plain' => 'Ya lo estamos revisando.',
        ]);

        Mail::assertQueued(TicketReplyNotificationMail::class, $requester->email);
    }

    public function test_ticket_reply_notification_sent_to_agents_when_requester_comments_via_portal(): void
    {
        Mail::fake();

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        setPermissionsTeamId($client->id);

        $agent = User::create([
            'first_name' => 'Agente', 'paternal_last_name' => 'Soporte',
            'email' => 'agente-'.uniqid().'@empresa.test', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $fixture['area_id'], 'position_id' => DB::table('positions')->value('id'),
            'site_id' => $fixture['site_id'], 'client_id' => $client->id, 'status' => 'active',
        ]);
        Permission::firstOrCreate(['name' => 'tickets.manage_all', 'guard_name' => 'web']);
        $agent->givePermissionTo('tickets.manage_all');
        // site_user explícito (Fase 5.3): sin esto, SendTicketNotification::recipients()
        // ya no lo alcanza -- antes colaba por area_id (areaUsers, sin ningún scope de
        // operador); notifiableStaff() exige site_user. La otra ruta posible
        // (globalUsers vía tickets.manage_all) tampoco lo alcanzaría aquí: este fixture
        // (CreatesTenantFixtures) no le pone operator_user_id al client, así que
        // userInTicketOperatorScope() siempre da false para cualquiera en este test --
        // nunca dependió de esa ruta, solo de la legacy que se acaba de quitar.
        DB::table('site_user')->insert(['site_id' => $fixture['site_id'], 'user_id' => $agent->id]);

        $requester = User::create([
            'first_name' => 'Cliente', 'paternal_last_name' => 'Final',
            'email' => 'requester-'.uniqid().'@empresa.test', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $fixture['area_id'], 'position_id' => DB::table('positions')->value('id'),
            'site_id' => $fixture['site_id'], 'client_id' => $client->id, 'status' => 'active',
        ]);
        Permission::firstOrCreate(['name' => 'requester.comment.ticket', 'guard_name' => 'web']);
        $requester->givePermissionTo('requester.comment.ticket');

        $ticketId = $this->insertTicket($fixture, $client->id);
        DB::table('tickets')->where('id', $ticketId)->update(['requester_id' => $requester->id]);
        $ticket = Ticket::find($ticketId);

        $response = $this->actingAs($requester, 'web')->postJson("/api/my-tickets/{$ticket->id}/comments", [
            'note' => 'Sigo esperando una respuesta.',
        ]);
        $response->assertCreated();

        Mail::assertQueued(TicketReplyNotificationMail::class, $agent->email);
    }
}
