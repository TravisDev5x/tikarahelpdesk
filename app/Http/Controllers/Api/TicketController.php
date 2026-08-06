<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAlert;
use App\Models\AuditLog;
use App\Models\TicketHistory;
use App\Models\TicketAreaAccess;
use App\Models\TicketState;
use App\Models\User;
use App\Models\PriorityMatrix;
use App\Mail\TicketReassignedMail;
use App\Notifications\Tickets\TicketAssignedNotification;
use App\Notifications\Tickets\TicketReassignedNotification;
use App\Notifications\Tickets\TicketEscalatedNotification;
use App\Notifications\Tickets\TicketRequesterResolvedNotification;
use App\Notifications\Tickets\TicketRequesterCommentNotification;
use App\Notifications\Tickets\TicketRequesterAlertNotification;
use App\Events\TicketCreated;
use App\Events\TicketUpdated;
use App\Exports\TicketAuditExport;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Services\ClientScopeService;

class TicketController extends Controller
{
    public function __construct(
        protected ClientScopeService $clientScope,
        protected \App\Services\TicketCreationService $ticketCreation
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        if ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'tickets')) {
            Log::warning('tickets.view_area sin area_id', ['user_id' => $user->id]);

            return $blocked;
        }

        Gate::authorize('viewAny', Ticket::class);

        $query = Ticket::with([
            'areaOrigin:id,name',
            'areaCurrent:id,name',
            'site:id,name,client_id',
            'client:id,name',
            'location:id,name,site_id',
            'requester:id,name,email',
            'assignedUser:id,name,position_id',
            'ticketType:id,name',
            'priority:id,name,level',
            'impactLevel:id,name',
            'urgencyLevel:id,name',
            'state:id,name,code',
        ]);

        // Alcance base via Policy (mismo comportamiento que antes)
        $policy = app(\App\Policies\TicketPolicy::class);
        $query = $policy->scopeFor($user, $query);

        $this->applyCatalogFilters($request, $user, $query);

        $assignedTo = $request->input('assigned_to');
        $assignedStatus = $request->input('assigned_status');
        if ($assignedTo === 'me') {
            $query->where('assigned_user_id', $user->id);
        } elseif ($assignedStatus === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif ($request->filled('assigned_user_id')) {
            $assigneeId = (int) $request->input('assigned_user_id');
            $allowed = true;
            if (!$user->can('tickets.manage_all')) {
                $allowed = $this->clientScope->assertUserAccessible($user, $assigneeId)
                    && DB::table('users')
                        ->where('id', $assigneeId)
                        ->where('area_id', $user->area_id)
                        ->exists();
            }
            if ($allowed) {
                $query->where('assigned_user_id', $assigneeId);
            }
        }

        if ($request->input('created_by') === 'me') {
            $query->where('requester_id', $user->id);
        }

        $query->orderByDesc('id');

        // Paginación segura
        $allowedPerPage = [10, 25, 50, 100, 500];
        $perPage = (int) $request->input('per_page', 10);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 10;
        }

        return $query->paginate($perPage);
    }

    /**
     * Resumen de tickets (cards) segun filtros y permisos actuales.
     */
    public function summary(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        // Mensaje claro si falta area para permisos de area (no aplica a manage_all)
        if ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'tickets')) {
            Log::warning('tickets.view_area sin area_id', ['user_id' => $user->id]);

            return $blocked;
        }

        Gate::authorize('viewAny', Ticket::class);

        $query = Ticket::query();
        $policy = app(\App\Policies\TicketPolicy::class);
        $query = $policy->scopeFor($user, $query);

        $this->applyCatalogFilters($request, $user, $query);

        $assignedTo = $request->input('assigned_to');
        $assignedStatus = $request->input('assigned_status');
        if ($assignedTo === 'me') {
            $query->where('assigned_user_id', $user->id);
        } elseif ($assignedStatus === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif ($request->filled('assigned_user_id')) {
            $assigneeId = (int) $request->input('assigned_user_id');
            $allowed = true;
            if (!$user->can('tickets.manage_all')) {
                $allowed = $this->clientScope->assertUserAccessible($user, $assigneeId)
                    && DB::table('users')
                        ->where('id', $assigneeId)
                        ->where('area_id', $user->area_id)
                        ->exists();
            }
            if ($allowed) {
                $query->where('assigned_user_id', $assigneeId);
            }
        }

        if ($request->input('created_by') === 'me') {
            $query->where('requester_id', $user->id);
        }

        $total = (clone $query)->count();

        $states = TicketState::select('id', 'name', 'code', 'is_final')->get()->keyBy('id');
        $byState = (clone $query)
            ->select('ticket_state_id', DB::raw('count(*) as total'))
            ->groupBy('ticket_state_id')
            ->get()
            ->map(function ($row) use ($states) {
                $state = $states->get($row->ticket_state_id);
                return [
                    'id' => $row->ticket_state_id,
                    'label' => $state?->name ?? 'Sin estado',
                    'code' => $state?->code,
                    'is_final' => (bool) ($state?->is_final ?? false),
                    'value' => (int) $row->total,
                ];
            })
            ->values();

        $finalStateIds = $states->filter(fn ($s) => $s->is_final)->keys();
        $burnedCount = (clone $query)
            ->where('created_at', '<=', now()->subHours(Ticket::SLA_LIMIT_HOURS))
            ->when($finalStateIds->isNotEmpty(), fn ($q) => $q->whereNotIn('ticket_state_id', $finalStateIds))
            ->count();

        $cancelStateId = TicketState::getCancelStateIdOrNull();
        $canceledCount = $cancelStateId
            ? (clone $query)->where('ticket_state_id', $cancelStateId)->count()
            : 0;

        return response()->json([
            'total' => $total,
            'burned' => $burnedCount,
            'canceled' => $canceledCount,
            'by_state' => $byState,
        ]);
    }

    /**
     * Exporta tickets visibles para el usuario en CSV.
     */
    public function export(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        Gate::authorize('viewAny', Ticket::class);

        if (!$user->can('tickets.manage_all')) {
            return response()->json(['message' => 'Solo administradores pueden exportar CSV'], 403);
        }

        $query = Ticket::with([
            'areaOrigin:id,name',
            'areaCurrent:id,name',
            'site:id,name',
            'ticketType:id,name',
            'priority:id,name',
            'state:id,name',
            'assignedUser:id,name',
        ]);

        $policy = app(\App\Policies\TicketPolicy::class);
        $query = $policy->scopeFor($user, $query);

        $this->applyCatalogFilters($request, $user, $query);

        $assignedTo = $request->input('assigned_to');
        $assignedStatus = $request->input('assigned_status');
        if ($assignedTo === 'me') {
            $query->where('assigned_user_id', $user->id);
        } elseif ($assignedStatus === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif ($request->filled('assigned_user_id')) {
            $assigneeId = (int) $request->input('assigned_user_id');
            $allowed = $user->can('tickets.manage_all')
                || ($this->clientScope->assertUserAccessible($user, $assigneeId)
                    && DB::table('users')->where('id', $assigneeId)->where('area_id', $user->area_id)->exists());
            if ($allowed) {
                $query->where('assigned_user_id', $assigneeId);
            }
        }

        if ($request->input('created_by') === 'me') {
            $query->where('requester_id', $user->id);
        }

        $query->orderByDesc('id');

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tickets.csv"',
        ];

        $callback = function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'asunto', 'created_at', 'due_at', 'resolved_at', 'area_origen', 'area_actual', 'sede', 'tipo', 'prioridad', 'estado', 'asignado']);
            $query->chunk(500, function ($tickets) use ($out) {
                foreach ($tickets as $t) {
                    fputcsv($out, [
                        $t->id,
                        $t->subject ?? '',
                        $t->created_at?->toIso8601String() ?? '',
                        $t->due_at?->toIso8601String() ?? '',
                        $t->resolved_at?->toIso8601String() ?? '',
                        $t->areaOrigin->name ?? '',
                        $t->areaCurrent->name ?? '',
                        $t->site->name ?? '',
                        $t->ticketType->name ?? '',
                        $t->priority->name ?? '',
                        $t->state->name ?? '',
                        $t->assignedUser->name ?? '',
                    ]);
                }
            });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function show(Ticket $ticket)
    {
        $user = Auth::user();
        if ($user && ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'tickets'))) {
            Log::warning('tickets.show sin area_id', ['user_id' => $user->id, 'ticket_id' => $ticket->id]);

            return $blocked;
        }
        Gate::authorize('view', $ticket);
        $ticket->load([
            'areaOrigin:id,name',
            'areaCurrent:id,name',
            'site:id,name,client_id',
            'client:id,name',
            'location:id,name,site_id',
            'requester:id,name,email',
            'requesterPosition:id,name',
            'assignedUser:id,name,position_id',
            'assignedUser.position:id,name',
'ticketType:id,name',
                'priority:id,name,level',
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name,code,is_final',
            'histories' => function ($q) {
                $q->orderByDesc('created_at');
                $q->with([
                    'actor:id,name,email',
                    'fromAssignee:id,name,position_id',
                    'toAssignee:id,name,position_id',
                    'fromArea:id,name',
                    'toArea:id,name',
                    'state:id,name,code',
                ]);
            },
            'attachments' => function ($q) {
                $q->orderByDesc('created_at');
                $q->with('uploader:id,name');
            },
        ]);
        if ($user && (int) $user->id === (int) $ticket->requester_id) {
            $ticket->setRelation('histories', $ticket->histories->reject(fn ($h) => $h->action === 'comment' && $h->is_internal)->values());
        }
        return $this->withAbilities($ticket);
    }

    /**
     * Listado de logs de auditoría con filtros (Centro de Mando).
     * Solo usuarios con tickets.manage_all.
     */
    public function indexAuditLogs(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        if (!$user->can('tickets.manage_all')) {
            return response()->json(['message' => 'Solo administradores pueden acceder al centro de auditoría'], 403);
        }

        $query = app(\App\Services\OperatorScopeService::class)
            ->applyOnAuditLogs(
                AuditLog::query()
                    ->where('auditable_type', Ticket::class)
                    ->with('user:id,name,email'),
                $user
            );

        $from = $request->input('from');
        $to = $request->input('to');
        if ($from) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $ticketIds = $request->input('ticket_ids');
        if ($ticketIds !== null && $ticketIds !== '') {
            $ids = array_filter(array_map('intval', explode(',', (string) $ticketIds)));
            if (!empty($ids)) {
                $query->whereIn('auditable_id', $ids);
            }
        }

        $logs = $query->orderByDesc('created_at')->get();

        return response()->json(['data' => $logs]);
    }

    /**
     * Exportar logs de auditoría a Excel (mismos filtros que indexAuditLogs).
     * Solo usuarios con tickets.manage_all.
     *
     * Parámetros: start_date, end_date (YYYY-MM-DD), ticket_ids (opcional, separados por comas).
     */
    public function exportAudit(Request $request): BinaryFileResponse
    {
        $user = Auth::user();
        if (!$user) {
            abort(401, 'No autorizado');
        }
        if (!$user->can('tickets.manage_all')) {
            abort(403, 'Solo administradores pueden exportar auditoría');
        }

        $startDate = $request->input('start_date') ?: $request->input('from');
        $endDate = $request->input('end_date') ?: $request->input('to');
        $ticketIdsRaw = $request->input('ticket_ids');
        $ticketIds = null;
        if ($ticketIdsRaw !== null && $ticketIdsRaw !== '') {
            $ticketIds = array_filter(array_map('intval', explode(',', (string) $ticketIdsRaw)));
        }

        $export = new TicketAuditExport($startDate, $endDate, $ticketIds ?: null, $user);
        $filename = 'auditoria_tickets_' . now()->format('Ymd_His') . '.xlsx';
        $tempName = 'audit_export_' . substr(uniqid('', true), -8) . '.xlsx';
        $path = storage_path('app' . DIRECTORY_SEPARATOR . $tempName);
        $export->exportToPath($path);

        // Limpiar buffer de salida para evitar espacios/saltos que corrompan el binario Excel
        if (ob_get_length() > 0) {
            ob_end_clean();
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Logs de auditoría del ticket (trazabilidad tipo ISO 27001).
     */
    public function audit(Ticket $ticket)
    {
        $user = Auth::user();
        if ($user && ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'tickets'))) {
            Log::warning('tickets.audit sin area_id', ['user_id' => $user->id, 'ticket_id' => $ticket->id]);

            return $blocked;
        }
        Gate::authorize('view', $ticket);

        $logs = $ticket->auditLogs()->with('user:id,name,email')->get();

        return response()->json(['data' => $logs]);
    }

    public function store(StoreTicketRequest $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        Gate::authorize('create', Ticket::class);
        if (!$user->can('tickets.create') && !$user->can('tickets.manage_all')) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validated();

        if ($error = $this->clientScope->stampTicketSiteFromUser($user, $data)) {
            return $error;
        }

        if (!empty($data['impact_level_id']) && !empty($data['urgency_level_id'])) {
            $matrix = PriorityMatrix::where('impact_level_id', $data['impact_level_id'])
                ->where('urgency_level_id', $data['urgency_level_id'])
                ->first();
            if ($matrix) {
                $data['priority_id'] = $matrix->priority_id;
            }
        }
        if (empty($data['priority_id'])) {
            return response()->json(['message' => 'Indica la prioridad o el par Impacto y Urgencia para calcularla.'], 422);
        }

        $data['requester_id'] = $user->id;
        $data['requester_position_id'] = $user->position_id ?? null;
        $clientCreatedAt = Carbon::parse($data['created_at'])->timezone(config('app.timezone'));
        unset($data['created_at']);

        $data['due_at'] = ! empty($data['due_at'])
            ? Carbon::parse($data['due_at'])->timezone(config('app.timezone'))
            : $clientCreatedAt->copy()->addHours(Ticket::SLA_LIMIT_HOURS);

        return DB::transaction(function () use ($data, $user, $clientCreatedAt) {
            // Folio atómico vía TicketSequence::nextFor() — este controlador
            // (creación operativa/interna) tenía el mismo bug histórico que
            // MyTicketsController::store(): nunca asignaba folio.
            $ticket = $this->ticketCreation->create($data, $clientCreatedAt);

            foreach ([$ticket->area_origin_id, $ticket->area_current_id] as $areaId) {
                try {
                    TicketAreaAccess::firstOrCreate(
                        ['ticket_id' => $ticket->id, 'area_id' => $areaId],
                        ['reason' => 'created', 'created_at' => now()]
                    );
                } catch (\Throwable $e) {
                    // fallos silenciosos para no afectar la creacion del ticket
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
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name'
            );
            return response()->json($this->withAbilities($ticket), 201);
        });
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        if ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'tickets')) {
            Log::warning('tickets.update sin area_id', ['user_id' => $user->id, 'ticket_id' => $ticket->id]);

            return $blocked;
        }
        Gate::authorize('update', $ticket);

        $data = $request->validated();

        if (isset($data['site_id'])) {
            if (! $this->clientScope->assertSiteAccessible($user, (int) $data['site_id'])) {
                return response()->json(['message' => 'La sede seleccionada no pertenece a tu cliente'], 422);
            }
            $data['client_id'] = $this->clientScope->syncTicketClientFromSite((int) $data['site_id']);
        }

        if (!empty($data['impact_level_id']) && !empty($data['urgency_level_id'])) {
            $matrix = PriorityMatrix::where('impact_level_id', $data['impact_level_id'])
                ->where('urgency_level_id', $data['urgency_level_id'])
                ->first();
            if ($matrix) {
                $data['priority_id'] = $matrix->priority_id;
            }
        }

        return DB::transaction(function () use ($data, $ticket, $user) {
            $beforeStateId = $ticket->ticket_state_id;
            $beforePriorityId = $ticket->priority_id;
            $beforeAreaId = $ticket->area_current_id;
            $beforeAssigneeId = $ticket->assigned_user_id;
            $fromArea = $ticket->area_current_id;
            $toArea = null;
            $fromAssignee = $ticket->assigned_user_id;
            $didEscalate = false;

            if (isset($data['ticket_state_id'])) {
                Gate::authorize('changeStatus', $ticket);
                $ticket->ticket_state_id = $data['ticket_state_id'];
            }
            if (isset($data['priority_id'])) {
                Gate::authorize('changeStatus', $ticket);
                $ticket->priority_id = $data['priority_id'];
            }
            if (array_key_exists('impact_level_id', $data)) {
                $ticket->impact_level_id = $data['impact_level_id'];
            }
            if (array_key_exists('urgency_level_id', $data)) {
                $ticket->urgency_level_id = $data['urgency_level_id'];
            }
            if (isset($data['area_current_id'])) {
                Gate::authorize('changeArea', $ticket);
                $newArea = $data['area_current_id'];
                if ((int) $newArea !== (int) $ticket->area_current_id) {
                    $ticket->area_current_id = $newArea;
                    $toArea = $newArea;
                    $didEscalate = true;
                    $ticket->assigned_user_id = null;
                    $ticket->assigned_at = null;
                }
            }

            $noteProvided = array_key_exists('note', $data) && $data['note'];
            $noteAllowed = false;
            if ($noteProvided) {
                if (!Gate::allows('comment', $ticket)) {
                    Log::warning('tickets.comment sin permiso', ['user_id' => $user->id, 'ticket_id' => $ticket->id]);
                    abort(403, 'No puede comentar');
                }
                $noteAllowed = true;
            }

            if (array_key_exists('due_at', $data)) {
                $ticket->due_at = $data['due_at'] ? Carbon::parse($data['due_at']) : null;
            }

            if (isset($data['ticket_state_id'])) {
                $newState = TicketState::find($ticket->ticket_state_id);
                $oldState = $beforeStateId ? TicketState::find($beforeStateId) : null;
                if ($newState && $newState->is_final) {
                    $ticket->resolved_at = now();
                } elseif ($oldState && $oldState->is_final && (!$newState || !$newState->is_final)) {
                    $ticket->resolved_at = null;
                }
            }

            $didResolve = false;
            if (isset($data['ticket_state_id']) && (int) $beforeStateId !== (int) $ticket->ticket_state_id) {
                $newState = TicketState::find($ticket->ticket_state_id);
                $didResolve = $newState && $newState->is_final;
            }

            $ticket->save();

            if ($toArea) {
                try {
                    TicketAreaAccess::firstOrCreate(
                        ['ticket_id' => $ticket->id, 'area_id' => $toArea],
                        ['reason' => 'escalated', 'created_at' => now()]
                    );
                } catch (\Throwable $e) {
                    // fallos silenciosos para no afectar la escalacion
                }
            }

            $hasOtherChanges = (int) $beforeStateId !== (int) $ticket->ticket_state_id
                || (int) $beforePriorityId !== (int) $ticket->priority_id
                || (int) $beforeAreaId !== (int) $ticket->area_current_id
                || (int) $beforeAssigneeId !== (int) $ticket->assigned_user_id;
            $action = $didEscalate ? 'escalated' : ($noteAllowed && $noteProvided ? 'comment' : ($hasOtherChanges ? 'state_change' : null));
            $isInternal = ($action === 'comment') ? (bool) ($data['is_internal'] ?? true) : false;

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $user->id,
                'from_area_id' => $fromArea,
                'to_area_id' => $ticket->area_current_id,
                'ticket_state_id' => $ticket->ticket_state_id,
                'note' => $noteAllowed ? ($data['note'] ?? null) : null,
                'is_internal' => $isInternal,
                'action' => $action,
                'from_assignee_id' => $didEscalate ? $fromAssignee : null,
                'to_assignee_id' => $didEscalate ? null : null,
            ]);

            if ($action === 'comment' && !$isInternal && $ticket->first_response_at === null) {
                $ticket->first_response_at = now();
                $ticket->save();
            }

            $changes = [];
            if ((int) $beforeStateId !== (int) $ticket->ticket_state_id) {
                $changes['ticket_state_id'] = ['from' => $beforeStateId, 'to' => $ticket->ticket_state_id];
            }
            if ((int) $beforePriorityId !== (int) $ticket->priority_id) {
                $changes['priority_id'] = ['from' => $beforePriorityId, 'to' => $ticket->priority_id];
            }
            if ((int) $beforeAreaId !== (int) $ticket->area_current_id) {
                $changes['area_current_id'] = ['from' => $beforeAreaId, 'to' => $ticket->area_current_id];
            }
            if ((int) $beforeAssigneeId !== (int) $ticket->assigned_user_id) {
                $changes['assigned_user_id'] = ['from' => $beforeAssigneeId, 'to' => $ticket->assigned_user_id];
            }
            if (!empty($changes)) {
                $this->auditTicketChange($user, $ticket, 'update', $changes, [
                    'note_provided' => (bool) $noteProvided,
                    'note_length' => $noteProvided ? strlen((string) $data['note']) : 0,
                ]);
            }

            if ($didEscalate && $toArea) {
                $this->notifyEscalated($ticket, $user, (int) $toArea);
            }

            if ($didResolve && $ticket->requester_id && (int) $ticket->requester_id !== (int) $user->id) {
                $requester = User::find($ticket->requester_id);
                if ($requester) {
                    $this->safeNotify(
                        $requester,
                        new TicketRequesterResolvedNotification(
                            $ticket->id,
                            "Tu ticket #{$ticket->id} fue marcado como resuelto/cerrado.",
                            $user->id
                        ),
                        $ticket->id,
                        'requester_resolved'
                    );
                }
            }

            $isPublicComment = $noteAllowed && $noteProvided && !($data['is_internal'] ?? true);
            if ($isPublicComment && $ticket->requester_id && (int) $ticket->requester_id !== (int) $user->id) {
                $requester = User::find($ticket->requester_id);
                if ($requester) {
                    $this->safeNotify(
                        $requester,
                        new TicketRequesterCommentNotification(
                            $ticket->id,
                            "Se agregó un comentario al ticket #{$ticket->id}.",
                            $user->id
                        ),
                        $ticket->id,
                        'requester_comment'
                    );
                }
            }

            TicketUpdated::dispatch($ticket);

            $ticket->load(
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name',
                'location:id,name',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'ticketType:id,name',
                'priority:id,name,level',
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name',
                'histories.actor:id,name,email',
                'histories.fromAssignee:id,name,position_id',
                'histories.toAssignee:id,name,position_id',
                'histories.fromArea:id,name',
                'histories.toArea:id,name',
                'histories.state:id,name,code',
            );
            return response()->json($this->withAbilities($ticket));
        });
    }

    public function take(Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        // take() siempre es autoasignación: actor y destino son la misma
        // persona (Fase 5 -- assign() ahora requiere el destino explícito).
        Gate::authorize('assign', [$ticket, $user]);

        if ($ticket->assigned_user_id) {
            return response()->json(['message' => 'Ticket ya asignado'], 409);
        }

        return DB::transaction(function () use ($ticket, $user) {
            // Relee bajo lock de fila: serializa contra otro take()/assign()
            // concurrente sobre el mismo ticket. El chequeo de arriba corre
            // antes de abrir la transacción (evita tomar el lock en el caso
            // común ya-asignado) -- esta relectura es la que realmente cierra
            // la ventana de carrera: antes, dos requests casi simultáneos
            // pasaban ambos ese chequeo y el que guardaba al final ganaba en
            // silencio, con notificación duplicada y el otro agente creyendo
            // que el ticket era suyo sin serlo.
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->assigned_user_id) {
                return response()->json(['message' => 'Ticket ya asignado'], 409);
            }

            $openStateId = TicketState::findIdByCode(TicketState::CODE_OPEN);
            $progressStateId = TicketState::findIdByCode(TicketState::CODE_IN_PROGRESS);
            $beforeStateId = $ticket->ticket_state_id;
            $beforeAssigneeId = $ticket->assigned_user_id;

            $ticket->assigned_user_id = $user->id;
            $ticket->assigned_at = now();

            if ($openStateId && $progressStateId && (int) $ticket->ticket_state_id === (int) $openStateId) {
                $ticket->ticket_state_id = $progressStateId;
            }

            $ticket->save();

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $user->id,
                'ticket_state_id' => $ticket->ticket_state_id,
                'action' => 'assigned',
                'is_internal' => false,
                'from_assignee_id' => null,
                'to_assignee_id' => $user->id,
            ]);

            $changes = [
                'assigned_user_id' => ['from' => $beforeAssigneeId, 'to' => $ticket->assigned_user_id],
            ];
            if ((int) $beforeStateId !== (int) $ticket->ticket_state_id) {
                $changes['ticket_state_id'] = ['from' => $beforeStateId, 'to' => $ticket->ticket_state_id];
            }
            $this->auditTicketChange($user, $ticket, 'take', $changes);

            $this->notifyAssignment($ticket, $user, $user->id, 'assigned');

            $ticket->load(
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name',
                'location:id,name',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'ticketType:id,name',
                'priority:id,name,level',
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name',
                'histories.actor:id,name,email',
                'histories.fromAssignee:id,name,position_id',
                'histories.toAssignee:id,name,position_id',
                'histories.fromArea:id,name',
                'histories.toArea:id,name',
                'histories.state:id,name,code',
            );
            return response()->json($this->withAbilities($ticket));
        });
    }

    /**
     * Tomar/asignar (ticket sin responsable) o reasignar (ticket ya
     * asignado a alguien más) -- mismo endpoint, la ability a autorizar
     * depende del estado ACTUAL del ticket (Fase 5). Los dos obstáculos
     * legacy que vivían aquí (línea "solo el responsable actual puede
     * reasignar" y el chequeo de newUser->area_id === ticket->area_current_id)
     * se quitaron -- ambos ya viven en TicketPolicy::assign()/reassign()
     * (withinStaffSiteScope() para el actor, userLinkedToTicketSite() para
     * el destino), no hay chequeo duplicado aquí.
     */
    public function assign(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        $data = $request->validate([
            'assigned_user_id' => 'required|exists:users,id',
        ]);

        $newUser = User::findOrFail((int) $data['assigned_user_id']);
        $isReassignment = (bool) $ticket->assigned_user_id;

        Gate::authorize($isReassignment ? 'reassign' : 'assign', [$ticket, $newUser]);

        if ((int) $ticket->assigned_user_id === (int) $newUser->id) {
            return response()->json(['message' => 'Ticket ya asignado a ese usuario'], 409);
        }

        return DB::transaction(function () use ($ticket, $user, $newUser, $isReassignment) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ((int) $ticket->assigned_user_id === (int) $newUser->id) {
                return response()->json(['message' => 'Ticket ya asignado a ese usuario'], 409);
            }

            // El estado de asignación cambió entre el Gate::authorize() de
            // arriba (fuera del lock, decide 'assign' vs 'reassign') y esta
            // relectura -- la ability correcta pudo cambiar con él. Más
            // seguro rechazar y forzar reintento que aplicar un cambio que
            // la policy nunca autorizó para el estado real actual.
            if ($isReassignment !== (bool) $ticket->assigned_user_id) {
                return response()->json(['message' => 'El ticket cambió de estado, vuelve a intentarlo'], 409);
            }

            $prevAssignee = $ticket->assigned_user_id;

            $ticket->assigned_user_id = $newUser->id;
            $ticket->assigned_at = now();
            $ticket->save();

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $user->id,
                'ticket_state_id' => $ticket->ticket_state_id,
                'action' => $isReassignment ? 'reassigned' : 'assigned',
                'is_internal' => false,
                'from_assignee_id' => $prevAssignee,
                'to_assignee_id' => $newUser->id,
            ]);

            $this->auditTicketChange($user, $ticket, 'assign', [
                'assigned_user_id' => ['from' => $prevAssignee, 'to' => $newUser->id],
            ]);

            if ($isReassignment) {
                $this->notifyAssignment($ticket, $user, $newUser->id, 'reassigned');
                $this->notifyPreviousAssignee($ticket, $user, $prevAssignee, $newUser->id);
                if ($newUser->email) {
                    try {
                        Mail::to($newUser->email)->queue(new TicketReassignedMail($ticket, $user));
                    } catch (\Throwable $e) {
                        Log::warning('ticket reassigned mail failed', [
                            'ticket_id' => $ticket->id,
                            'user_id' => $newUser->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                $this->notifyAssignment($ticket, $user, $newUser->id, 'assigned');
            }

            $ticket->load(
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name',
                'location:id,name',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'ticketType:id,name',
                'priority:id,name,level',
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name',
                'histories.actor:id,name,email',
                'histories.fromAssignee:id,name,position_id',
                'histories.toAssignee:id,name,position_id',
                'histories.fromArea:id,name',
                'histories.toArea:id,name',
                'histories.state:id,name,code',
            );
            return response()->json($this->withAbilities($ticket));
        });
    }

    public function unassign(Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        Gate::authorize('release', $ticket);

        if (!$ticket->assigned_user_id) {
            return response()->json(['message' => 'Ticket sin responsable'], 409);
        }

        return DB::transaction(function () use ($ticket, $user) {
            $prevAssignee = $ticket->assigned_user_id;

            $ticket->assigned_user_id = null;
            $ticket->assigned_at = null;
            $ticket->save();

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $user->id,
                'ticket_state_id' => $ticket->ticket_state_id,
                'action' => 'unassigned',
                'is_internal' => false,
                'from_assignee_id' => $prevAssignee,
                'to_assignee_id' => null,
                'note' => 'Liberado para otros agentes',
            ]);

            $this->auditTicketChange($user, $ticket, 'unassign', [
                'assigned_user_id' => ['from' => $prevAssignee, 'to' => null],
            ]);

            $ticket->load(
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name',
                'location:id,name',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'ticketType:id,name',
                'priority:id,name,level',
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name',
                'histories.actor:id,name,email',
                'histories.fromAssignee:id,name,position_id',
                'histories.toAssignee:id,name,position_id',
                'histories.fromArea:id,name',
                'histories.toArea:id,name',
                'histories.state:id,name,code',
            );
            return response()->json($this->withAbilities($ticket));
        });
    }

    /**
     * El solicitante envía una alerta (no atendido / ignorado).
     * La notificación llega solo al responsable actual y a supervisores/admins (tickets.manage_all).
     */
    public function sendAlert(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        Gate::authorize('alert', $ticket);

        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
        ]);

        $alert = TicketAlert::create([
            'ticket_id' => $ticket->id,
            'requester_id' => $user->id,
            'message' => $data['message'] ?? null,
        ]);

        $noteText = trim((string) ($data['message'] ?? ''));
        if ($noteText === '') {
            $noteText = 'Alerta del solicitante (sin mensaje adicional).';
        } else {
            $noteText = 'Observación / alerta del solicitante: ' . $noteText;
        }
        TicketHistory::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $user->id,
            'from_area_id' => $ticket->area_current_id,
            'to_area_id' => $ticket->area_current_id,
            'ticket_state_id' => $ticket->ticket_state_id,
            'note' => $noteText,
            'is_internal' => false,
            'action' => 'requester_alert',
            'from_assignee_id' => null,
            'to_assignee_id' => null,
        ]);

        $requesterName = $user->name;
        $message = "El solicitante ha enviado una alerta por el ticket #{$ticket->id}: no atendido o ignorado.";
        if (!empty(trim((string) ($data['message'] ?? '')))) {
            $message .= ' ' . trim($data['message']);
        }

        $recipientIds = collect();
        if ($ticket->assigned_user_id && (int) $ticket->assigned_user_id !== (int) $user->id) {
            $recipientIds->push($ticket->assigned_user_id);
        }
        $manageAllIds = User::permission('tickets.manage_all')->get()
            ->filter(fn (User $u) => app(\App\Services\OperatorScopeService::class)->userInTicketOperatorScope($u, $ticket))
            ->pluck('id');
        $recipientIds = $recipientIds->merge($manageAllIds)
            ->unique()->filter(fn ($id) => (int) $id !== (int) $user->id)->values();

        $notification = new TicketRequesterAlertNotification($ticket->id, $message, $user->id);
        foreach (User::whereIn('id', $recipientIds)->get() as $recipient) {
            $this->safeNotify($recipient, $notification, $ticket->id, 'requester_alert');
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
            'impactLevel:id,name',
            'urgencyLevel:id,name',
            'state:id,name,code',
        ]);
        return response()->json([
            'alert' => $alert,
            'ticket' => $this->withAbilities($ticket),
        ], 201);
    }

    /**
     * El solicitante cancela su ticket (solo si no está resuelto).
     */
    public function cancel(Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        Gate::authorize('cancel', $ticket);

        try {
            $cancelStateId = TicketState::getCancelStateId();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DB::transaction(function () use ($ticket, $user, $cancelStateId) {
            $beforeStateId = $ticket->ticket_state_id;
            $ticket->ticket_state_id = $cancelStateId;
            $ticket->resolved_at = now();
            $ticket->save();

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $user->id,
                'from_area_id' => $ticket->area_current_id,
                'to_area_id' => $ticket->area_current_id,
                'ticket_state_id' => $cancelStateId,
                'note' => 'Ticket cancelado por el solicitante',
                'is_internal' => false,
                'action' => 'state_change',
            ]);

            $this->auditTicketChange($user, $ticket, 'cancel', [
                'ticket_state_id' => ['from' => $beforeStateId, 'to' => $cancelStateId],
            ]);

            $ticket->load([
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name',
                'location:id,name',
                'requester:id,name,email',
                'assignedUser:id,name,position_id',
                'ticketType:id,name',
                'priority:id,name,level',
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name,code',
                'histories' => function ($q) {
                    $q->orderByDesc('created_at');
                    $q->with(['actor:id,name,email', 'state:id,name,code']);
                },
            ]);
            return response()->json($this->withAbilities($ticket));
        });
    }

    public function escalate(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        Gate::authorize('escalate', $ticket);

        $data = $request->validate([
            'area_destino_id' => 'required|exists:areas,id',
            'note' => 'nullable|string|max:1000',
        ]);

        $noteProvided = array_key_exists('note', $data) && $data['note'];
        if ($noteProvided && !Gate::allows('comment', $ticket)) {
            Log::warning('tickets.comment sin permiso', ['user_id' => $user->id, 'ticket_id' => $ticket->id]);
            abort(403, 'No puede comentar');
        }

        $newArea = (int) $data['area_destino_id'];
        if ($newArea === (int) $ticket->area_current_id) {
            return response()->json(['message' => 'Area destino igual a area actual'], 409);
        }

        return DB::transaction(function () use ($ticket, $user, $newArea, $data) {
            $fromArea = $ticket->area_current_id;
            $fromAssignee = $ticket->assigned_user_id;

            $ticket->area_current_id = $newArea;
            $ticket->assigned_user_id = null;
            $ticket->assigned_at = null;
            $ticket->save();

            try {
                TicketAreaAccess::firstOrCreate(
                    ['ticket_id' => $ticket->id, 'area_id' => $newArea],
                    ['reason' => 'escalated', 'created_at' => now()]
                );
            } catch (\Throwable $e) {
                // fallos silenciosos para no afectar la escalacion
            }

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $user->id,
                'from_area_id' => $fromArea,
                'to_area_id' => $newArea,
                'ticket_state_id' => $ticket->ticket_state_id,
                'note' => $data['note'] ?? null,
                'is_internal' => false,
                'action' => 'escalated',
                'from_assignee_id' => $fromAssignee,
                'to_assignee_id' => null,
            ]);

            $this->auditTicketChange($user, $ticket, 'escalate', [
                'area_current_id' => ['from' => $fromArea, 'to' => $newArea],
                'assigned_user_id' => ['from' => $fromAssignee, 'to' => null],
            ], [
                'note_provided' => !empty($data['note']),
                'note_length' => !empty($data['note']) ? strlen((string) $data['note']) : 0,
            ]);

            $this->notifyEscalated($ticket, $user, $newArea);

            $ticket->load(
                'areaOrigin:id,name',
                'areaCurrent:id,name',
                'site:id,name',
                'location:id,name',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'ticketType:id,name',
                'priority:id,name,level',
                'impactLevel:id,name',
                'urgencyLevel:id,name',
                'state:id,name',
                'histories.actor:id,name,email',
                'histories.fromAssignee:id,name,position_id',
                'histories.toAssignee:id,name,position_id',
                'histories.fromArea:id,name',
                'histories.toArea:id,name',
                'histories.state:id,name,code',
            );
            return response()->json($this->withAbilities($ticket));
        });
    }

    protected function hasTicketPermission(User $user): bool
    {
        return $user->hasAnyPermission([
            'tickets.create',
            'tickets.view_own',
            'tickets.view_area',
            'tickets.filter_by_site',
            'tickets.assign',
            'tickets.comment',
            'tickets.change_status',
            'tickets.escalate',
            'tickets.manage_all',
        ]);
    }

    protected function applyCatalogFilters(Request $request, User $user, $query): void
    {
        $this->clientScope->applyClientFilter($request, $user, $query);

        $filters = [
            'area_current_id' => 'area_current_id',
            'area_origin_id' => 'area_origin_id',
            'location_id' => 'location_id',
            'ticket_type_id' => 'ticket_type_id',
            'priority_id' => 'priority_id',
            'ticket_state_id' => 'ticket_state_id',
        ];

        foreach ($filters as $param => $column) {
            if ($request->filled($param)) {
                $query->where($column, $request->input($param));
            }
        }

        if ($request->filled('site_id')) {
            $canFilterSite = $user->can('tickets.filter_by_site')
                || $user->can('tickets.manage_all')
                || $user->can('tickets.view_area');
            if ($canFilterSite) {
                $this->clientScope->applySiteFilter($request, $user, $query);
            }
        }

        if ($request->filled('date_from')) {
            try {
                $from = Carbon::parse($request->input('date_from'))->startOfDay();
                $query->where('created_at', '>=', $from);
            } catch (\Throwable $e) {
                // ignore invalid date_from
            }
        }

        if ($request->filled('date_to')) {
            try {
                $to = Carbon::parse($request->input('date_to'))->endOfDay();
                $query->where('created_at', '<=', $to);
            } catch (\Throwable $e) {
                // ignore invalid date_to
            }
        }

        $search = $request->input('search') ?? $request->input('q');
        if (is_string($search) && trim($search) !== '') {
            $term = '%' . preg_replace('/%/', '\\%', trim(mb_substr($search, 0, 200))) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)->orWhere('description', 'like', $term);
            });
        }

        $slaFilter = $request->input('sla');
        $slaDeadline = now()->subHours(Ticket::SLA_LIMIT_HOURS);
        if ($slaFilter === 'overdue') {
            $query->whereNull('resolved_at')->where(function ($q) use ($slaDeadline) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('due_at')->where('due_at', '<', now());
                })->orWhere(function ($q2) use ($slaDeadline) {
                    $q2->whereNull('due_at')->where('created_at', '<', $slaDeadline);
                });
            });
        } elseif ($slaFilter === 'within') {
            $query->whereNull('resolved_at')->where(function ($q) use ($slaDeadline) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('due_at')->where('due_at', '>=', now());
                })->orWhere(function ($q2) use ($slaDeadline) {
                    $q2->whereNull('due_at')->where('created_at', '>=', $slaDeadline);
                });
            });
        }
    }

    protected function notifyAssignment(Ticket $ticket, User $actor, int $assigneeId, string $action): void
    {
        $recipientIds = collect([$assigneeId, $ticket->requester_id])
            ->filter()
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $recipientIds)->get();
        foreach ($users as $u) {
            if (!$this->hasTicketPermission($u)) {
                continue;
            }

            $isAssignee = (int) $u->id === (int) $assigneeId;
            $message = $isAssignee
                ? "Ticket #{$ticket->id} " . ($action === 'assigned' ? 'asignado a ti' : 'reasignado a ti')
                : "Tu ticket #{$ticket->id} fue " . ($action === 'assigned' ? 'asignado' : 'reasignado');

            $notification = $action === 'assigned'
                ? new TicketAssignedNotification($ticket->id, $message, $actor->id)
                : new TicketReassignedNotification($ticket->id, $message, $actor->id);

            $this->safeNotify($u, $notification, $ticket->id, $action);
        }
    }

    /**
     * Al responsable ANTERIOR de un ticket reasignado: solo in-app, sin
     * correo -- decisión de Fase 5. notifyAssignment() (arriba) ya cubre al
     * nuevo responsable y al solicitante; este método cubre al único
     * destinatario que notifyAssignment() no contempla, porque nunca
     * conoce al responsable anterior (solo recibe el nuevo assigneeId).
     */
    protected function notifyPreviousAssignee(Ticket $ticket, User $actor, ?int $prevAssigneeId, int $newAssigneeId): void
    {
        if (! $prevAssigneeId || (int) $prevAssigneeId === $newAssigneeId) {
            return;
        }

        $prevUser = User::find($prevAssigneeId);
        if (! $prevUser || ! $this->hasTicketPermission($prevUser)) {
            return;
        }

        $message = "Tu ticket #{$ticket->id} ya no está asignado a ti (reasignado a otro responsable)";
        $this->safeNotify(
            $prevUser,
            new TicketReassignedNotification($ticket->id, $message, $actor->id),
            $ticket->id,
            'reassigned'
        );
    }

    protected function notifyEscalated(Ticket $ticket, User $actor, int $areaId): void
    {
        $recipients = User::permission('tickets.view_area')
            ->where('area_id', $areaId)
            ->get();

        $requester = $ticket->requester_id ? User::find($ticket->requester_id) : null;
        if ($requester && $recipients->where('id', $requester->id)->isEmpty()) {
            $recipients->push($requester);
        }

        $seen = [];
        foreach ($recipients as $u) {
            if (in_array($u->id, $seen, true)) {
                continue;
            }
            $seen[] = $u->id;

            if (!$this->hasTicketPermission($u)) {
                continue;
            }

            $isRequester = (int) $u->id === (int) $ticket->requester_id;
            $message = $isRequester
                ? "Tu ticket #{$ticket->id} fue escalado"
                : "Ticket #{$ticket->id} escalado a tu area";

            $notification = new TicketEscalatedNotification($ticket->id, $message, $actor->id);
            $this->safeNotify($u, $notification, $ticket->id, 'escalated');
        }
    }

    protected function safeNotify(User $user, $notification, int $ticketId, string $action): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('ticket notification failed', [
                'user_id' => $user->id,
                'ticket_id' => $ticketId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * assign()/reassign() ya no aceptan solo el ticket -- necesitan un
     * destino ($newUser) desde Fase 5 (TicketPolicy::canAssignTo()). Aquí
     * todavía no hay un destino elegido (el picker de agente vive en el
     * frontend, después de este flag), así que se usa al propio actor como
     * candidato placeholder: refleja "¿tiene sentido mostrarme la acción
     * en este ticket?" (alcance del actor), no "¿puedo asignarlo a
     * cualquiera?" -- esa segunda validación (destino con site_user en el
     * site del ticket) se aplica de verdad recién en el submit real, con
     * Gate::authorize() en assign()/unassign() de este mismo controlador.
     */
    protected function withAbilities(Ticket $ticket): Ticket
    {
        $user = Auth::user();

        $ticket->setAttribute('abilities', [
            'assign' => $user ? Gate::allows('assign', [$ticket, $user]) : false,
            'reassign' => $user ? Gate::allows('reassign', [$ticket, $user]) : false,
            'release' => Gate::allows('release', $ticket),
            'escalate' => Gate::allows('escalate', $ticket),
            'comment' => Gate::allows('comment', $ticket),
            'change_status' => Gate::allows('changeStatus', $ticket),
            'change_area' => Gate::allows('changeArea', $ticket),
            'alert' => Gate::allows('alert', $ticket),
            'cancel' => Gate::allows('cancel', $ticket),
        ]);

        return $ticket;
    }

    protected function auditTicketChange(?User $actor, Ticket $ticket, string $action, array $changes, array $meta = []): void
    {
        if (!config('helpdesk.tickets.audit_enabled', false)) {
            return;
        }

        try {
            $channel = config('helpdesk.tickets.audit_channel', 'audit');
            Log::channel($channel)->info('ticket.audit', [
                'actor_id' => $actor?->id,
                'ticket_id' => $ticket->id,
                'action' => $action,
                'changes' => $changes,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ticket audit failed', ['error' => $e->getMessage()]);
        }
    }
}
