<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    /**
     * POST /api/roles/{role}/permissions
     * Sync permissions for a role.
     */
    public function sync(Request $request, Role $role)
    {
        // RBAC v2 (Fase 6, Paso 3): mismo chequeo que RoleController -- sin
        // esto, cualquier admin podría reescribir los permisos de la
        // plantilla de OTRO tenant adivinando el ID del rol.
        $teamId = getPermissionsTeamId();
        if ($role->team_id !== null && (int) $role->team_id !== (int) $teamId) {
            abort(403, 'No tienes acceso a esta plantilla de rol.');
        }

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $permissionIds = $data['permissions'] ?? [];
        // Solo permisos del mismo guard que el rol (evita mezclar web/sanctum y PermissionDoesNotExist)
        $permissions = $permissionIds
            ? Permission::whereIn('id', $permissionIds)->where('guard_name', $role->guard_name)->get()
            : [];
        $role->syncPermissions($permissions);

        User::forgetPermissionCacheForRole($role->name);

        return response()->noContent();
    }
}
