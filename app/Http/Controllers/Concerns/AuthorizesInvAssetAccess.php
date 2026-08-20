<?php

namespace App\Http\Controllers\Concerns;

use App\Models\InvAsset;
use App\Services\ClientScopeService;
use Illuminate\Support\Facades\Auth;

/**
 * Guard de acceso a un InvAsset por client_id -- repetido en
 * InvAssetController/InvAssetImageController (fase 2) y InvMovementController
 * (fase 3), extraído aquí en vez de triplicar el mismo método privado.
 */
trait AuthorizesInvAssetAccess
{
    protected function clientScope(): ClientScopeService
    {
        return app(ClientScopeService::class);
    }

    protected function authorizeAssetAccess(InvAsset $inv_asset): void
    {
        $scoped = $this->clientScope()->applyInventoryAssetScope(
            InvAsset::query()->whereKey($inv_asset->id),
            Auth::user()
        );

        abort_unless($scoped->exists(), 403, 'No tienes acceso a este activo.');
    }
}
