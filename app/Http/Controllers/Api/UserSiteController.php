<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Panel de asignación de site_user fuera de onboarding (auditoría
 * 2026-08-11, deuda documentada desde Fase 7.7 en docs/PENDING.md): antes
 * solo se podía asignar sites a staff desde /onboarding/teams, y solo
 * mientras ese wizard seguía abierto -- un invitado que aceptaba después
 * no tenía forma de que le asignaran una sede. Mismo patrón que
 * UserPermissionOverrideController, gateado por sites.assign_staff (no
 * users.manage -- permiso separado a propósito).
 */
class UserSiteController extends Controller
{
    public function __construct(protected ClientScopeService $clientScope) {}

    /**
     * GET /api/users/{user}/sites
     * Sedes ya asignadas + catálogo de sedes disponibles del tenant del
     * usuario objetivo (mismo criterio que ya usa InertiaUserController::index()).
     */
    public function show(User $user)
    {
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $clientId = $this->clientScope->resolveUserClientId($user);

        return response()->json([
            'assigned' => $user->sites()->orderBy('sites.name')->get(['sites.id', 'sites.name']),
            'available' => $clientId
                ? Site::where('client_id', $clientId)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    /**
     * POST /api/users/{user}/sites
     * Sincroniza (reemplaza) las sedes asignadas -- mismo
     * User::sites()->sync() ya usado en TenantOnboardingController::storeTeams().
     */
    public function sync(Request $request, User $user)
    {
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'site_ids' => ['array'],
            'site_ids.*' => ['integer'],
        ]);

        $siteIds = $data['site_ids'] ?? [];

        // Cada site debe pertenecer al mismo tenant del usuario OBJETIVO,
        // no del actor -- mismo criterio ya usado en
        // UserController::update() para el site_id "hogar"
        // (ClientScopeService::assertSiteAccessible()), reusado tal cual
        // pasándole el usuario objetivo.
        foreach ($siteIds as $siteId) {
            if (! $this->clientScope->assertSiteAccessible($user, (int) $siteId)) {
                throw ValidationException::withMessages([
                    'site_ids' => ['Una o más sedes no pertenecen al cliente de este usuario.'],
                ]);
            }
        }

        $user->sites()->sync($siteIds);

        return response()->json([
            'assigned' => $user->sites()->orderBy('sites.name')->get(['sites.id', 'sites.name']),
        ]);
    }
}
