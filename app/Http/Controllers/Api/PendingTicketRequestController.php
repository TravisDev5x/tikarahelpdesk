<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PendingTicketRequest;
use App\Services\OperatorScopeService;
use App\Services\PendingTicketReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Cola de revisión manual de correos no reconocidos
 * (docs/PENDING_TICKET_REVIEW.md). Scope por tenant vía
 * OperatorScopeService::applyOnPendingTicketRequests(), misma forma que
 * applyOnAuditLogs() (columna client_id plana).
 */
class PendingTicketRequestController extends Controller
{
    public function __construct(protected OperatorScopeService $operatorScope) {}

    public function index(Request $request)
    {
        $user = Auth::user();

        $status = $request->input('status', PendingTicketRequest::STATUS_PENDING);
        $query = PendingTicketRequest::with([
            'client:id,name',
            'matchedUser:id,first_name,paternal_last_name,maternal_last_name,email,status',
            'reviewedBy:id,first_name,paternal_last_name,maternal_last_name',
            'resultingTicket:id,folio',
        ]);

        if ($status === 'resolved') {
            $query->resolved();
        } elseif (in_array($status, [PendingTicketRequest::STATUS_PENDING, PendingTicketRequest::STATUS_APPROVED, PendingTicketRequest::STATUS_REJECTED], true)) {
            $query->where('status', $status);
        }

        $query = $this->operatorScope->applyOnPendingTicketRequests($query, $user);

        $requests = $query->orderByDesc('created_at')->paginate(20);

        return response()->json($requests);
    }

    public function approve(Request $request, PendingTicketRequest $pendingTicketRequest, PendingTicketReviewService $service)
    {
        $this->authorizeAccess($pendingTicketRequest);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        try {
            $ticket = $service->approveWithExistingUser($pendingTicketRequest, Auth::user(), (int) $validated['user_id']);
        } catch (RuntimeException $e) {
            return $this->mapRuntimeError($e);
        }

        return response()->json([
            'message' => 'Ticket creado',
            'ticket' => ['id' => $ticket->id, 'folio' => $ticket->folio],
        ]);
    }

    public function reject(Request $request, PendingTicketRequest $pendingTicketRequest, PendingTicketReviewService $service)
    {
        $this->authorizeAccess($pendingTicketRequest);

        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $service->reject($pendingTicketRequest, Auth::user(), $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->mapRuntimeError($e);
        }

        return response()->json(['message' => 'Solicitud rechazada']);
    }

    private function authorizeAccess(PendingTicketRequest $pendingTicketRequest): void
    {
        $accessible = $this->operatorScope
            ->applyOnPendingTicketRequests(PendingTicketRequest::query()->whereKey($pendingTicketRequest->id), Auth::user())
            ->exists();

        abort_unless($accessible, 403, 'No tienes acceso a esta solicitud.');
    }

    private function mapRuntimeError(RuntimeException $e)
    {
        return match ($e->getMessage()) {
            'pending_ticket_request_already_reviewed' => response()->json(['message' => 'Esta solicitud ya fue revisada por alguien más.'], 409),
            'target_user_not_active' => response()->json(['message' => 'El usuario seleccionado no está activo.'], 422),
            'target_user_wrong_tenant' => response()->json(['message' => 'El usuario seleccionado no pertenece a este tenant.'], 422),
            default => response()->json(['message' => 'No se pudo procesar la solicitud.'], 422),
        };
    }
}
