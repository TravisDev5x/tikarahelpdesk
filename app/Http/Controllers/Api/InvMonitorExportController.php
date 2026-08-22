<?php

namespace App\Http\Controllers\Api;

use App\Exports\InvMonitorExport;
use App\Http\Controllers\Controller;
use App\Services\InvMonitorAlertsService;
use Illuminate\Support\Facades\Auth;

/**
 * Export del dashboard de alertas de Inventario (fase 7.2, port desde
 * HelpdeskECD2026 -- InventoryMonitorExportController). Reusa
 * InvMonitorAlertsService, mismas queries que ya muestra Monitor.jsx.
 */
class InvMonitorExportController extends Controller
{
    public function __construct(protected InvMonitorAlertsService $alerts) {}

    public function __invoke()
    {
        $user = Auth::user();

        $export = new InvMonitorExport([
            'warrantyExpiring' => $this->alerts->warrantyExpiring($user),
            'unassigned' => $this->alerts->unassigned($user),
            'repeatedTransfers' => $this->alerts->repeatedTransfers($user),
            'staleMaintenances' => $this->alerts->staleMaintenances($user),
            'problemAssets' => $this->alerts->problemAssets($user),
        ]);

        $filename = 'alertas_inventario_'.now()->format('Ymd_His').'.xlsx';
        $tempName = 'inv_monitor_export_'.substr(uniqid('', true), -8).'.xlsx';
        $path = storage_path('app'.DIRECTORY_SEPARATOR.$tempName);
        $export->exportToPath($path);

        // Ver comentario equivalente en InvAssetExportController.
        if (! app()->runningUnitTests() && ob_get_length() > 0) {
            ob_end_clean();
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
