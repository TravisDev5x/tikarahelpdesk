<?php

namespace App\Notifications\Tickets;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Avisa al staff de un tenant que llegó un correo de un remitente sin cuenta
 * reconocida y quedó en la cola de revisión manual (docs/PENDING_TICKET_REVIEW.md).
 * No hay Ticket todavía -- a diferencia de las demás notificaciones de
 * Tickets/, no extiende BaseTicketNotification.
 */
class PendingTicketRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $pendingTicketRequestId,
        public string $fromEmail,
        public ?string $subject,
        public int $clientId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $message = "Nuevo correo sin cuenta reconocida: {$this->fromEmail}";
        if ($this->subject) {
            $message .= " — \"{$this->subject}\"";
        }

        return [
            'kind' => 'pending_ticket_request',
            'pending_ticket_request_id' => $this->pendingTicketRequestId,
            'message' => $message,
            'href' => '/resolbeb/pending-requests',
            'client_id' => $this->clientId,
        ];
    }
}
