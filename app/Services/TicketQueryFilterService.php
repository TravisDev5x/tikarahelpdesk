<?php

namespace App\Services;

use App\Models\TicketHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filtros comunes de consultas de tickets, compartidos por
 * TicketAnalyticsController (/api/tickets/analytics, reporte histórico) y
 * ResolbebController (/api/tickets/dashboard-operativo, tablero en vivo).
 *
 * Antes cada controller tenía su propio applyFilters() escrito a mano --
 * mismos parámetros, dos implementaciones que ya habían divergido: Analytics
 * validaba site_id vía ClientScopeService::applySiteFilter() (confirma que
 * la sede pertenece al client/operador del usuario antes de filtrar);
 * Resolbeb hacía where('site_id', ...) crudo sin esa validación. Único
 * punto de verdad ahora -- ver docs/PENDING.md.
 */
class TicketQueryFilterService
{
    public function __construct(
        protected ClientScopeService $clientScope
    ) {}

    /**
     * Aplica todos los filtros opcionales sobre un query YA scopeado por
     * TicketPolicy::scopeFor() (esta clase no reemplaza ese scope base, solo
     * lo estrecha más si el request trae parámetros).
     */
    public function apply(Request $request, User $user, Builder $query): void
    {
        $this->clientScope->applyClientFilter($request, $user, $query);

        $simpleFilters = [
            'area_current_id' => 'area_current_id',
            'area_origin_id' => 'area_origin_id',
            'location_id' => 'location_id',
            'ticket_type_id' => 'ticket_type_id',
            'priority_id' => 'priority_id',
            'ticket_state_id' => 'ticket_state_id',
        ];

        foreach ($simpleFilters as $param => $column) {
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

        if ($request->filled('assigned_user_id')) {
            $assigneeId = (int) $request->input('assigned_user_id');
            $allowed = $user->can('tickets.manage_all')
                || DB::table('users')->where('id', $assigneeId)->where('area_id', $user->area_id)->exists();
            if ($allowed) {
                $query->where('assigned_user_id', $assigneeId);
            }
        }

        if ($request->input('assigned_to') === 'me') {
            $query->where('assigned_user_id', $user->id);
        }

        if ($request->input('assigned_status') === 'unassigned') {
            $query->whereNull('assigned_user_id');
        }

        if ($request->filled('date_from')) {
            try {
                $query->where('created_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
            } catch (\Throwable $e) {
                // ignore invalid date_from
            }
        }

        if ($request->filled('date_to')) {
            try {
                $query->where('created_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
            } catch (\Throwable $e) {
                // ignore invalid date_to
            }
        }
    }

    /**
     * Top N agentes que más tickets cerraron (historial con estado final),
     * dentro del alcance ya resuelto por el caller. $ticketIds acepta un
     * Builder (subquery, evita pre-cargar todos los IDs) o una Collection ya
     * calculada -- whereIn() de Laravel acepta ambos. $since acota a una
     * ventana de tiempo (ej. Resolbeb: últimos 30 días); null = sin límite
     * (ej. Analytics: todo el alcance filtrado).
     *
     * @param  Builder|Collection<int, int>  $ticketIds
     * @param  Collection<int, int>  $finalStateIds
     * @return Collection<int, array{user_id: int, name: string, total: int}>
     */
    public function topResolvers($ticketIds, Collection $finalStateIds, int $limit = 5, ?Carbon $since = null): Collection
    {
        $isEmptyCollection = $ticketIds instanceof Collection && $ticketIds->isEmpty();
        if ($isEmptyCollection || $finalStateIds->isEmpty()) {
            return collect();
        }

        $rows = TicketHistory::query()
            ->whereIn('ticket_id', $ticketIds)
            ->whereIn('ticket_state_id', $finalStateIds)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->select('actor_id', DB::raw('count(*) as total'))
            ->groupBy('actor_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('actor:id,name')
            ->get();

        return $rows->map(fn ($r) => [
            'user_id' => (int) $r->actor_id,
            'name' => $r->actor->name ?? 'Usuario #'.$r->actor_id,
            'total' => (int) $r->total,
        ])->values();
    }
}
