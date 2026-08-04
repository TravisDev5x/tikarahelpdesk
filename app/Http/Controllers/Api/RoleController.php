<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    /**
     * GET /api/roles
     *
     * RBAC v2 (Fase 6, Paso 3): scoped por team_id -- antes devolvía TODOS
     * los roles del sistema, de TODOS los tenants (cualquier usuario
     * autenticado, sin roles.manage). Mismo criterio de visibilidad que
     * Spatie usa internamente para hasRole()/permissions (whereNull(team)
     * OR team=actual): un tenant ve sus propias plantillas + los roles
     * "globales" sin dueño (legacy de FullDemoSeeder, o futuros roles de
     * plataforma), nunca las plantillas de OTRO tenant.
     */
    public function index()
    {
        $guard = config('auth.defaults.guard', 'web');
        $guards = collect([$guard, 'web', 'sanctum'])->unique()->all();
        $teamId = getPermissionsTeamId();

        return Role::with('permissions')
            ->whereIn('guard_name', $guards)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $teamId))
            ->orderBy('guard_name')
            ->orderBy('name')
            ->get();
    }

    /**
     * POST /api/roles
     */
    public function store(Request $request)
    {
        // Hardcoded 'web' -- ver nota completa en PermissionController::store().
        // Dentro de una ruta auth:sanctum, config('auth.defaults.guard') ya
        // no es confiable (Authenticate::authenticate() lo muta a 'sanctum').
        $guard = 'web';
        $teamId = getPermissionsTeamId();

        $data = $request->validate([
            'name' => [
                'required',
                'min:3',
                // team_id explícito en el criterio de unicidad -- sin esto,
                // el nombre de una plantilla de OTRO tenant bloquearía la
                // creación aquí (unique ya es (team_id, name, guard_name)
                // a nivel de DB desde RBAC v2, la validación debe calzar).
                Rule::unique('roles', 'name')->where('guard_name', $guard)->where('team_id', $teamId),
            ],
        ]);

        $role = Role::create([
            'team_id' => $teamId,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'guard_name' => $guard,
        ]);

        return response()->json($role, 201);
    }

    /**
     * PUT /api/roles/{role}
     */
    public function update(Request $request, Role $role)
    {
        $this->assertVisibleToCurrentTeam($role);

        $guard = $role->guard_name ?? 'web';

        $data = $request->validate([
            'name' => [
                'required',
                'min:3',
                Rule::unique('roles', 'name')
                    ->where('guard_name', $guard)
                    ->where('team_id', $role->team_id)
                    ->ignore($role->id),
            ],
        ]);

        $role->name = $data['name'];
        $role->slug = Str::slug($data['name']);
        $role->guard_name = $guard;
        $role->save();

        return response()->json($role);
    }

    /**
     * DELETE /api/roles/{role}
     */
    public function destroy(Role $role)
    {
        $this->assertVisibleToCurrentTeam($role);

        // Clear assignments before delete to avoid orphaned relations
        $role->users()->detach();
        $role->delete();

        return response()->noContent();
    }

    /**
     * RBAC v2 (Fase 6, Paso 3): el route model binding de {role} no filtra
     * por team_id -- sin este chequeo, un admin de un tenant podría editar/
     * borrar la plantilla de OTRO tenant adivinando su ID numérico. 403 en
     * vez de 404 porque la ausencia de permiso, no la ausencia del recurso,
     * es la razón real (mismo criterio que el resto de la API de tenants).
     */
    private function assertVisibleToCurrentTeam(Role $role): void
    {
        $teamId = getPermissionsTeamId();

        if ($role->team_id !== null && (int) $role->team_id !== (int) $teamId) {
            abort(403, 'No tienes acceso a esta plantilla de rol.');
        }
    }
}
