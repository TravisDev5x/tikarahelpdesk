<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketState;
use App\Services\Tenant\TenantContextService;
use App\Services\TicketCreationService;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessInboundReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 30;

    public function __construct(
        private int    $clientId,
        private string $folio,
        private array  $parsedEmail
    ) {}

    public function handle(TicketCreationService $ticketCreation): void
    {
        $tenant = Client::find($this->clientId);
        if (! $tenant) {
            return;
        }

        if (PgsqlRowLevelSecurity::enabled()) {
            PgsqlRowLevelSecurity::setBypass(true);
        }
        TenantContextService::set($tenant);

        // RBAC v2 (Fase 6): mismo razonamiento que ProcessInboundTicket --
        // este Job no pasa por ApplyPgsqlTenantRls.
        setPermissionsTeamId($tenant->id);

        $ticket = Ticket::where('client_id', $this->clientId)
            ->where('folio', $this->folio)
            ->first();

        if (! $ticket) {
            Log::warning('ProcessInboundReply: ticket no encontrado', [
                'folio'     => $this->folio,
                'client_id' => $this->clientId,
            ]);
            return;
        }

        // Misma validación que la creación de tickets (Único punto de
        // decisión: TicketCreationService::resolveActiveTenantUser). Antes,
        // si el remitente no matcheaba un usuario activo del tenant, la
        // respuesta se atribuía silenciosamente al requester original del
        // ticket y se procesaba igual — ahora se rechaza.
        $resolution = $ticketCreation->resolveActiveTenantUser($this->parsedEmail['from'], $this->clientId);

        if (! $resolution->allowed) {
            Log::warning('ProcessInboundReply: remitente rechazado, no se agrega respuesta', [
                'client_id' => $this->clientId,
                'folio' => $this->folio,
                'from' => $this->parsedEmail['from'],
                'reason' => $resolution->reason,
            ]);

            return;
        }

        $actorId = $resolution->user->id;

        DB::transaction(function () use ($ticket, $actorId) {
            TicketHistory::create([
                'ticket_id'   => $ticket->id,
                'actor_id'    => $actorId,
                'action'      => 'email_reply',
                'note'        => mb_substr($this->parsedEmail['body_plain'], 0, 5000),
                'is_internal' => false,
            ]);

            // Reabrir si el ticket está en estado final
            if ($ticket->state?->is_final) {
                $openState = TicketState::where('is_final', false)
                    ->orderBy('id')
                    ->first();

                if ($openState) {
                    $ticket->update(['ticket_state_id' => $openState->id]);

                    TicketHistory::create([
                        'ticket_id'   => $ticket->id,
                        'actor_id'    => $actorId,
                        'action'      => 'reopened_by_email',
                        'note'        => 'Reabierto automáticamente por respuesta de email.',
                        'is_internal' => true,
                    ]);
                }
            }

            Log::info('Tikara: respuesta de email agregada a ticket', [
                'folio'     => $this->folio,
                'ticket_id' => $ticket->id,
                'client_id' => $this->clientId,
                'from'      => $this->parsedEmail['from'],
            ]);

            // Antes ninguno de los dos flujos (email ni portal) disparaba esto.
            \App\Events\TicketUpdated::dispatch($ticket);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessInboundReply: job fallido', [
            'client_id' => $this->clientId,
            'folio'     => $this->folio,
            'error'     => $exception->getMessage(),
        ]);
    }
}
