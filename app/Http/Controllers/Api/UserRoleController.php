<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    /**
     * POST /api/users/{user}/roles
     * Sincroniza roles del usuario
     */
    public function sync(Request $request, User $user)
    {
        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['exists:roles,id,deleted_at,NULL'],
        ]);

        $roleIds = $data['roles'] ?? [];
        $roles = $roleIds ? Role::whereIn('id', $roleIds)->get() : collect();
        $allowedGuards = ['web', 'sanctum'];

        // Normalizar a roles con guard 'web' (los asignamos siempre con web para consistencia)
        $normalized = $roles->map(function ($role) use ($allowedGuards) {
            if (!in_array($role->guard_name, $allowedGuards, true)) {
                return null;
            }
            if ($role->guard_name === 'web') {
                return $role;
            }
            // RBAC v2 (Fase 6, Paso 3): scoped por team_id, ver mismo fix en
            // InvitationAcceptanceService::resolveRoleForGuard().
            return Role::where('team_id', $role->team_id)
                ->where('name', $role->name)
                ->where('guard_name', 'web')
                ->first() ?? $role;
        })->filter();

        if ($roles->count() !== $normalized->count()) {
            return response()->json([
                'message' => 'Roles incompatibles con el guard actual',
            ], 422);
        }

        $user->syncRoles($normalized->unique('id'));
        User::forgetPermissionCache($user);

        // Activar solo si tiene un rol distinto de visitante (visitante es solo lectura hasta que admin asigne rol)
        if ($user->status === 'pending_admin' && $user->roles()->count() > 0 && !($user->roles()->count() === 1 && $user->hasRole('visitante'))) {
            $user->update(['status' => 'active']);
        }

        return response()->noContent();
    }
}
