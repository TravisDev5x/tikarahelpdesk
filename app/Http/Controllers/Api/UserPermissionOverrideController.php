<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Services\ClientScopeService;
use App\Services\RoleTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * RBAC v2 (Fase 6), Paso 5: overrides directos de permiso por usuario,
 * por encima de su(s) plantilla(s) -- usa el mecanismo nativo de Spatie
 * (model_has_permissions vía givePermissionTo()/revokePermissionTo()), no
 * una tabla paralela. Gateado por users.manage, igual que UserRoleController.
 */
class UserPermissionOverrideController extends Controller
{
    public function __construct(
        private RoleTemplateService $templates,
        protected ClientScopeService $clientScope
    ) {}

    /**
     * POST /api/users/{user}/permission-overrides
     */
    public function store(Request $request, User $user)
    {
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Hardcoded 'web' -- App\Models\User tiene guard_name='web' fijo
        // por propiedad de clase; leer config('auth.defaults.guard') aquí
        // resolvía 'sanctum' (Authenticate::authenticate() lo muta al
        // autenticar esta misma request) y givePermissionTo() reventaba con
        // GuardDoesNotMatch. Ver nota completa en PermissionController::store().
        $guard = 'web';

        $data = $request->validate([
            'permission' => [
                'required', 'string',
                Rule::exists('permissions', 'name')->where('guard_name', $guard),
            ],
        ]);

        $permission = Permission::where('name', $data['permission'])->where('guard_name', $guard)->firstOrFail();
        $user->givePermissionTo($permission);

        return response()->json($this->templates->permissionBreakdownFor($user), 201);
    }

    /**
     * DELETE /api/users/{user}/permission-overrides/{permission}
     * Revoca SOLO el override directo -- si el usuario también tiene ese
     * permiso vía una plantilla, lo conserva por esa vía (revokePermissionTo
     * de Spatie ya se comporta así: solo quita la asignación directa).
     */
    public function destroy(User $user, string $permission)
    {
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $user->revokePermissionTo($permission);

        return response()->json($this->templates->permissionBreakdownFor($user));
    }

    /**
     * GET /api/users/{user}/permissions
     * Solo lectura: qué viene de plantilla vs. qué es override directo.
     */
    public function show(User $user)
    {
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json($this->templates->permissionBreakdownFor($user));
    }
}
