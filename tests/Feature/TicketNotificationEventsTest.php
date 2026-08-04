<?php

namespace Tests\Feature;

use App\Events\TicketCreated;
use App\Events\TicketUpdated;
use App\Jobs\ProcessInboundReply;
use App\Jobs\ProcessInboundTicket;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Antes de este sprint: solo el flujo de portal disparaba TicketCreated
 * (in-app), y ningún flujo (ni email ni portal) disparaba TicketUpdated en
 * respuestas/comentarios. Confirma que los 4 caminos ahora disparan el evento
 * correspondiente, sin importar el canal de origen.
 */
class TicketNotificationEventsTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    public function test_email_flow_dispatches_ticket_created(): void
    {
        Event::fake([TicketCreated::class]);

        $fixture = $this->createTenantFixtureSet();
        $tenant = Client::find($fixture['client_id']);
        DB::table('sites')->where('id', $fixture['site_id'])->update(['client_id' => $tenant->id, 'is_active' => true]);
        DB::table('users')->where('id', $fixture['user_id'])->update(['client_id' => $tenant->id]);
        $requesterEmail = \App\Models\User::find($fixture['user_id'])->email;

        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        // Remitente debe ser un User ya registrado del tenant (política nueva).
        ProcessInboundTicket::dispatch($tenant->id, [
            'from' => $requesterEmail,
            'from_name' => 'Cliente',
            'to' => 'soporte@'.$tenant->portal_slug.'.tikara.mx',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<x@empresa.test>',
        ]);

        Event::assertDispatched(TicketCreated::class);
    }

    public function test_portal_flow_dispatches_ticket_created(): void
    {
        Event::fake([TicketCreated::class]);

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);

        $user = \App\Models\User::create([
            'first_name' => 'Portal', 'paternal_last_name' => 'User',
            'email' => 'portal-'.uniqid().'@test.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $fixture['area_id'], 'position_id' => DB::table('positions')->value('id'),
            'site_id' => $fixture['site_id'], 'client_id' => $client->id, 'status' => 'active',
        ]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'requester.create.ticket', 'guard_name' => 'web']);
        setPermissionsTeamId($client->id);
        $user->givePermissionTo('requester.create.ticket');

        $response = $this->actingAs($user, 'web')->postJson('/api/my-tickets', [
            'subject' => 'Ticket portal',
            'area_origin_id' => $fixture['area_id'],
            'area_current_id' => $fixture['area_id'],
            'site_id' => $fixture['site_id'],
            'ticket_type_id' => $fixture['ticket_type_id'],
            'priority_id' => $fixture['priority_id'],
            'ticket_state_id' => $fixture['ticket_state_id'],
            'created_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(201);
        Event::assertDispatched(TicketCreated::class);
    }

    public function test_email_reply_dispatches_ticket_updated(): void
    {
        Event::fake([TicketUpdated::class]);

        $fixture = $this->createTenantFixtureSet();
        DB::table('sites')->where('id', $fixture['site_id'])->update(['client_id' => $fixture['client_id']]);
        $requesterEmail = \App\Models\User::find($fixture['user_id'])->email;

        $ticketId = $this->insertTicket($fixture, $fixture['client_id']);
        DB::table('tickets')->where('id', $ticketId)->update(['folio' => '00001']);

        // Remitente debe ser un User ya registrado del tenant (política nueva).
        ProcessInboundReply::dispatch($fixture['client_id'], '00001', [
            'from' => $requesterEmail,
            'body_plain' => 'Sigue sin funcionar.',
        ]);

        Event::assertDispatched(TicketUpdated::class);
    }

    public function test_portal_comment_dispatches_ticket_updated(): void
    {
        Event::fake([TicketUpdated::class]);

        $fixture = $this->createTenantFixtureSet();
        $client = Client::find($fixture['client_id']);
        $ticketId = $this->insertTicket($fixture, $client->id);
        $ticket = Ticket::find($ticketId);

        $user = \App\Models\User::create([
            'first_name' => 'Portal', 'paternal_last_name' => 'User',
            'email' => 'portal2-'.uniqid().'@test.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $fixture['area_id'], 'position_id' => DB::table('positions')->value('id'),
            'site_id' => $fixture['site_id'], 'client_id' => $client->id, 'status' => 'active',
        ]);
        $ticket->update(['requester_id' => $user->id]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'requester.comment.ticket', 'guard_name' => 'web']);
        setPermissionsTeamId($client->id);
        $user->givePermissionTo('requester.comment.ticket');

        $this->actingAs($user, 'web')->postJson("/api/my-tickets/{$ticket->id}/comments", [
            'note' => 'Sigo esperando respuesta.',
        ]);

        Event::assertDispatched(TicketUpdated::class);
    }
}
