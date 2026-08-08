<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketState;
use App\Models\TicketType;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Services\ClientScopeService;
use App\Services\TicketQueryFilterService;
use App\Support\Database\SqlDialect;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard operativo RESOLBEB: KPIs, balance de carga, tendencia y tickets críticos.
 * Filtros: assigned_user_id (agente), site_id (sede).
 */
class ResolbebController extends Controller
{
    public function __construct(
        protected ClientScopeService $clientScope,
        protected TicketQueryFilterService $filters
    ) {}

    /**
     * Dashboard operativo: KPIs, balance de carga, tendencia, top incidentes y top 5 críticos.
     * Filtros opcionales: site_id, assigned_user_id.
     */
    public function dashboardOperativo(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        if ($blocked = $this->clientScope->guardOperationalModuleAccess($user, 'tickets')) {
            return $blocked;
        }

        Gate::authorize('viewAny', Ticket::class);

        $policy = app(TicketPolicy::class);
        $base = $policy->scopeFor($user, Ticket::query());
        $this->filters->apply($request, $user, $base);

        $cacheKey = 'dashboard.'.$user->id.'.'.md5(json_encode($request->query()));

        $result = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($base) {
            return $this->buildDashboardPayload($base);
        });

        return response()->json($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(Builder $base): array
    {
        $stateIds = $this->cachedTicketStateIds();
        $openIds = $stateIds['open'];
        $finalStateIds = $stateIds['final'];
        $frozenIds = $stateIds['frozen'];
        $resueltoCerradoIds = $stateIds['resuelto_cerrado'];
        $abiertoId = $stateIds['abierto'];

        $baseNoFinal = (clone $base)->whereNotIn('ticket_state_id', $finalStateIds);
        $ticketSubquery = (clone $base)->select('id');
        $hasScopedTickets = (clone $base)->exists();

        // --- KPIs ---
        $vencidosHoy = (clone $baseNoFinal)
            ->where(function ($q) {
                $q->where(function ($q1) {
                    $q1->whereNotNull('due_at')->where('due_at', '<=', now());
                })->orWhere(function ($q2) {
                    $q2->whereNull('due_at')
                        ->whereRaw(SqlDialect::createdAtPlusHoursLte('created_at', Ticket::SLA_LIMIT_HOURS), [now()]);
                });
            })
            ->count();

        $sinAsignar = (clone $baseNoFinal)->whereNull('assigned_user_id')->count();

        $congelados = 0;
        if ($frozenIds->isNotEmpty()) {
            $congelados = (clone $baseNoFinal)
                ->whereIn('ticket_state_id', $frozenIds)
                ->where('updated_at', '<=', now()->subHours(48))
                ->count();
        }

        $semanaInicio = Carbon::now()->startOfWeek();
        $semanaFin = Carbon::now()->endOfWeek();
        $mttrSemanal = (clone $base)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$semanaInicio, $semanaFin])
            ->selectRaw(SqlDialect::avgHoursBetween('created_at', 'resolved_at').' as avg_hours')
            ->value('avg_hours');
        $mttrSemanal = $mttrSemanal !== null ? round((float) $mttrSemanal, 1) : null;

        // --- Balance de carga ---
        $balanceCarga = (clone $base)
            ->whereIn('ticket_state_id', $openIds)
            ->select('assigned_user_id', DB::raw('count(*) as total'))
            ->groupBy('assigned_user_id')
            ->orderByDesc('total')
            ->get();
        $userIds = $balanceCarga->pluck('assigned_user_id')->filter()->unique()->values()->all();
        $userNames = $userIds ? User::whereIn('id', $userIds)->pluck('name', 'id')->all() : [];
        $balanceCargaData = $balanceCarga->map(function ($row) use ($userNames) {
            $name = $row->assigned_user_id ? ($userNames[$row->assigned_user_id] ?? 'Usuario #'.$row->assigned_user_id) : 'Sin asignar';

            return ['agente' => $name, 'total' => (int) $row->total];
        })->values()->all();

        // --- Tendencia: últimos 15 días (2 queries agrupadas) ---
        $hace15Dias = now()->subDays(14)->startOfDay();

        $creadosPorDia = (clone $base)
            ->where('created_at', '>=', $hace15Dias)
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $cerradosPorDia = (clone $base)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $hace15Dias)
            ->selectRaw('DATE(resolved_at) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $tendencia = [];
        for ($i = 14; $i >= 0; $i--) {
            $dia = Carbon::today()->subDays($i);
            $fecha = $dia->format('Y-m-d');
            $tendencia[] = [
                'fecha' => $fecha,
                'etiqueta' => $dia->locale('es')->dayName.' '.$dia->format('d/m'),
                'creados' => (int) ($creadosPorDia[$fecha] ?? 0),
                'cerrados' => (int) ($cerradosPorDia[$fecha] ?? 0),
            ];
        }

        // --- Top incidentes por categoría (tipo de ticket) ---
        $topIncidentesPorTipo = (clone $base)
            ->whereIn('ticket_state_id', $openIds)
            ->select('ticket_type_id', DB::raw('count(*) as total'))
            ->groupBy('ticket_type_id')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
        $typeIds = $topIncidentesPorTipo->pluck('ticket_type_id')->filter()->unique()->values()->all();
        $typeNames = $typeIds ? TicketType::whereIn('id', $typeIds)->pluck('name', 'id')->all() : [];
        $topIncidentes = $topIncidentesPorTipo->map(function ($row) use ($typeNames) {
            return [
                'categoria' => $row->ticket_type_id ? ($typeNames[$row->ticket_type_id] ?? 'Tipo #'.$row->ticket_type_id) : 'Sin tipo',
                'total' => (int) $row->total,
            ];
        })->values()->all();

        // --- Top 5 críticos: prioridad Alta/Urgente, más antiguos primero ---
        $prioridadAltaIds = DB::table('priorities')
            ->where(function ($q) {
                $q->where('name', 'like', '%Urgente%')->orWhere('name', 'like', '%Alta%');
            })
            ->orWhere('level', '<=', 2)
            ->pluck('id');
        $criticosQuery = (clone $base)
            ->whereNotIn('ticket_state_id', $finalStateIds)
            ->with(['state:id,name,code', 'priority:id,name', 'assignedUser:id,name', 'site:id,name'])
            ->orderBy('created_at')
            ->limit(10);
        if ($prioridadAltaIds->isNotEmpty()) {
            $criticosQuery->whereIn('priority_id', $prioridadAltaIds);
        }
        $top5Criticos = $criticosQuery->get()->take(5)->map(function ($t) {
            return [
                'id' => $t->id,
                'subject' => $t->subject,
                'state' => $t->state ? ['name' => $t->state->name, 'code' => $t->state->code] : null,
                'priority' => $t->priority ? ['name' => $t->priority->name] : null,
                'assigned_user' => $t->assignedUser ? ['name' => $t->assignedUser->name] : null,
                'site' => $t->site ? ['name' => $t->site->name] : null,
                'sla_status_text' => $t->sla_status_text,
                'is_overdue' => $t->is_overdue,
                'due_at' => $t->due_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ];
        })->values()->all();

        // --- Top 3 resolvers: agentes que más cerraron tickets en los últimos 30 días ---
        $topResolvers = [];
        if ($hasScopedTickets && $resueltoCerradoIds->isNotEmpty()) {
            $topResolvers = $this->filters
                ->topResolvers($ticketSubquery, $resueltoCerradoIds, 3, now()->subDays(30))
                ->map(fn ($r) => ['nombre' => $r['name'], 'tickets_cerrados' => $r['total']])
                ->all();
        }

        // --- Top sites: tickets creados este mes por sede ---
        $mesInicio = Carbon::now()->startOfMonth();
        $mesFin = Carbon::now()->endOfMonth();
        $topSitesRaw = (clone $base)
            ->whereBetween('created_at', [$mesInicio, $mesFin])
            ->select('site_id', DB::raw('count(*) as total'))
            ->groupBy('site_id')
            ->orderByDesc('total')
            ->get();
        $siteIds = $topSitesRaw->pluck('site_id')->filter()->unique()->values()->all();
        $siteNames = $siteIds ? Site::whereIn('id', $siteIds)->pluck('name', 'id')->all() : [];
        $topSites = $topSitesRaw->map(function ($row) use ($siteNames) {
            return [
                'site' => $row->site_id ? ($siteNames[$row->site_id] ?? 'Sede #'.$row->site_id) : 'Sin sede',
                'total' => (int) $row->total,
            ];
        })->values()->all();

        // --- Top fallas: agrupación por categoría/tipo (tickets creados este mes) ---
        $topFallasRaw = (clone $base)
            ->whereBetween('created_at', [$mesInicio, $mesFin])
            ->select('ticket_type_id', DB::raw('count(*) as total'))
            ->groupBy('ticket_type_id')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
        $fallasTypeIds = $topFallasRaw->pluck('ticket_type_id')->filter()->unique()->values()->all();
        $fallasTypeNames = $fallasTypeIds ? TicketType::whereIn('id', $fallasTypeIds)->pluck('name', 'id')->all() : [];
        $topFallas = $topFallasRaw->map(function ($row) use ($fallasTypeNames) {
            return [
                'categoria' => $row->ticket_type_id ? ($fallasTypeNames[$row->ticket_type_id] ?? 'Tipo #'.$row->ticket_type_id) : 'Sin tipo',
                'total' => (int) $row->total,
            ];
        })->values()->all();

        // --- KPI reaperturas: tickets que pasaron de Resuelto/Cerrado a Abierto ---
        $reaperturas = 0;
        $totalResueltos = 0;
        $porcentajeReapertura = 0;
        if ($abiertoId && $hasScopedTickets && $resueltoCerradoIds->isNotEmpty()) {
            $reaperturas = TicketHistory::query()
                ->whereIn('ticket_id', $ticketSubquery)
                ->where('ticket_state_id', $abiertoId)
                ->whereExists(function ($q) use ($resueltoCerradoIds) {
                    $q->select(DB::raw(1))
                        ->from('ticket_histories as h2')
                        ->whereColumn('h2.ticket_id', 'ticket_histories.ticket_id')
                        ->whereIn('h2.ticket_state_id', $resueltoCerradoIds)
                        ->whereColumn('h2.created_at', '<', 'ticket_histories.created_at');
                })
                ->distinct()
                ->count('ticket_id');

            $totalResueltos = TicketHistory::query()
                ->whereIn('ticket_id', $ticketSubquery)
                ->whereIn('ticket_state_id', $resueltoCerradoIds)
                ->distinct()
                ->count('ticket_id');

            $porcentajeReapertura = $totalResueltos > 0
                ? round((float) ($reaperturas / $totalResueltos) * 100, 1)
                : 0;
        }

        return [
            'kpis' => [
                'vencidos_hoy' => $vencidosHoy,
                'sin_asignar' => $sinAsignar,
                'congelados' => $congelados,
                'mttr_semanal' => $mttrSemanal,
            ],
            'kpi_reaperturas' => [
                'cantidad_reaperturas' => $reaperturas,
                'total_resueltos' => $totalResueltos,
                'porcentaje' => $porcentajeReapertura,
            ],
            'balance_carga' => $balanceCargaData,
            'tendencia' => $tendencia,
            'top_incidentes' => $topIncidentes,
            'top_5_criticos' => $top5Criticos,
            'top_resolvers' => $topResolvers,
            'top_sites' => $topSites,
            'top_fallas' => $topFallas,
        ];
    }

    /**
     * @return array{
     *     open: Collection,
     *     final: Collection,
     *     frozen: Collection,
     *     resuelto_cerrado: Collection,
     *     abierto: int|null
     * }
     */
    private function cachedTicketStateIds(): array
    {
        return Cache::remember('ticket_state_ids', now()->addMinutes(5), function () {
            return [
                'open' => TicketState::where('is_final', false)->pluck('id'),
                'final' => TicketState::where('is_final', true)->pluck('id'),
                'frozen' => TicketState::where(function ($q) {
                    $q->where('name', 'like', '%Esperando Proveedor%')
                        ->orWhere('name', 'like', '%Pausado%')
                        ->orWhereIn('code', ['esperando_proveedor', 'pausado', 'en_espera']);
                })->pluck('id'),
                'resuelto_cerrado' => TicketState::whereIn('code', ['resuelto', 'cerrado'])->pluck('id'),
                'abierto' => TicketState::where('code', 'abierto')->value('id'),
            ];
        });
    }

}
