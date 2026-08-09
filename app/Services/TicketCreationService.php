<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketSequence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Punto único de creación de tickets, usado tanto por el flujo de email
 * (ProcessInboundTicket) como por el de portal (MyTicketsController::store).
 * Antes divergían: el flujo de email llamaba a TicketSequence::nextFor()
 * explícitamente y el de portal no, dejando folio NULL en tickets de portal.
 */
class TicketCreationService
{
    public function __construct(
        protected TenantClientResolver $tenantClientResolver,
        protected TicketPrefixService $prefixService,
    ) {}

    /**
     * Crea un ticket asignándole folio atómico antes de persistir.
     * $attributes debe incluir 'client_id'.
     *
     * site_id: si no viene, se HEREDA del site "hogar" del requester
     * (users.site_id) — nunca se infiere por dominio de correo ni ningún
     * otro canal. Requester sin site → el ticket se crea igual con
     * site_id NULL ("sin site asignado", visible solo admin/supervisor).
     * Idéntico para email y portal/API porque ambos pasan por aquí.
     */
    public function create(array $attributes, ?Carbon $createdAt = null): Ticket
    {
        if (empty($attributes['client_id'])) {
            throw new InvalidArgumentException('TicketCreationService::create requiere client_id.');
        }

        $client = Client::find((int) $attributes['client_id']);
        if (! $client) {
            throw new InvalidArgumentException("TicketCreationService::create: client_id {$attributes['client_id']} no existe.");
        }

        if (! array_key_exists('site_id', $attributes) || $attributes['site_id'] === null) {
            $requester = ! empty($attributes['requester_id'])
                ? User::find((int) $attributes['requester_id'])
                : null;
            $attributes['site_id'] = $requester?->site_id;
        }

        $this->assertSiteBelongsToClient($attributes['site_id'], $client);

        $attributes['folio'] = $this->nextFolioFor($client);

        $ticket = new Ticket($attributes);

        if ($createdAt) {
            $ticket->created_at = $createdAt;
        }

        $ticket->save();

        return $ticket;
    }

    /**
     * Integridad no-cross-tenant: un site con client_id asignado solo puede
     * usarse en tickets de ese mismo client. Sites globales/compartidos
     * (client_id NULL, ej. "Remoto") se aceptan en cualquier tenant.
     */
    private function assertSiteBelongsToClient(?int $siteId, Client $client): void
    {
        if (! $siteId) {
            return;
        }

        $siteClientId = \App\Models\Site::where('id', $siteId)->value('client_id');

        if ($siteClientId !== null && (int) $siteClientId !== (int) $client->id) {
            throw new InvalidArgumentException(
                "Site {$siteId} pertenece al client {$siteClientId}, no al client {$client->id} del ticket."
            );
        }
    }

    /**
     * Compone el siguiente folio del tenant: TK-{Letra}{Número:5}-{Prefijo}-{Random:5}
     * (ej. TK-A00042-SYD-88291).
     *
     * - Letra/Número: del contador atómico interno n (TicketSequence).
     *   Bloques de 100,000: n 1–99,999 → A (mostrado = n);
     *   n 100,000–199,999 → B (mostrado = n − 100,000); y así hasta Z
     *   (n 2,500,000–2,599,999). Pasado Z se lanza excepción (≈2.6M tickets
     *   por tenant; decidir formato extendido si algún día se alcanza).
     * - Prefijo: clients.ticket_prefix (inmutable). Si el cliente aún no lo
     *   tiene (fila anterior al backfill o insert crudo), se deriva y asigna
     *   aquí mismo — lazy, para no fallar la creación del ticket.
     * - Random: 5 dígitos vía random_int (CSPRNG). No se guarda en columna
     *   aparte: queda embebido en el folio, que se escribe una sola vez al
     *   crear y nada lo recalcula — inmutable por construcción.
     */
    public function nextFolioFor(Client $client): string
    {
        $prefix = $client->ticket_prefix ?: $this->prefixService->assignTo($client);

        $n = TicketSequence::nextNumberFor((int) $client->id);
        [$letter, $shown] = $this->splitCounter($n);

        $random = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        return sprintf('TK-%s%05d-%s-%s', $letter, $shown, $prefix, $random);
    }

    /**
     * @return array{0: string, 1: int} [letra, número mostrado]
     */
    private function splitCounter(int $n): array
    {
        if ($n <= 99999) {
            return ['A', $n];
        }

        $block = intdiv($n, 100000);
        if ($block > 25) {
            throw new RuntimeException("Contador de folios agotó la letra Z (n={$n}).");
        }

        return [chr(ord('A') + $block), $n - $block * 100000];
    }

    /**
     * Área y estado por defecto para un ticket nuevo de un tenant -- ambas
     * columnas son NOT NULL en `tickets`. Extraído de ProcessInboundTicket
     * (antes inline en su transacción) para que el flujo de aprobación de
     * PendingTicketReviewService use exactamente los mismos defaults sin
     * duplicar el lookup.
     *
     * @return array{0: int, 1: int} [area_id, ticket_state_id]
     */
    public function resolveDefaultAreaAndState(int $clientId): array
    {
        $defaultAreaId = DB::table('areas')
            ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $clientId))
            ->orderByRaw('client_id IS NULL')  // tenant-specific first, then global
            ->orderBy('id')
            ->value('id');

        $defaultStateId = DB::table('ticket_states')
            ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $clientId))
            ->where(fn ($q) => $q->whereNull('is_final')->orWhere('is_final', false))
            ->orderBy('id')
            ->value('id');

        if (! $defaultAreaId || ! $defaultStateId) {
            throw new RuntimeException(
                "Tenant {$clientId}: sin área o estado disponible para el ticket."
            );
        }

        return [$defaultAreaId, $defaultStateId];
    }

    /**
     * Único lugar que decide "¿este email puede generar/interactuar con un
     * ticket de este tenant?". Política: un email = un tenant, siempre; solo
     * usuarios ya registrados (nunca creados implícitamente) pueden generar
     * tickets por correo. Usado por email (creación y respuesta) y portal.
     */
    public function resolveActiveTenantUser(string $email, int $tenantClientId): RequesterResolution
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            Log::warning('ticket requester: email no registrado', [
                'email' => $email,
                'tenant_client_id' => $tenantClientId,
            ]);

            return RequesterResolution::unregistered();
        }

        if ($user->status !== 'active') {
            Log::warning('ticket requester: usuario no activo', [
                'user_id' => $user->id,
                'email' => $email,
                'status' => $user->status,
                'tenant_client_id' => $tenantClientId,
            ]);

            return RequesterResolution::inactive($user);
        }

        $actualClientId = $this->tenantClientResolver->resolve($user);

        if ($actualClientId !== $tenantClientId) {
            Log::warning('ticket requester: email registrado en otro tenant', [
                'user_id' => $user->id,
                'email' => $email,
                'tenant_client_id_target' => $tenantClientId,
                'tenant_client_id_real' => $actualClientId,
            ]);

            return RequesterResolution::wrongTenant($user, $actualClientId);
        }

        return RequesterResolution::ok($user);
    }
}
