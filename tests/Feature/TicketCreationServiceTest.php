<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundTicket;
use App\Models\Client;
use App\Services\TicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Confirma que el flujo de email (ProcessInboundTicket) y el de portal
 * (MyTicketsController::store, cubierto en TicketApiTest) pasan ambos por
 * TicketCreationService — no vuelven a divergir en la asignación de folio.
 */
class TicketCreationServiceTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    public function test_service_assigns_sequential_folio_per_tenant(): void
    {
        $fixture = $this->createTenantFixtureSet();

        $service = app(TicketCreationService::class);

        $ticket1 = $service->create([
            'subject' => 'Uno',
            'area_origin_id' => $fixture['area_id'],
            'area_current_id' => $fixture['area_id'],
            'site_id' => $fixture['site_id'],
            'client_id' => $fixture['client_id'],
            'requester_id' => $fixture['user_id'],
            'ticket_type_id' => $fixture['ticket_type_id'],
            'priority_id' => $fixture['priority_id'],
            'ticket_state_id' => $fixture['ticket_state_id'],
        ]);

        $ticket2 = $service->create([
            'subject' => 'Dos',
            'area_origin_id' => $fixture['area_id'],
            'area_current_id' => $fixture['area_id'],
            'site_id' => $fixture['site_id'],
            'client_id' => $fixture['client_id'],
            'requester_id' => $fixture['user_id'],
            'ticket_type_id' => $fixture['ticket_type_id'],
            'priority_id' => $fixture['priority_id'],
            'ticket_state_id' => $fixture['ticket_state_id'],
        ]);

        $client = Client::find($fixture['client_id']);
        $prefix = preg_quote($client->fresh()->ticket_prefix, '/');

        $this->assertMatchesRegularExpression("/^TK-A00001-{$prefix}-\d{5}$/", $ticket1->folio);
        $this->assertMatchesRegularExpression("/^TK-A00002-{$prefix}-\d{5}$/", $ticket2->folio);
    }

    public function test_service_requires_client_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(TicketCreationService::class)->create(['subject' => 'Sin cliente']);
    }

    public function test_email_flow_produces_ticket_with_folio_via_shared_service(): void
    {
        $fixture = $this->createTenantFixtureSet();
        $tenant = Client::find($fixture['client_id']);

        DB::table('sites')->where('id', $fixture['site_id'])->update([
            'client_id' => $tenant->id,
            'is_active' => true,
        ]);
        DB::table('users')->where('id', $fixture['user_id'])->update(['client_id' => $tenant->id]);
        $requesterEmail = \App\Models\User::find($fixture['user_id'])->email;

        // TicketClassifierService cae al default (category=general, priority=medium)
        // sin reglas ni IA configurada, que mapea a ticket_type_id=3, priority_id=3.
        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        // La política nueva exige que el remitente sea un User ya registrado
        // del tenant — usamos el usuario del fixture, no un email arbitrario.
        ProcessInboundTicket::dispatch($tenant->id, [
            'from' => $requesterEmail,
            'from_name' => 'Cliente Prueba',
            'to' => 'soporte@'.$tenant->portal_slug.'.tikara.mx',
            'subject' => 'Mi impresora no funciona',
            'body_plain' => 'Detalle del problema.',
            'message_id' => '<abc@empresa.test>',
        ]);

        $ticket = \App\Models\Ticket::where('client_id', $tenant->id)
            ->where('source', 'email')
            ->first();

        $this->assertNotNull($ticket);
        $this->assertNotNull($ticket->folio);
        $this->assertMatchesRegularExpression('/^TK-[A-Z]\d{5}-[A-Z0-9]{1,10}-\d{5}$/', $ticket->folio);
    }

    /**
     * Mailgun reintenta el webhook si la respuesta tarda o no es 2xx -- el
     * mismo correo (mismo Message-Id) puede llegar dos veces. No debe
     * producir dos tickets.
     */
    public function test_duplicate_webhook_delivery_does_not_create_a_second_ticket(): void
    {
        $fixture = $this->createTenantFixtureSet();
        $tenant = Client::find($fixture['client_id']);

        DB::table('sites')->where('id', $fixture['site_id'])->update([
            'client_id' => $tenant->id,
            'is_active' => true,
        ]);
        DB::table('users')->where('id', $fixture['user_id'])->update(['client_id' => $tenant->id]);
        $requesterEmail = \App\Models\User::find($fixture['user_id'])->email;

        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $payload = [
            'from' => $requesterEmail,
            'from_name' => 'Cliente Prueba',
            'to' => 'soporte@'.$tenant->portal_slug.'.tikara.mx',
            'subject' => 'Mi impresora no funciona',
            'body_plain' => 'Detalle del problema.',
            'message_id' => '<duplicado-webhook@empresa.test>',
        ];

        // Misma entrega procesada dos veces (retry de Mailgun).
        ProcessInboundTicket::dispatch($tenant->id, $payload);
        ProcessInboundTicket::dispatch($tenant->id, $payload);

        $count = \App\Models\Ticket::where('client_id', $tenant->id)
            ->where('origin_message_id', '<duplicado-webhook@empresa.test>')
            ->count();

        $this->assertSame(1, $count, 'El reintento del webhook no debe crear un segundo ticket.');
    }

    /** El unique constraint es el backstop real, independiente del chequeo previo del job. */
    public function test_origin_message_id_is_unique_constraint_at_db_level(): void
    {
        $fixture = $this->createTenantFixtureSet();
        $service = app(TicketCreationService::class);

        $service->create([
            'subject' => 'Uno',
            'origin_message_id' => '<mismo@empresa.test>',
            'area_origin_id' => $fixture['area_id'],
            'area_current_id' => $fixture['area_id'],
            'site_id' => $fixture['site_id'],
            'client_id' => $fixture['client_id'],
            'requester_id' => $fixture['user_id'],
            'ticket_type_id' => $fixture['ticket_type_id'],
            'priority_id' => $fixture['priority_id'],
            'ticket_state_id' => $fixture['ticket_state_id'],
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $service->create([
            'subject' => 'Dos (mismo Message-Id, sin pasar por el chequeo previo del job)',
            'origin_message_id' => '<mismo@empresa.test>',
            'area_origin_id' => $fixture['area_id'],
            'area_current_id' => $fixture['area_id'],
            'site_id' => $fixture['site_id'],
            'client_id' => $fixture['client_id'],
            'requester_id' => $fixture['user_id'],
            'ticket_type_id' => $fixture['ticket_type_id'],
            'priority_id' => $fixture['priority_id'],
            'ticket_state_id' => $fixture['ticket_state_id'],
        ]);
    }
}
