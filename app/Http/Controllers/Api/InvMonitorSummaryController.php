<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Services\ClientScopeService;
use App\Services\InvMonitorAlertsService;
use Illuminate\Support\Facades\Auth;

/**
 * Resumen ligero de Inventario para el panel general de Inicio (fase 1 de
 * la separación de dashboards, 2026-08-20) -- solo conteos, sin las filas
 * completas que sí necesita /inventory/monitor. Reusa
 * InvMonitorAlertsService::counts(), mismo criterio que
 * InvMonitorExportController reusando el resto de sus métodos.
 */
class InvMonitorSummaryController extends Controller
{
    public function __construct(
        protected InvMonitorAlertsService $alerts,
        protected ClientScopeService $clientScope
    ) {}

    public function __invoke()
    {
        $user = Auth::user();

        $counts = $this->alerts->counts($user);
        $counts['total_assets'] = $this->clientScope
            ->applyInventoryAssetScope(InvAsset::query(), $user)
            ->count();
        $counts['by_status'] = $this->alerts->byStatus($user);

        return response()->json($counts);
    }
}
