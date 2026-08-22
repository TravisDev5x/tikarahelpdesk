<?php

namespace App\Http\Controllers\Api;

use App\Events\TicketCreated;
use App\Events\TicketUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Models\Ticket;
use App\Models\TicketAreaAccess;
use App\Models\TicketAttachment;
use App\Models\TicketHistory;
use App\Services\ClientScopeService;
use App\Services\RequesterTicketService;
use App\Services\TicketCreationService;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Módulo "Mis Tickets": solo tickets donde el usuario es solicitante.
 * No comparte lógica con TicketController (operativo). Queries y políticas propias.
 */
class MyTicketsController extends Controller
{
    public function __construct(
        protected RequesterTicketService $requesterTicketService,
        protected ClientScopeService $clientScope,
        protected TicketCreationService $ticketCreation,
    ) {}

    /**
     * Lista únicamente tickets creados por el usuario autenticado como solicitante.
     * Filtros básicos: estado, prioridad, fecha, tipo/categoría.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        Gate::authorize('requester.viewAny.ticket');

        $query = Ticket::query()
            ->requesterOnly($user->id);

        if ($user->client_id !== null) {
            $query->where('tickets.client_id', $user->client_id);
        }

        $query->with([
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name',
                'ticketType:id,name',
                'priority:id,name,level',
                'state:id,name,code',
            ]);

        $this->applyRequesterFilters($request, $query);

        $query->orderByDesc('id');

        $perPage = (int) $request->input('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        return $query->paginate($perPage);
    }

    /**
     * Detalle de un ticket propio (solo solicitante). Oculta notas internas.
     */
    public function show(Ticket $ticket)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        if ($user->client_id !== null
            && $ticket->client_id !== null
            && (int) $ticket->client_id !== (int) $user->client_id) {
            abort(403, 'No tienes acceso a este ticket');
        }

        Gate::authorize('requester.view.ticket', $ticket);

        $ticket->load([
            'areaOrigin:id,name',
            'areaCurrent:id,name',
            'site:id,name',
            'location:id,name,site_id',
            'requester:id,name,email',
            'assignedUser:id,name,position_id',
            'ticketType:id,name',
            'priority:id,name,level',
            'state:id,name,code,is_final',
            'histories' => function ($q) {
                $q->orderByDesc('created_at');
                $q->with(['actor:id,name,email', 'state:id,name,code']);
            },
            'attachments' => function ($q) {
                $q->orderByDesc('created_at');
                $q->with('uploader:id,name');
            },
        ]);

        $ticket->setRelation(
            'histories',
            $ticket->histories->reject(fn ($h) => $h->action === 'comment' && $h->is_internal)->values()
        );

        $this->maskAgentIdentity($ticket);

        return $this->withRequesterAbilities($ticket);
    }

    /**
     * Crear ticket (el usuario será siempre requester).
     */
    public function store(StoreTicketRequest $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        Gate::authorize('requester.create.ticket');

        $data = $request->validated();
        if ($error = $this->clientScope->stampTicketSiteFromUser($user, $data)) {
            return $error;
        }
        if ($user->client_id !== null) {
            $data['client_id'] = (int) $user->client_id;
        }

        // Mismo punto de decisión que email (TicketCreationService::
        // resolveActiveTenantUser) — aquí siempre debería ser 'ok' (el
        // usuario ya está autenticado vía Sanctum y client_id sale de él
        // mismo, nunca de input externo), pero es el mismo lugar que decide
        // para los tres caminos, no lógica duplicada.
        if (! empty($data['client_id'])) {
            $resolution = $this->ticketCreation->resolveActiveTenantUser($user->email, (int) $data['client_id']);
            if (! $resolution->allowed) {
                return response()->json(['message' => 'No autorizado para crear tickets en este tenant.'], 403);
            }
        }

        $data['requester_id'] = $user->id;
        $data['requester_position_id'] = $user->position_id ?? null;
        $clientCreatedAt = Carbon::parse($data['created_at'])->timezone(config('app.timezone'));
        unset($data['created_at']);

        $data['due_at'] = ! empty($data['due_at'])
            ? Carbon::parse($data['due_at'])->timezone(config('app.timezone'))
            : $clientCreatedAt->copy()->addHours(Ticket::SLA_LIMIT_HOURS);

        return DB::transaction(function () use ($data, $user, $clientCreatedAt) {
            // Folio atómico vía TicketSequence::nextFor(), mismo mecanismo que
            // el flujo de email (ProcessInboundTicket) — antes este controlador
            // creaba el ticket directamente y nunca le asignaba folio.
            $ticket = $this->ticketCreation->create($data, $clientCreatedAt);

            foreach ([$ticket->area_origin_id, $ticket->area_current_id] as $areaId) {
                try {
                    TicketAreaAccess::firstOrCreate(
                        ['ticket_id' => $ticket->id, 'area_id' => $areaId],
                        ['reason' => 'created', 'created_at' => now()]
                    );
                } catch (\Throwable $e) {
                    // fallo silencioso
                }
            }

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $user->id,
                'from_area_id' => null,
                'to_area_id' => $ticket->area_current_id,
                'ticket_state_id' => $ticket->ticket_state_id,
                'note' => 'Creación de ticket',
                'is_internal' => false,
                'created_at' => $ticket->created_at,
            ]);

            TicketCreated::dispatch($ticket);

            $ticket->load(
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name,client_id',
                'client:id,name',
                'location:id,name',
                'ticketType:id,name',
                'priority:id,name,level',
                'state:id,name'
            );

            return response()->json($this->withRequesterAbilities($ticket), 201);
        });
    }

    /**
     * Enviar alerta/observación como solicitante.
     */
    public function sendAlert(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        Gate::authorize('requester.alert.ticket', $ticket);

        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
        ]);

        $result = $this->requesterTicketService->sendAlert(
            $ticket,
            $user,
            $data['message'] ?? null
        );

        $ticket = $result['ticket'];
        $ticket->load([
            'areaOrigin:id,name',
            'areaCurrent:id,name',
            'site:id,name',
            'location:id,name',
            'requester:id,name,email',
            'assignedUser:id,name,position_id',
            'ticketType:id,name',
            'priority:id,name,level',
            'state:id,name,code',
        ]);

        $this->maskAgentIdentity($ticket);

        return response()->json([
            'alert' => $result['alert'],
            'ticket' => $this->withRequesterAbilities($ticket),
        ], 201);
    }

    /**
     * Añadir comentario como solicitante (visible en el historial).
     */
    public function addComment(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        Gate::authorize('requester.comment.ticket', $ticket);

        $data = $request->validate([
            'note' => 'required|string|max:10000',
        ]);

        try {
            $history = $this->requesterTicketService->addComment($ticket, $user, $data['note']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Antes esto no disparaba nada; ahora usa el mismo evento que el reply
        // por email (ProcessInboundReply), unificando el gancho de notificación.
        TicketUpdated::dispatch($ticket);

        $ticket->load([
            'histories' => function ($q) {
                $q->orderByDesc('created_at');
                $q->with(['actor:id,name,email', 'state:id,name,code']);
            },
        ]);

        $this->maskAgentIdentity($ticket);

        // El history recién creado es siempre del propio solicitante
        // (RequesterTicketService::addComment fija actor_id = $user->id) --
        // no necesita masking, a diferencia de los del historial completo
        // arriba, que sí pueden incluir comentarios de agentes.
        return response()->json([
            'history' => $history->load(['actor:id,name,email', 'state:id,name,code']),
            'ticket' => $this->withRequesterAbilities($ticket),
        ], 201);
    }

    /**
     * Subir adjuntos al ticket como solicitante.
     */
    public function storeAttachment(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        Gate::authorize('requester.attach.ticket', $ticket);

        $data = $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $saved = [];
        foreach ($request->file('attachments', []) as $file) {
            // Auditoría de bugs críticos (2026-08-22): mismo fix que
            // TicketAttachmentController::store() -- disco 'local' en vez
            // de 'public', solo accesible vía downloadAttachment() autenticado.
            $path = $file->store("tickets/{$ticket->id}", ['disk' => 'local']);
            $attachment = TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'uploaded_by' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'file_name' => basename($path),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'disk' => 'local',
            ]);
            $saved[] = $attachment;
        }

        return response()->json($saved, 201);
    }

    /**
     * Cancelar ticket como solicitante (solo antes de que soporte lo tome).
     */
    public function cancel(Ticket $ticket)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        try {
            Gate::authorize('requester.cancel.ticket', $ticket);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $message = $ticket->assigned_user_id
                ? 'Solo puedes cancelar antes de que soporte tome el ticket.'
                : 'No puedes cancelar este ticket.';
            return response()->json(['message' => $message], 403);
        }

        try {
            $ticket = $this->requesterTicketService->cancel($ticket, $user);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $ticket->load([
            'areaOrigin:id,name',
            'areaCurrent:id,name',
            'site:id,name',
            'location:id,name',
            'requester:id,name,email',
            'assignedUser:id,name,position_id',
            'ticketType:id,name',
            'priority:id,name,level',
            'state:id,name,code',
            'histories' => function ($q) {
                $q->orderByDesc('created_at');
                $q->with(['actor:id,name,email', 'state:id,name,code']);
            },
        ]);

        $this->maskAgentIdentity($ticket);

        return response()->json($this->withRequesterAbilities($ticket));
    }

    /**
     * Descargar adjunto de un ticket propio (solo solicitante).
     */
    public function downloadAttachment(Ticket $ticket, TicketAttachment $attachment)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        Gate::authorize('requester.view.ticket', $ticket);

        if ((int) $attachment->ticket_id !== (int) $ticket->id) {
            return response()->json(['message' => 'Adjunto no válido'], 404);
        }

        if (! $attachment->file_path || ! Storage::disk($attachment->disk ?: 'public')->exists($attachment->file_path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        return Storage::disk($attachment->disk ?: 'public')->download(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type]
        );
    }

    /**
     * Filtros básicos solo sobre tickets del solicitante.
     */
    protected function applyRequesterFilters(Request $request, $query): void
    {
        if ($request->filled('ticket_state_id')) {
            $query->where('ticket_state_id', $request->input('ticket_state_id'));
        }
        if ($request->filled('priority_id')) {
            $query->where('priority_id', $request->input('priority_id'));
        }
        if ($request->filled('ticket_type_id')) {
            $query->where('ticket_type_id', $request->input('ticket_type_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
    }

    /**
     * Sub-fase 5.2: si el tenant tiene clients.show_agent_names = false,
     * reemplaza nombre y email del agente por una etiqueta genérica en todo
     * lo que ve el solicitante -- assignedUser y cada entrada de histories
     * cuyo actor no sea el propio solicitante (sus propios comentarios no
     * se tocan). No aplica al lado staff (TicketController): exclusivo de
     * este controlador ("Mis Tickets"). Default de la columna es true
     * (mostrar), así que si el ticket no tiene client o el flag está en
     * true, no hace nada.
     */
    protected function maskAgentIdentity(Ticket $ticket): Ticket
    {
        $ticket->loadMissing('client');

        if (! $ticket->client || $ticket->client->show_agent_names !== false) {
            return $ticket;
        }

        if ($ticket->relationLoaded('assignedUser') && $ticket->assignedUser) {
            $this->maskAgentUser($ticket->assignedUser);
        }

        if ($ticket->relationLoaded('histories')) {
            foreach ($ticket->histories as $history) {
                if ($history->relationLoaded('actor')
                    && $history->actor
                    && (int) $history->actor->id !== (int) $ticket->requester_id) {
                    $this->maskAgentUser($history->actor);
                }
            }
        }

        return $ticket;
    }

    protected function maskAgentUser(\App\Models\User $agent): void
    {
        $agent->setAttribute('name', 'Agente de soporte');
        if (array_key_exists('email', $agent->getAttributes())) {
            $agent->setAttribute('email', null);
        }
    }

    /**
     * Abilities solo para contexto solicitante. Sin acciones operativas (assign, escalate, etc.).
     */
    protected function withRequesterAbilities(Ticket $ticket): Ticket
    {
        $user = Auth::user();
        $gate = $user ? Gate::forUser($user) : null;

        $ticket->setAttribute('abilities', [
            'alert' => $gate ? $gate->allows('requester.alert.ticket', $ticket) : false,
            'comment' => $gate ? $gate->allows('requester.comment.ticket', $ticket) : false,
            'attach' => $gate ? $gate->allows('requester.attach.ticket', $ticket) : false,
            'cancel' => $gate ? $gate->allows('requester.cancel.ticket', $ticket) : false,
        ]);

        return $ticket;
    }
}
