<?php

namespace App\Http\Controllers\Concerns;

use App\Models\InvMaintenance;
use App\Services\ClientScopeService;
use Illuminate\Support\Facades\Auth;

/**
 * Guard de acceso a un InvMaintenance por client_id (fase 5) -- mismo
 * patrón que AuthorizesInvComponentAccess (fase 4).
 */
trait AuthorizesInvMaintenanceAccess
{
    protected function clientScope(): ClientScopeService
    {
        return app(ClientScopeService::class);
    }

    protected function authorizeMaintenanceAccess(InvMaintenance $inv_maintenance): void
    {
        $scoped = $this->clientScope()->applyInventoryMaintenanceScope(
            InvMaintenance::query()->whereKey($inv_maintenance->id),
            Auth::user()
        );

        abort_unless($scoped->exists(), 403, 'No tienes acceso a este mantenimiento.');
    }
}
