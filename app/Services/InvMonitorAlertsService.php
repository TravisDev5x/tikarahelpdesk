<?php

namespace App\Services;

use App\Models\InvAsset;
use App\Models\InvMaintenance;
use App\Models\InvMovement;
use App\Models\InvStatus;
use App\Models\InvWarranty;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Las alertas del dashboard de Inventario (fase 7.1, port desde
 * HelpdeskECD2026; enriquecidas en fase 5 de la auditoría --
 * "Inteligencia"). Extraído de InvMonitorPageController en fase 7.2 --
 * ahora lo consumen dos cosas (la página y el export), no tiene sentido
 * duplicar las queries en los dos archivos.
 */
class InvMonitorAlertsService
{
    /** Fase 5 -- renovación predictiva: además de futuras, detecta vencidas sin renovar. */
    private const RENEWAL_LOOKBACK_DAYS = 30;

    private const RENEWAL_LOOKAHEAD_DAYS = 30;

    private const RENEWAL_CRITICAL_DAYS = 15;

    /** Fase 5 -- scoring de anomalías de traslados (antes: una sola regla fija de 24h). */
    private const TRANSFER_WATCH_24H = 2;

    private const TRANSFER_CRITICAL_24H = 4;

    private const TRANSFER_WATCH_7D = 4;

    private const TRANSFER_CRITICAL_7D = 8;

    /** Fase 5 -- "activos con demasiados tickets". */
    private const PROBLEM_ASSET_WINDOW_DAYS = 90;

    private const PROBLEM_ASSET_THRESHOLD = 3;

    public function __construct(protected ClientScopeService $clientScope) {}

    /**
     * Renovación predictiva (fase 5): une inv_assets.warranty_expiry (el
     * campo rápido original) con inv_warranties.ends_at (la tabla rica
     * opcional de fase 2.3, que hasta ahora ninguna alerta leía). Ventana
     * de 30 días hacia atrás (vencidas sin renovar) y 30 hacia adelante,
     * con severidad graduada en vez del corte binario de 15 días fijos.
     */
    public function warrantyExpiring(User $user)
    {
        $windowStart = now()->subDays(self::RENEWAL_LOOKBACK_DAYS)->toDateString();
        $windowEnd = now()->addDays(self::RENEWAL_LOOKAHEAD_DAYS)->toDateString();

        $fromAssets = $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->whereNotNull('warranty_expiry')
            ->whereBetween('warranty_expiry', [$windowStart, $windowEnd])
            ->get(['id', 'name', 'internal_tag', 'warranty_expiry'])
            ->map(fn (InvAsset $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'internal_tag' => $a->internal_tag,
                // Auditoría de bugs críticos (2026-08-22): warranty_expiry
                // está cast a 'date' (Carbon), no string -- sin
                // toDateString() esta rama comparaba/ordenaba distinto que
                // $fromWarranties (que sí lo hacía), corrompiendo el
                // dedupe/severidad/orden cuando un activo tenía ambas
                // fuentes.
                'expires_on' => $a->warranty_expiry->toDateString(),
                'source' => 'warranty_expiry',
            ]);

        $assetIds = $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)->pluck('id');
        $fromWarranties = InvWarranty::query()
            ->whereIn('asset_id', $assetIds)
            ->whereBetween('ends_at', [$windowStart, $windowEnd])
            ->with('asset:id,name,internal_tag')
            ->get()
            ->filter(fn (InvWarranty $w) => $w->asset !== null)
            ->map(fn (InvWarranty $w) => [
                'id' => $w->asset_id,
                'name' => $w->asset->name,
                'internal_tag' => $w->asset->internal_tag,
                'expires_on' => $w->ends_at->toDateString(),
                'source' => 'inv_warranties',
            ]);

        return $fromAssets->concat($fromWarranties)
            ->groupBy('id')
            ->map(fn ($rows) => $rows->sortBy('expires_on')->first())
            ->map(fn ($row) => [...$row, 'severity' => $this->renewalSeverity($row['expires_on'])])
            ->sortBy('expires_on')
            ->values();
    }

    private function renewalSeverity(string $expiresOn): string
    {
        if ($expiresOn < now()->toDateString()) {
            return 'vencida';
        }
        if ($expiresOn <= now()->addDays(self::RENEWAL_CRITICAL_DAYS)->toDateString()) {
            return 'critica';
        }

        return 'proxima';
    }

    /** Activos sin responsable asignado -- informativo, no necesariamente un problema. */
    public function unassigned(User $user)
    {
        return $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->whereNull('current_user_id')
            ->orderBy('name')
            ->get(['id', 'name', 'internal_tag']);
    }

    /**
     * Scoring de anomalías de traslados (fase 5): antes una sola regla fija
     * (2+ en 24h). Ahora combina dos ventanas (24h y 7 días) y gradúa la
     * severidad en vez de un corte binario.
     */
    public function repeatedTransfers(User $user)
    {
        $counts24h = $this->transferCounts(now()->subDay());
        $counts7d = $this->transferCounts(now()->subDays(7));

        $flagged = $counts24h->filter(fn ($c) => $c >= self::TRANSFER_WATCH_24H)->keys()
            ->merge($counts7d->filter(fn ($c) => $c >= self::TRANSFER_WATCH_7D)->keys())
            ->unique();

        if ($flagged->isEmpty()) {
            return collect();
        }

        return $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->whereIn('id', $flagged)
            ->get(['id', 'name', 'internal_tag'])
            ->map(function (InvAsset $asset) use ($counts24h, $counts7d) {
                $c24h = $counts24h[$asset->id] ?? 0;
                $c7d = $counts7d[$asset->id] ?? 0;

                return [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'internal_tag' => $asset->internal_tag,
                    'transfers_24h' => $c24h,
                    'transfers_7d' => $c7d,
                    'severity' => $this->transferSeverity($c24h, $c7d),
                ];
            })
            ->sortByDesc('transfers_7d')
            ->values();
    }

    private function transferCounts($since)
    {
        return InvMovement::query()
            ->select('asset_id', DB::raw('count(*) as c'))
            ->where('type', 'TRASLADO')
            ->where('date', '>=', $since)
            ->groupBy('asset_id')
            ->pluck('c', 'asset_id');
    }

    private function transferSeverity(int $transfers24h, int $transfers7d): string
    {
        if ($transfers24h >= self::TRANSFER_CRITICAL_24H || $transfers7d >= self::TRANSFER_CRITICAL_7D) {
            return 'critica';
        }

        return 'atencion';
    }

    /**
     * "Activos con demasiados tickets" (fase 5, nuevo) -- factible desde
     * que fase 3.1 vinculó Activo↔Ticket (inv_asset_ticket), nadie lo había
     * agregado por conteo todavía.
     */
    public function problemAssets(User $user)
    {
        return $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->withCount(['ticketLinks as ticket_count' => fn ($q) => $q->where('created_at', '>=', now()->subDays(self::PROBLEM_ASSET_WINDOW_DAYS))])
            ->get()
            ->filter(fn (InvAsset $asset) => $asset->ticket_count >= self::PROBLEM_ASSET_THRESHOLD)
            ->sortByDesc('ticket_count')
            ->map(fn (InvAsset $asset) => [
                'id' => $asset->id,
                'name' => $asset->name,
                'internal_tag' => $asset->internal_tag,
                'ticket_count' => $asset->ticket_count,
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

    /**
     * Igual que las de arriba pero solo el conteo -- para el resumen
     * ligero de Inicio (fase 1 de la separación de dashboards), que no
     * necesita nombres/tags/fechas. Fase 5: en vez de reescribir cada
     * criterio en SQL aparte (como antes), cuenta sobre el resultado de
     * los mismos métodos que arriba -- garantiza que counts() y las
     * versiones "con filas" nunca se desalineen, y el volumen de
     * activos de un tenant no justifica la duplicación.
     *
     * @return array{warranty_expiring: int, unassigned: int, repeated_transfers: int, stale_maintenances: int, problem_assets: int}
     */
    public function counts(User $user): array
    {
        return [
            'warranty_expiring' => $this->warrantyExpiring($user)->count(),
            'unassigned' => $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
                ->whereNull('current_user_id')
                ->count(),
            'repeated_transfers' => $this->repeatedTransfers($user)->count(),
            'stale_maintenances' => $this->clientScope->applyInventoryMaintenanceScope(InvMaintenance::query(), $user)
                ->whereNull('end_date')
                ->where('start_date', '<=', now()->subDays(30)->toDateString())
                ->count(),
            'problem_assets' => $this->problemAssets($user)->count(),
        ];
    }

    /**
     * Activos por estatus, agregado a nivel SQL (no en PHP como
     * InvMonitorPageController::reports(), que carga todas las filas con
     * relaciones para 6 bloques distintos) -- para el resumen ligero de
     * Inicio, mismo criterio que TicketController::summary()::$byState.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function byStatus(User $user): array
    {
        $statuses = InvStatus::query()->pluck('name', 'id');

        return $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->select('status_id', DB::raw('count(*) as total'))
            ->groupBy('status_id')
            ->get()
            ->map(fn ($row) => [
                'label' => $statuses->get($row->status_id, 'Sin estatus'),
                'value' => (int) $row->total,
            ])
            ->values()
            ->all();
    }
}
