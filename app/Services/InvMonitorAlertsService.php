<?php

namespace App\Services;

use App\Models\InvAsset;
use App\Models\InvMaintenance;
use App\Models\InvMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Las 4 alertas del dashboard de Inventario (fase 7.1, port desde
 * HelpdeskECD2026). Extraído de InvMonitorPageController en fase 7.2 --
 * ahora lo consumen dos cosas (la página y el export), no tiene sentido
 * duplicar las queries en los dos archivos.
 */
class InvMonitorAlertsService
{
    public function __construct(protected ClientScopeService $clientScope) {}

    /** Garantías por vencer en los próximos 15 días. */
    public function warrantyExpiring(User $user)
    {
        return $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->whereNotNull('warranty_expiry')
            ->whereBetween('warranty_expiry', [now()->toDateString(), now()->addDays(15)->toDateString()])
            ->orderBy('warranty_expiry')
            ->get(['id', 'name', 'internal_tag', 'warranty_expiry']);
    }

    /** Activos sin responsable asignado -- informativo, no necesariamente un problema. */
    public function unassigned(User $user)
    {
        return $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->whereNull('current_user_id')
            ->orderBy('name')
            ->get(['id', 'name', 'internal_tag']);
    }

    /** Activos con 2+ traslados en las últimas 24h -- posible indicio de mal uso o dato erróneo. */
    public function repeatedTransfers(User $user)
    {
        $counts = InvMovement::query()
            ->select('asset_id', DB::raw('count(*) as transfer_count'))
            ->where('type', 'TRASLADO')
            ->where('date', '>=', now()->subDay())
            ->groupBy('asset_id')
            ->havingRaw('count(*) >= 2')
            ->pluck('transfer_count', 'asset_id');

        if ($counts->isEmpty()) {
            return collect();
        }

        return $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->whereIn('id', $counts->keys())
            ->get(['id', 'name', 'internal_tag'])
            ->map(fn (InvAsset $asset) => [
                'id' => $asset->id,
                'name' => $asset->name,
                'internal_tag' => $asset->internal_tag,
                'transfer_count' => $counts[$asset->id],
            ])
            ->values();
    }

    /** Mantenimientos abiertos hace más de 30 días. */
    public function staleMaintenances(User $user)
    {
        return $this->clientScope->applyInventoryMaintenanceScope(
            InvMaintenance::query()->with('asset:id,name,internal_tag'),
            $user
        )
            ->whereNull('end_date')
            ->where('start_date', '<=', now()->subDays(30)->toDateString())
            ->orderBy('start_date')
            ->get(['id', 'asset_id', 'title', 'start_date']);
    }
}
