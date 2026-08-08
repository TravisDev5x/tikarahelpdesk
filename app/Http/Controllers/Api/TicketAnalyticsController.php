<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketState;
use App\Models\Area;
use App\Models\TicketType;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use App\Services\ClientScopeService;
use App\Services\TicketQueryFilterService;
use App\Support\Database\SqlDialect;

class TicketAnalyticsController extends Controller
{
    public function __construct(
        protected ClientScopeService $clientScope,
        protected TicketQueryFilterService $filters
    ) {}

    public function __invoke(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        Gate::authorize('viewAny', Ticket::class);

        $cacheKey = 'tickets.analytics.' . $user->id . '.' . md5($request->fullUrl());

        $payload = function () use ($request, $user) {
            /** @var TicketPolicy $policy */
            $policy = app(TicketPolicy::class);
            $base = $policy->scopeFor($user, Ticket::query());
            $this->filters->apply($request, $user, $base);

            if ($request->input('assigned_to') === 'me') {
                $base->where('assigned_user_id', $user->id);
            }
            if ($request->input('created_by') === 'me') {
                $base->where('requester_id', $user->id);
            }

            $ticketIds = (clone $base)->pluck('id');

            $finalStateIds = TicketState::where('is_final', true)->pluck('id');
            $hasFinalStates = $finalStateIds->isNotEmpty();

            // Tickets por estado
            $statesMap = TicketState::pluck('name', 'id');
            $byState = (clone $base)
                ->select('ticket_state_id', DB::raw('count(*) as total'))
                ->groupBy('ticket_state_id')
                ->get()
                ->map(function ($row) use ($statesMap) {
                    return [
                        'label' => $statesMap[$row->ticket_state_id] ?? 'Sin estado',
                        'value' => (int) $row->total,
                    ];
                })
                ->values();

            // Tickets quemados
            $burnedCount = (clone $base)
                ->where('created_at', '<=', now()->subHours(Ticket::SLA_LIMIT_HOURS))
                ->when($hasFinalStates, fn($q) => $q->whereNotIn('ticket_state_id', $finalStateIds))
                ->count();

            // Areas que mas reciben (origen)
            $areasMap = Area::pluck('name', 'id');
            $areasReceive = (clone $base)
                ->select('area_origin_id', DB::raw('count(*) as total'))
                ->groupBy('area_origin_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn($r) => ['label' => $areasMap[$r->area_origin_id] ?? '-', 'value' => (int) $r->total]);

            // Areas que mas resuelven (estado cerrado)
            $areasResolve = (clone $base)
                ->when($hasFinalStates, fn($q) => $q->whereIn('ticket_state_id', $finalStateIds))
                ->select('area_current_id', DB::raw('count(*) as total'))
                ->groupBy('area_current_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn($r) => ['label' => $areasMap[$r->area_current_id] ?? '-', 'value' => (int) $r->total]);

            // Usuarios que mas cierran (historial con estado cerrado)
            $resolvers = $this->filters
                ->topResolvers($ticketIds, $finalStateIds, 5)
                ->map(fn ($r) => ['label' => $r['name'], 'value' => $r['total']]);

            // Tipos mas frecuentes (creacion)
            $typesMap = TicketType::pluck('name', 'id');
            $typesFrequent = (clone $base)
                ->select('ticket_type_id', DB::raw('count(*) as total'))
                ->groupBy('ticket_type_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn($r) => ['label' => $typesMap[$r->ticket_type_id] ?? '-', 'value' => (int) $r->total]);

            // Tipos mas resueltos (cerrados)
            $typesResolved = (clone $base)
                ->when($hasFinalStates, fn($q) => $q->whereIn('ticket_state_id', $finalStateIds))
                ->select('ticket_type_id', DB::raw('count(*) as total'))
                ->groupBy('ticket_type_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn($r) => ['label' => $typesMap[$r->ticket_type_id] ?? '-', 'value' => (int) $r->total]);

            // Usuarios que más tickets reportan (top requesters) en el alcance actual
            $topRequesters = (clone $base)
                ->select('requester_id', DB::raw('count(*) as total'))
                ->whereNotNull('requester_id')
                ->groupBy('requester_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get();
            $requesterIds = $topRequesters->pluck('requester_id')->unique()->filter()->values()->all();
            $requesterNames = $requesterIds ? User::whereIn('id', $requesterIds)->pluck('name', 'id')->all() : [];
            $topRequesters = $topRequesters->map(fn($r) => [
                'label' => $requesterNames[$r->requester_id] ?? 'Usuario #' . $r->requester_id,
                'value' => (int) $r->total,
            ]);

            // Tiempo promedio de resolución (horas), solo tickets ya resueltos
            $avgResolutionHours = null;
            if ($hasFinalStates) {
                $avg = (clone $base)
                    ->whereNotNull('resolved_at')
                    ->whereIn('ticket_state_id', $finalStateIds)
                    ->selectRaw(SqlDialect::avgHoursBetween('created_at', 'resolved_at').' as avg_hours')
                    ->value('avg_hours');
                $avgResolutionHours = $avg !== null ? round((float) $avg, 1) : null;
            }

            return [
                'states' => $byState,
                'burned' => $burnedCount,
                'areas_receive' => $areasReceive,
                'areas_resolve' => $areasResolve,
                'top_resolvers' => $resolvers,
                'types_frequent' => $typesFrequent,
                'types_resolved' => $typesResolved,
                'top_requesters' => $topRequesters,
                'avg_resolution_hours' => $avgResolutionHours,
            ];
        };

        $payload = $payload();
        $totalTickets = collect($payload['states'])->sum('value');
        // No cachear resultados vacíos: así al cambiar/limpiar filtros se recalculan las métricas
        if ($totalTickets > 0) {
            Cache::put($cacheKey, $payload, now()->addSeconds(60));
        }

        return response()->json($payload);
    }
}
