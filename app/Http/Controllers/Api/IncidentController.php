<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\ClientScopeService;
use App\Models\IncidentHistory;
use App\Models\IncidentStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class IncidentController extends Controller
{
    public function __construct(
        protected ClientScopeService $clientScope
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        if ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'incidents')) {
            Log::warning('incidents.view_area sin area_id', ['user_id' => $user->id]);

            return $blocked;
        }

        Gate::authorize('viewAny', Incident::class);

        $query = Incident::with([
            'area:id,name',
            'site:id,name',
            'reporter:id,name,email',
            'involvedUser:id,name,email',
            'assignedUser:id,name,position_id',
            'assignedUser.position:id,name',
            'incidentType:id,name',
            'incidentSeverity:id,name,level',
            'incidentStatus:id,name,code,is_final',
        ]);

        $policy = app(\App\Policies\IncidentPolicy::class);
        $query = $policy->scopeFor($user, $query);
        $this->clientScope->applyClientFilter($request, $user, $query);

        $this->applyFilters($request, $user, $query);

        $assignedTo = $request->input('assigned_to');
        $assignedStatus = $request->input('assigned_status');
        if ($assignedTo === 'me') {
            $query->where('assigned_user_id', $user->id);
        } elseif ($assignedStatus === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif ($request->filled('assigned_user_id')) {
            $assigneeId = (int) $request->input('assigned_user_id');
            $allowed = true;
            if (!$user->can('incidents.manage_all')) {
                $allowed = DB::table('users')
                    ->where('id', $assigneeId)
                    ->where('area_id', $user->area_id)
                    ->exists();
            }
            if ($allowed) {
                $query->where('assigned_user_id', $assigneeId);
            }
        }

        $query->orderByDesc('id');

        $allowedPerPage = [10, 25, 50, 100];
        $perPage = (int) $request->input('per_page', 10);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 10;
        }

        return $query->paginate($perPage);
    }

    public function show(Incident $incident)
    {
        $user = Auth::user();
        if ($user && ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'incidents'))) {
            Log::warning('incidents.show sin area_id', ['user_id' => $user->id, 'incident_id' => $incident->id]);

            return $blocked;
        }
        Gate::authorize('view', $incident);

        $incident->load([
            'area:id,name',
            'site:id,name',
            'reporter:id,name,email',
            'involvedUser:id,name,email',
            'assignedUser:id,name,position_id',
            'assignedUser.position:id,name',
            'incidentType:id,name',
            'incidentSeverity:id,name,level',
            'incidentStatus:id,name,code,is_final',
            'attachments' => function ($q) {
                $q->orderByDesc('created_at');
            },
            'histories' => function ($q) {
                $q->orderByDesc('created_at');
                $q->with([
                    'actor:id,name,email',
                    'fromStatus:id,name,code',
                    'toStatus:id,name,code',
                    'fromAssignee:id,name,position_id',
                    'toAssignee:id,name,position_id',
                ]);
            },
        ]);

        return $this->withAbilities($incident);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        Gate::authorize('create', Incident::class);
        if (!$user->can('incidents.create') && !$user->can('incidents.manage_all')) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'occurred_at' => 'nullable|date',
            'enabled_at' => 'required|date',
            'area_id' => 'required|exists:areas,id',
            'site_id' => 'required|exists:sites,id',
            'incident_type_id' => 'required|exists:incident_types,id',
            'incident_severity_id' => 'required|exists:incident_severities,id',
            'incident_status_id' => 'required|exists:incident_statuses,id',
            'involved_user_id' => 'nullable|exists:users,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'attachments' => 'sometimes|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $data['reporter_id'] = $user->id;

        if (! $this->clientScope->assertSiteAccessible($user, (int) $data['site_id'])) {
            return response()->json(['message' => 'La sede seleccionada no está disponible para tu organización.'], 422);
        }

        if (isset($data['assigned_user_id']) && !$user->can('incidents.assign') && !$user->can('incidents.manage_all')) {
            unset($data['assigned_user_id']);
        }

        $isFinal = IncidentStatus::where('id', $data['incident_status_id'])->value('is_final');
        if ($isFinal) {
            $data['closed_at'] = now();
        }

        return DB::transaction(function () use ($data, $request, $user) {
            $incident = Incident::create($data);

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store("incidents/{$incident->id}", ['disk' => 'public']);
                $incident->attachments()->create([
                    'uploaded_by' => $user->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_name' => basename($path),
                    'file_path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'disk' => 'public',
                ]);
            }

            IncidentHistory::create([
                'incident_id' => $incident->id,
                'actor_id' => $user->id,
                'action' => 'created',
                'from_status_id' => null,
                'to_status_id' => $incident->incident_status_id,
                'from_assigned_user_id' => null,
                'to_assigned_user_id' => $incident->assigned_user_id,
                'note' => 'Creacion de incidencia',
                'created_at' => $incident->created_at,
            ]);

            $incident->load(
                'area:id,name',
                'site:id,name',
                'reporter:id,name,email',
                'involvedUser:id,name,email',
                'assignedUser:id,name,position_id',
                'incidentType:id,name',
                'incidentSeverity:id,name,level',
                'incidentStatus:id,name,code,is_final',
                'attachments'
            );

            return response()->json($this->withAbilities($incident), 201);
        });
    }

    public function update(Request $request, Incident $incident)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        if ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'incidents')) {
            Log::warning('incidents.update sin area_id', ['user_id' => $user->id, 'incident_id' => $incident->id]);

            return $blocked;
        }
        Gate::authorize('update', $incident);

        $data = $request->validate([
            'incident_status_id' => 'nullable|exists:incident_statuses,id',
            'incident_severity_id' => 'nullable|exists:incident_severities,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'enabled_at' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
        ]);

        if (array_key_exists('assigned_user_id', $data) && $data['assigned_user_id']) {
            $newUser = User::findOrFail((int) $data['assigned_user_id']);
            if (!$user->can('incidents.manage_all')) {
                if (!$newUser->area_id || (int) $newUser->area_id !== (int) $incident->area_id) {
                    return response()->json(['message' => 'Responsable fuera del area actual'], 422);
                }
            }
        }

        return DB::transaction(function () use ($data, $incident, $user) {
            $fromStatus = $incident->incident_status_id;
            $toStatus = $fromStatus;
            $fromAssignee = $incident->assigned_user_id;
            $toAssignee = $fromAssignee;
            $action = null;
            $severityChanged = false;

            if (isset($data['incident_status_id'])) {
                Gate::authorize('changeStatus', $incident);
                $incident->incident_status_id = $data['incident_status_id'];
                $toStatus = $data['incident_status_id'];
                $action = 'status_changed';
            }

            if (isset($data['incident_severity_id'])) {
                Gate::authorize('changeStatus', $incident);
                $incident->incident_severity_id = $data['incident_severity_id'];
                $severityChanged = true;
                if (!$action) {
                    $action = 'severity_changed';
                }
            }

            if (isset($data['enabled_at'])) {
                $incident->enabled_at = $data['enabled_at'];
            }

            if (array_key_exists('assigned_user_id', $data)) {
                Gate::authorize('assign', $incident);
                $incident->assigned_user_id = $data['assigned_user_id'];
                $toAssignee = $data['assigned_user_id'];
                if ($fromAssignee && $toAssignee) {
                    $action = 'reassigned';
                } elseif ($toAssignee) {
                    $action = 'assigned';
                } else {
                    $action = 'unassigned';
                }
            }

            $noteProvided = array_key_exists('note', $data) && $data['note'];
            if ($noteProvided && !Gate::allows('comment', $incident)) {
                Log::warning('incidents.comment sin permiso', ['user_id' => $user->id, 'incident_id' => $incident->id]);
                abort(403, 'No puede comentar');
            }

            if (isset($data['incident_status_id'])) {
                $isFinal = IncidentStatus::where('id', $data['incident_status_id'])->value('is_final');
                $incident->closed_at = $isFinal ? now() : null;
            }

            $incident->save();

            $shouldLog = $noteProvided
                || $fromStatus !== $toStatus
                || $fromAssignee !== $toAssignee
                || $severityChanged;

            if ($shouldLog) {
                IncidentHistory::create([
                    'incident_id' => $incident->id,
                    'actor_id' => $user->id,
                    'action' => $action,
                    'from_status_id' => $fromStatus,
                    'to_status_id' => $toStatus,
                    'from_assigned_user_id' => $fromAssignee,
                    'to_assigned_user_id' => $toAssignee,
                    'note' => $noteProvided ? $data['note'] : null,
                ]);
            }

            $incident->load(
                'area:id,name',
                'site:id,name',
                'reporter:id,name,email',
                'involvedUser:id,name,email',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'incidentType:id,name',
                'incidentSeverity:id,name,level',
                'incidentStatus:id,name,code,is_final',
                'attachments',
                'histories.actor:id,name,email',
                'histories.fromStatus:id,name,code',
                'histories.toStatus:id,name,code',
                'histories.fromAssignee:id,name,position_id',
                'histories.toAssignee:id,name,position_id'
            );

            return response()->json($this->withAbilities($incident));
        });
    }

    public function take(Incident $incident)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        Gate::authorize('assign', $incident);

        if ($incident->assigned_user_id) {
            return response()->json(['message' => 'Incidencia ya asignada'], 409);
        }

        return DB::transaction(function () use ($incident, $user) {
            // Relee bajo lock de fila -- mismo fix que TicketController::take():
            // cierra la ventana de carrera entre dos take() casi simultáneos
            // sobre la misma incidencia.
            $incident = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($incident->assigned_user_id) {
                return response()->json(['message' => 'Incidencia ya asignada'], 409);
            }

            $incident->assigned_user_id = $user->id;
            $incident->save();

            IncidentHistory::create([
                'incident_id' => $incident->id,
                'actor_id' => $user->id,
                'action' => 'assigned',
                'from_status_id' => $incident->incident_status_id,
                'to_status_id' => $incident->incident_status_id,
                'from_assigned_user_id' => null,
                'to_assigned_user_id' => $user->id,
            ]);

            $incident->load(
                'area:id,name',
                'site:id,name',
                'reporter:id,name,email',
                'involvedUser:id,name,email',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'incidentType:id,name',
                'incidentSeverity:id,name,level',
                'incidentStatus:id,name,code,is_final'
            );
            return response()->json($this->withAbilities($incident));
        });
    }

    public function assign(Request $request, Incident $incident)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        Gate::authorize('assign', $incident);

        $data = $request->validate([
            'assigned_user_id' => 'required|exists:users,id',
        ]);

        $newUser = User::findOrFail((int) $data['assigned_user_id']);

        if (!$user->can('incidents.manage_all')) {
            if (!$newUser->area_id || (int) $newUser->area_id !== (int) $incident->area_id) {
                return response()->json(['message' => 'Responsable fuera del area actual'], 422);
            }
        }

        if ((int) $incident->assigned_user_id === (int) $newUser->id) {
            return response()->json(['message' => 'Incidencia ya asignada a ese usuario'], 409);
        }

        return DB::transaction(function () use ($incident, $user, $newUser) {
            $incident = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if ((int) $incident->assigned_user_id === (int) $newUser->id) {
                return response()->json(['message' => 'Incidencia ya asignada a ese usuario'], 409);
            }

            $prevAssignee = $incident->assigned_user_id;

            $incident->assigned_user_id = $newUser->id;
            $incident->save();

            IncidentHistory::create([
                'incident_id' => $incident->id,
                'actor_id' => $user->id,
                'action' => $prevAssignee ? 'reassigned' : 'assigned',
                'from_status_id' => $incident->incident_status_id,
                'to_status_id' => $incident->incident_status_id,
                'from_assigned_user_id' => $prevAssignee,
                'to_assigned_user_id' => $newUser->id,
            ]);

            $incident->load(
                'area:id,name',
                'site:id,name',
                'reporter:id,name,email',
                'involvedUser:id,name,email',
                'assignedUser:id,name,position_id',
                'assignedUser.position:id,name',
                'incidentType:id,name',
                'incidentSeverity:id,name,level',
                'incidentStatus:id,name,code,is_final'
            );

            return response()->json($this->withAbilities($incident));
        });
    }

    public function unassign(Incident $incident)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);

        Gate::authorize('assign', $incident);

        if (!$incident->assigned_user_id) {
            return response()->json(['message' => 'Incidencia sin responsable'], 409);
        }

        return DB::transaction(function () use ($incident, $user) {
            $prevAssignee = $incident->assigned_user_id;

            $incident->assigned_user_id = null;
            $incident->save();

            IncidentHistory::create([
                'incident_id' => $incident->id,
                'actor_id' => $user->id,
                'action' => 'unassigned',
                'from_status_id' => $incident->incident_status_id,
                'to_status_id' => $incident->incident_status_id,
                'from_assigned_user_id' => $prevAssignee,
                'to_assigned_user_id' => null,
            ]);

            $incident->load(
                'area:id,name',
                'site:id,name',
                'reporter:id,name,email',
                'involvedUser:id,name,email',
                'assignedUser:id,name,position_id',
                'incidentType:id,name',
                'incidentSeverity:id,name,level',
                'incidentStatus:id,name,code,is_final'
            );
            return response()->json($this->withAbilities($incident));
        });
    }

    protected function applyFilters(Request $request, User $user, $query): void
    {
        $filters = [
            'area_id' => 'area_id',
            'site_id' => 'site_id',
            'incident_type_id' => 'incident_type_id',
            'incident_severity_id' => 'incident_severity_id',
            'incident_status_id' => 'incident_status_id',
            'reporter_id' => 'reporter_id',
            'involved_user_id' => 'involved_user_id',
        ];

        foreach ($filters as $param => $column) {
            if ($request->filled($param)) {
                if ($param === 'site_id' && !$user->can('incidents.filter_by_site') && !$user->can('incidents.manage_all')) {
                    continue;
                }
                $query->where($column, $request->input($param));
            }
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
                if (is_numeric($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
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

        if ($request->filled('occurred_from')) {
            try {
                $from = Carbon::parse($request->input('occurred_from'))->startOfDay();
                $query->where('occurred_at', '>=', $from);
            } catch (\Throwable $e) {
                // ignore invalid occurred_from
            }
        }

        if ($request->filled('occurred_to')) {
            try {
                $to = Carbon::parse($request->input('occurred_to'))->endOfDay();
                $query->where('occurred_at', '<=', $to);
            } catch (\Throwable $e) {
                // ignore invalid occurred_to
            }
        }
    }

    protected function withAbilities(Incident $incident): Incident
    {
        $incident->setAttribute('abilities', [
            'assign' => Gate::allows('assign', $incident),
            'comment' => Gate::allows('comment', $incident),
            'change_status' => Gate::allows('changeStatus', $incident),
        ]);

        return $incident;
    }
}
