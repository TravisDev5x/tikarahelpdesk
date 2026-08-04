<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El módulo "Mis Tickets" (portal del solicitante, RequesterTicketPolicy)
 * no tenía ningún test de IDOR por HTTP: ni TicketApiTest ni
 * RequesterValidationTest golpean /api/my-tickets/{ticket} con un ticket
 * ajeno por ID directo. TenantApiIsolationTest cubre el portal por
 * subdominio para el módulo operativo (tickets/incidents), pero no este
 * módulo, que es el que expone el solicitante final.
 *
 * Previene: un solicitante autenticado que adivina o enumera IDs de ticket
 * consecutivos (los folios son secuenciales por tenant, ver
 * TicketFolioFormatTest) y los prueba contra /api/my-tickets/{id} para leer
 * o mutar tickets de otro usuario -- del mismo tenant o de otro.
 */
class MyTicketsIdorTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;

    private Client $clientB;

    private User $requesterA1;

    private User $requesterA2;

    private User $requesterB;

    private Ticket $ticketA1;

    protected function setUp(): void
    {
        parent::setUp();

        $operator = $this->makeUser('operator@test.local');
        $this->clientA = Client::create(['name' => 'Tenant A', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $this->clientB = Client::create(['name' => 'Tenant B', 'operator_user_id' => $operator->id, 'is_active' => true]);

        $siteA = Site::create(['client_id' => $this->clientA->id, 'name' => 'Site A', 'type' => 'physical', 'is_active' => true]);
        Site::create(['client_id' => $this->clientB->id, 'name' => 'Site B', 'type' => 'physical', 'is_active' => true]);

        $this->requesterA1 = $this->makeUser('requester-a1@test.local', $this->clientA->id);
        $this->requesterA2 = $this->makeUser('requester-a2@test.local', $this->clientA->id);
        $this->requesterB = $this->makeUser('requester-b@test.local', $this->clientB->id);

        $catalog = $this->makeCatalog();

        $this->ticketA1 = Ticket::create([
            'subject' => 'Ticket privado de requesterA1',
            'folio' => 'IDOR-'.uniqid(),
            'area_origin_id' => $catalog['area_id'],
            'area_current_id' => $catalog['area_id'],
            'site_id' => $siteA->id,
            'client_id' => $this->clientA->id,
            'requester_id' => $this->requesterA1->id,
            'ticket_type_id' => $catalog['ticket_type_id'],
            'priority_id' => $catalog['priority_id'],
            'ticket_state_id' => $catalog['ticket_state_id'],
        ]);
    }

    /** Previene: IDOR entre dos solicitantes del MISMO tenant (GET). */
    public function test_requester_cannot_read_another_requesters_ticket_same_tenant(): void
    {
        $response = $this->actingAs($this->requesterA2, 'web')
            ->getJson("/api/my-tickets/{$this->ticketA1->id}");

        $response->assertForbidden();
    }

    /** Previene: IDOR entre solicitantes de DOS tenants distintos (GET). */
    public function test_requester_cannot_read_foreign_tenant_ticket(): void
    {
        $response = $this->actingAs($this->requesterB, 'web')
            ->getJson("/api/my-tickets/{$this->ticketA1->id}");

        $this->assertDenied($response);
    }

    /** Previene: IDOR mutando (comentar) el ticket de otro solicitante del mismo tenant. */
    public function test_requester_cannot_comment_on_another_requesters_ticket(): void
    {
        $response = $this->actingAs($this->requesterA2, 'web')
            ->postJson("/api/my-tickets/{$this->ticketA1->id}/comments", ['comment' => 'Intento ajeno']);

        $response->assertForbidden();
        $this->assertSame(
            0,
            DB::table('ticket_histories')->where('ticket_id', $this->ticketA1->id)->count(),
            'Ningún historial debe crearse en el ticket ajeno tras un intento de comentario rechazado.'
        );
    }

    /** Previene: IDOR cancelando el ticket de otro solicitante (cross-tenant). */
    public function test_requester_cannot_cancel_foreign_tenant_ticket(): void
    {
        $response = $this->actingAs($this->requesterB, 'web')
            ->postJson("/api/my-tickets/{$this->ticketA1->id}/cancel");

        $this->assertDenied($response);

        // La consulta de verificación corre fuera de la request de
        // requesterB (otro tenant) -- sin bypass explícito, RLS la
        // filtraría a "vacía" y el assert daría un falso positivo por la
        // razón equivocada. Mismo patrón que TicketSiteScopingTest::authorizes().
        PgsqlRowLevelSecurity::setBypass(true);
        $this->assertSame(
            $this->ticketA1->ticket_state_id,
            DB::table('tickets')->where('id', $this->ticketA1->id)->value('ticket_state_id'),
            'El estado del ticket ajeno no debe cambiar tras un intento de cancelación rechazado.'
        );
    }

    /** Previene: IDOR enviando una alerta a nombre de otro solicitante del mismo tenant. */
    public function test_requester_cannot_send_alert_on_another_requesters_ticket(): void
    {
        $response = $this->actingAs($this->requesterA2, 'web')
            ->postJson("/api/my-tickets/{$this->ticketA1->id}/alert", ['message' => 'no soy el dueño']);

        $response->assertForbidden();
    }

    /** Control positivo: el dueño real sí puede ver su propio ticket (evita falso positivo por 500/ruta rota). */
    public function test_owner_can_read_their_own_ticket(): void
    {
        $response = $this->actingAs($this->requesterA1, 'web')
            ->getJson("/api/my-tickets/{$this->ticketA1->id}");

        $response->assertOk()->assertJsonPath('id', $this->ticketA1->id);
    }

    /**
     * Con RLS (Postgres) la fila de otro tenant es invisible a nivel de BD
     * antes de que la policy corra (404 por binding). Sin RLS, la policy sí
     * la encuentra y la rechaza explícitamente (403). Mismo patrón que
     * TenantApiIsolationTest::assertTenantBoundaryDenied.
     */
    private function assertDenied(TestResponse $response): void
    {
        if (PgsqlRowLevelSecurity::enabled()) {
            $response->assertNotFound();
        } else {
            $response->assertForbidden();
        }
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
}
