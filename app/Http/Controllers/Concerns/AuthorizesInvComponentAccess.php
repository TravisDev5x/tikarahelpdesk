<?php

namespace App\Http\Controllers\Concerns;

use App\Models\InvComponent;
use App\Services\ClientScopeService;
use Illuminate\Support\Facades\Auth;

/**
 * Guard de acceso a un InvComponent por client_id (fase 4) -- mismo patrón
 * que AuthorizesInvAssetAccess (fase 3), scope distinto porque un
 * componente puede estar suelto (sin asset_id).
 */
trait AuthorizesInvComponentAccess
{
    protected function clientScope(): ClientScopeService
    {
        return app(ClientScopeService::class);
    }

    protected function authorizeComponentAccess(InvComponent $inv_component): void
    {
        $scoped = $this->clientScope()->applyInventoryComponentScope(
            InvComponent::query()->whereKey($inv_component->id),
            Auth::user()
        );

        abort_unless($scoped->exists(), 403, 'No tienes acceso a este componente.');
    }
}
