<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PendingTicketRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Tickets\PendingTicketRequestNotification;
use App\Services\Classification\TicketClassifierService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Punto único de decisión para la cola de revisión manual de correos no
 * reconocidos (docs/PENDING_TICKET_REVIEW.md). Mismo espíritu que
 * TicketCreationService: un solo lugar que crea/aprueba/rechaza estas
 * solicitudes para que ProcessInboundTicket y el controlador de revisión
 * nunca diverjan.
 */
class PendingTicketReviewService
{
    public function __construct(
        protected TicketCreationService $ticketCreation,
        protected TicketClassifierService $classifier,
        protected TenantClientResolver $tenantClientResolver,
    ) {}

    /**
     * Registra (o actualiza in-place) el intento rechazado. Si el mismo
     * remitente ya tiene una fila 'pending' de este tenant, la refresca en
     * vez de duplicar -- evita saturar la cola y renotificar en cada reintento
     * de un remitente que sigue escribiendo mientras nadie la revisa todavía.
     *
     * @return array{request: PendingTicketRequest, isNew: bool}
     */
    public function recordRejection(Client $tenant, array $parsedEmail, RequesterResolution $resolution): array
    {
        return DB::transaction(function () use ($tenant, $parsedEmail, $resolution) {
            $existing = PendingTicketRequest::where('client_id', $tenant->id)
                ->where('from_email', $parsedEmail['from'])
                ->where('status', PendingTicketRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'client_id' => $tenant->id,
                'from_email' => $parsedEmail['from'],
                'from_name' => $parsedEmail['from_name'] ?? null,
                'subject' => mb_substr($parsedEmail['subject'] ?? '', 0, 255) ?: null,
                'body' => $parsedEmail['body_plain'] ?? null,
                'reason' => $resolution->reason,
                'matched_user_id' => $resolution->user?->id,
                'origin_message_id' => $parsedEmail['message_id'] ?: null,
            ];

            if ($existing) {
                $existing->update($attributes);

                return ['request' => $existing, 'isNew' => false];
            }

            $request = PendingTicketRequest::create($attributes);

            return ['request' => $request, 'isNew' => true];
        });
    }

    public function notifyReviewers(PendingTicketRequest $request): void
    {
        $tenant = $request->client;
        if (! $tenant) {
            return;
        }

        $recipients = $this->reviewerRecipientsFor($tenant);
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new PendingTicketRequestNotification(
            pendingTicketRequestId: $request->id,
            fromEmail: $request->from_email,
            subject: $request->subject,
            clientId: $tenant->id,
        ));
    }

    /**
     * Staff del propio tenant con tickets.review_pending o
     * tickets.manage_all -- deliberadamente sin usar los scopes role()/
     * permission() de Spatie (filtran por el team_id AMBIENTE del request
     * actual, no por el team_id explícito que nos interesa aquí; mismo
     * motivo documentado en TenantContextService::platformRecipientsFor()).
     * No incluye super_admin/plataforma -- esto es triage rutinario de un
     * tenant, no un evento de seguridad cross-tenant. Si nadie del tenant
     * tiene el permiso todavía, cae al operador dueño del cliente como red
     * de seguridad para que no quede en el vacío total.
     */
    private function reviewerRecipientsFor(Client $tenant): Collection
    {
        $permIds = DB::table('permissions')
            ->whereIn('name', ['tickets.review_pending', 'tickets.manage_all'])
            ->where('guard_name', 'web')
            ->pluck('id');

        $roleIds = $permIds->isEmpty() ? collect() : DB::table('roles')
            ->where('team_id', $tenant->id)
            ->where('guard_name', 'web')
            ->pluck('id');

        $grantedRoleIds = $roleIds->isEmpty() ? collect() : DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permIds)
            ->pluck('role_id')
            ->unique();

        $userIds = $grantedRoleIds->isEmpty() ? collect() : DB::table('model_has_roles')
            ->where('team_id', $tenant->id)
            ->where('model_type', User::class)
            ->whereIn('role_id', $grantedRoleIds)
            ->pluck('model_id')
            ->unique();

        $recipients = $userIds->isEmpty() ? collect() : User::whereIn('id', $userIds)->where('status', 'active')->get();

        if ($recipients->isEmpty() && $tenant->operator_user_id) {
            $operator = User::find($tenant->operator_user_id);
            if ($operator) {
                $recipients->push($operator);
            }
        }

        return $recipients;
    }

    /**
     * Vincula la solicitud a un usuario YA existente del mismo tenant y crea
     * el ticket real -- mismos defaults (área/estado/clasificación) que
     * ProcessInboundTicket, vía TicketCreationService::create().
     */
    public function approveWithExistingUser(PendingTicketRequest $request, User $reviewer, int $userId): Ticket
    {
        return DB::transaction(function () use ($request, $reviewer, $userId) {
            /** @var PendingTicketRequest $locked */
            $locked = PendingTicketRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PendingTicketRequest::STATUS_PENDING) {
                throw new RuntimeException('pending_ticket_request_already_reviewed');
            }

            $targetUser = User::findOrFail($userId);

            if ($targetUser->status !== 'active') {
                throw new RuntimeException('target_user_not_active');
            }

            if ($this->tenantClientResolver->resolve($targetUser) !== $locked->client_id) {
                throw new RuntimeException('target_user_wrong_tenant');
            }

            $classification = $this->classifier->classify(
                subject: $locked->subject ?? '',
                body: $locked->body ?? '',
                clientId: $locked->client_id,
            );

            [$defaultAreaId, $defaultStateId] = $this->ticketCreation->resolveDefaultAreaAndState($locked->client_id);

            $ticket = $this->ticketCreation->create([
                'client_id' => $locked->client_id,
                'source' => 'email',
                'origin_message_id' => $locked->origin_message_id,
                'subject' => $locked->subject ?: '(sin asunto)',
                'description' => $locked->body,
                'requester_id' => $targetUser->id,
                'site_id' => $targetUser->site_id,
                'area_origin_id' => $defaultAreaId,
                'area_current_id' => $defaultAreaId,
                'ticket_state_id' => $defaultStateId,
                'ticket_type_id' => $classification['ticket_type_id'] ?? null,
                'priority_id' => $classification['priority_id'] ?? null,
            ]);

            $locked->update([
                'status' => PendingTicketRequest::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'resulting_ticket_id' => $ticket->id,
            ]);

            \App\Events\TicketCreated::dispatch($ticket);

            return $ticket;
        });
    }

    public function reject(PendingTicketRequest $request, User $reviewer, ?string $note): void
    {
        DB::transaction(function () use ($request, $reviewer, $note) {
            /** @var PendingTicketRequest $locked */
            $locked = PendingTicketRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PendingTicketRequest::STATUS_PENDING) {
                throw new RuntimeException('pending_ticket_request_already_reviewed');
            }

            $locked->update([
                'status' => PendingTicketRequest::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
        });
    }
}
