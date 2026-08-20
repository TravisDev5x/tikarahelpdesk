<?php

namespace App\Http\Controllers\Inertia;

use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\User;
use App\Services\ClientScopeService;
use App\Services\InvMonitorAlertsService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard de alertas y reportes de Inventario (fase 7.1 alertas, port
 * desde HelpdeskECD2026 -- página estática por request, sin filtros, se
 * sirve como props ya calculadas en el Inertia::render, a diferencia de
 * TicketAnalyticsController (que sí necesita cache+axios por tener
 * filtros dinámicos). De las 5 alertas del original se portan 4 --
 * "activos sin empresa" no aplica aquí: inv_assets.client_id es NOT NULL
 * a nivel de columna, ese estado es estructuralmente imposible. Las
 * queries de alertas viven en InvMonitorAlertsService (fase 7.2 las
 * compartió con el export del monitor).
 *
 * Reportes (fase posterior): el original NO tiene gráficos -- son 4
 * DataTables sobre el modelo V1 legacy `Product`, sin equivalente directo
 * en las tablas V2 que Tikara portó. Se diseñaron reportes propios sobre
 * el esquema real de Tikara (por categoría/estatus/sede, top responsables,
 * costo por categoría, tendencia de altas por mes), agregados en memoria
 * igual que InvAssetExport::buildByCategorySheet()/buildByStatusSheet() --
 * sin servicio de analíticas aparte, mismo criterio que TicketAnalyticsController.
 */
class InvMonitorPageController extends Controller
{
    public function __construct(
        protected InvMonitorAlertsService $alerts,
        protected ClientScopeService $clientScope
    ) {}

    public function __invoke(): Response
    {
        $user = Auth::user();

        return Inertia::render('Inventory/Monitor', [
            'warrantyExpiring' => $this->alerts->warrantyExpiring($user),
            'unassigned' => $this->alerts->unassigned($user),
            'repeatedTransfers' => $this->alerts->repeatedTransfers($user),
            'staleMaintenances' => $this->alerts->staleMaintenances($user),
            ...$this->reports($user),
        ]);
    }

    private function reports(User $user): array
    {
        $assets = $this->clientScope->applyInventoryAssetScope(
            InvAsset::query()->with(['category', 'status', 'site', 'currentUser']),
            $user
        )->get();

        $group = fn (callable $keyFn) => $assets
            ->groupBy($keyFn)
            ->map->count()
            ->sortDesc()
            ->map(fn ($value, $key) => ['label' => $key, 'value' => $value])
            ->values();

        $byCategory = $group(fn (InvAsset $a) => $a->category?->name ?? 'Sin categoría');
        $byStatus = $group(fn (InvAsset $a) => $a->status?->name ?? 'Sin estatus');
        $bySite = $group(fn (InvAsset $a) => $a->site?->name ?? 'Sin sede');

        // Se agrupa por current_user_id, no por nombre -- dos usuarios distintos
        // pueden compartir el mismo nombre para mostrar (ver userLabel()), y
        // agrupar por string los colapsaría en un solo renglón. Se manda
        // user_id aparte para el click-through en Monitor.jsx (TinyVerticalBarChart
        // no propaga campos extra en su payload de click, se resuelve ahí
        // haciendo match por label contra este mismo arreglo).
        $topAssignees = $assets
            ->whereNotNull('current_user_id')
            ->groupBy('current_user_id')
            ->map(fn ($group) => [
                'label' => $this->userLabel($group->first()->currentUser),
                'value' => $group->count(),
                'user_id' => $group->first()->current_user_id,
            ])
            ->sortByDesc('value')
            ->take(5)
            ->values();

        $totalValue = round((float) $assets->sum('cost'), 2);

        $costByCategory = $assets
            ->groupBy(fn (InvAsset $a) => $a->category?->name ?? 'Sin categoría')
            ->map(fn ($group) => $group->sum('cost'))
            ->sortDesc()
            ->map(fn ($value, $key) => ['label' => $key, 'value' => round((float) $value, 2)])
            ->values();

        $monthlyTrend = $this->monthlyTrend($assets);

        return compact('byCategory', 'byStatus', 'bySite', 'topAssignees', 'totalValue', 'costByCategory', 'monthlyTrend');
    }

    /** Altas de activos por mes, últimos 6 meses (incluye meses en cero, para que la tendencia se vea completa). */
    private function monthlyTrend($assets): array
    {
        static $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        return collect(range(5, 0))
            ->map(function (int $i) use ($assets, $meses) {
                $month = now()->subMonths($i)->startOfMonth();
                $count = $assets->filter(fn (InvAsset $a) => $a->created_at?->isSameMonth($month))->count();

                return ['label' => $meses[$month->month - 1].' '.$month->format('Y'), 'value' => $count];
            })
            ->values()
            ->all();
    }

    private function userLabel(?User $user): string
    {
        if (! $user) {
            return '—';
        }

        return trim(implode(' ', array_filter([$user->first_name, $user->paternal_last_name, $user->maternal_last_name])));
    }
}
