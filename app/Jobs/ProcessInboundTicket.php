<?php

namespace App\Jobs;

use App\Mail\RegistrationRequiredMail;
use App\Models\Client;
use App\Services\Classification\TicketClassifierService;
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
use Illuminate\Support\Facades\Mail;

class ProcessInboundTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 60;

    public function __construct(
        private int   $clientId,
        private array $parsedEmail
    ) {}

    public function handle(TicketClassifierService $classifier, TicketCreationService $ticketCreation): void
    {
        $tenant = Client::find($this->clientId);
        if (! $tenant) {
            Log::error('ProcessInboundTicket: tenant no encontrado', [
                'client_id' => $this->clientId,
            ]);
            return;
        }

        // Jobs son código confiable — bypass RLS para operar como sistema
        if (PgsqlRowLevelSecurity::enabled()) {
            PgsqlRowLevelSecurity::setBypass(true);
        }
        TenantContextService::set($tenant);

        // RBAC v2 (Fase 6): los Jobs no pasan por ApplyPgsqlTenantRls (ese
        // middleware es de HTTP, no de cola) -- sin esto, TicketCreated
        // (disparado más abajo) llega a SendTicketNotification::recipients()
        // -> TicketPolicy::notifiableStaff() con team_id sin resolver
        // (null, o lo que haya quedado de un job anterior en un worker de
        // larga duración -- riesgo real de fuga entre tenants en ese
        // segundo caso, no solo un crash).
        setPermissionsTeamId($tenant->id);

        // Política: un email = un tenant, y solo usuarios ya registrados
        // pueden generar tickets por correo — nada de cuentas guest
        // implícitas (antes: User::firstOrCreate creaba una para cualquier
        // remitente). Único punto de decisión: TicketCreationService.
        $resolution = $ticketCreation->resolveActiveTenantUser($this->parsedEmail['from'], $this->clientId);

        if (! $resolution->allowed) {
            Log::warning('ProcessInboundTicket: remitente rechazado, no se crea ticket', [
                'client_id' => $this->clientId,
                'from' => $this->parsedEmail['from'],
                'reason' => $resolution->reason,
            ]);

            Mail::to($this->parsedEmail['from'])->queue(new RegistrationRequiredMail($this->parsedEmail['from'], $tenant));

            return;
        }

        $requester = $resolution->user;

        DB::transaction(function () use ($tenant, $classifier, $ticketCreation, $requester) {
            $classification = $classifier->classify(
                subject:  $this->parsedEmail['subject'],
                body:     $this->parsedEmail['body_plain'],
                clientId: $this->clientId
            );

            // Lookup required NOT-NULL catalog IDs
            $defaultAreaId = DB::table('areas')
                ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $this->clientId))
                ->orderByRaw('client_id IS NULL')  // tenant-specific first, then global
                ->orderBy('id')
                ->value('id');

            $defaultStateId = DB::table('ticket_states')
                ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $this->clientId))
                ->where(fn ($q) => $q->whereNull('is_final')->orWhere('is_final', false))
                ->orderBy('id')
                ->value('id');

            if (! $defaultAreaId || ! $defaultStateId) {
                throw new \RuntimeException(
                    "Tenant {$this->clientId}: sin área o estado disponible para el ticket."
                );
            }

            // Folio atómico vía TicketSequence::nextFor(), a través del mismo
            // servicio que usa el flujo de portal (MyTicketsController::store).
            $ticket = $ticketCreation->create([
                'client_id'        => $this->clientId,
                'source'           => 'email',
                'origin_message_id'=> $this->parsedEmail['message_id'] ?: null,
                'subject'          => mb_substr($this->parsedEmail['subject'], 0, 255),
                'description'      => $this->parsedEmail['body_plain'],
                'requester_id'     => $requester->id,
                'site_id'          => $requester->site_id,
                'area_origin_id'   => $defaultAreaId,
                'area_current_id'  => $defaultAreaId,
                'ticket_state_id'  => $defaultStateId,
                'ticket_type_id'   => $classification['ticket_type_id'] ?? null,
                'priority_id'      => $classification['priority_id'] ?? null,
            ]);

            Log::info('Tikara: ticket creado por email', [
                'folio'      => $ticket->folio,
                'ticket_id'  => $ticket->id,
                'client_id'  => $this->clientId,
                'from'       => $this->parsedEmail['from'],
                'subject'    => $this->parsedEmail['subject'],
                'classifier' => $classification['source'],
            ]);

            // Antes solo el flujo de portal disparaba esto (MyTicketsController::store);
            // el de email nunca notificaba nada, ni siquiera in-app.
            \App\Events\TicketCreated::dispatch($ticket);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessInboundTicket: job fallido', [
            'client_id' => $this->clientId,
            'from'      => $this->parsedEmail['from'] ?? 'unknown',
            'error'     => $exception->getMessage(),
        ]);
    }
}
