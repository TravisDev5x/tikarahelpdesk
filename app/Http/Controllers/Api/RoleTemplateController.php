<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthorizationObject;
use App\Models\Role;
use App\Services\RoleTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RBAC v2 (Fase 6), Paso 5: plantillas editables por tenant -- nombre
 * libre + scope_archetype obligatorio + permisos por objeto del catálogo
 * (Full/Editar/Solo lectura/Ninguno -- "Editar" solo aplica a objetos con
 * edit_permission, fase 7.4), en vez de los 4 roles fijos globales.
 * Gateado por roles.manage (perm: en routes/api.php), que ya queda
 * team-scoped automáticamente por ApplyPgsqlTenantRls (Fase 6, Paso 1).
 */
class RoleTemplateController extends Controller
{
    public function __construct(private RoleTemplateService $templates) {}

    /**
     * POST /api/role-templates
     */
    public function store(Request $request)
    {
        // Hardcoded 'web' -- ver nota en PermissionController::store()
        // (config('auth.defaults.guard') no es confiable dentro de rutas
        // auth:sanctum, Authenticate::authenticate() ya lo mutó a 'sanctum'
        // para cuando este código corre).
        $guard = 'web';
        $teamId = getPermissionsTeamId();

        $data = $request->validate([
            'name' => [
                'required', 'min:3',
                Rule::unique('roles', 'name')->where('guard_name', $guard)->where('team_id', $teamId),
            ],
            'scope_archetype' => ['required', Rule::in(['admin', 'supervisor', 'agente', 'solicitante'])],
            'objects' => ['array'],
            'objects.*.key' => ['required', 'string', Rule::exists('authorization_objects', 'key')],
            'objects.*.level' => ['required', Rule::in(['full', 'edit', 'read', 'none'])],
        ]);

        $role = $this->templates->createTemplate(
            $data['name'],
            $data['scope_archetype'],
            $data['objects'] ?? [],
            $guard
        );

        return response()->json($role->load('permissions'), 201);
    }

    /**
     * PUT /api/role-templates/{role}
     */
    public function update(Request $request, Role $role)
    {
        $this->assertVisibleToCurrentTeam($role);

        $guard = $role->guard_name;

        $data = $request->validate([
            'name' => [
                'required', 'min:3',
                Rule::unique('roles', 'name')->where('guard_name', $guard)->where('team_id', $role->team_id)->ignore($role->id),
            ],
            'scope_archetype' => ['required', Rule::in(['admin', 'supervisor', 'agente', 'solicitante'])],
            'objects' => ['array'],
            'objects.*.key' => ['required', 'string', Rule::exists('authorization_objects', 'key')],
            'objects.*.level' => ['required', Rule::in(['full', 'edit', 'read', 'none'])],
        ]);

        $role = $this->templates->updateTemplate($role, $data['name'], $data['scope_archetype'], $data['objects'] ?? []);

        return response()->json($role->load('permissions'));
    }

    /**
     * GET /api/authorization-objects
     * Catálogo global (no por tenant) para armar el selector Full/Solo lectura/Ninguno.
     */
    public function catalog()
    {
        return AuthorizationObject::whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get();
    }

    private function assertVisibleToCurrentTeam(Role $role): void
    {
        $teamId = getPermissionsTeamId();
        if ($role->team_id !== null && (int) $role->team_id !== (int) $teamId) {
            abort(403, 'No tienes acceso a esta plantilla de rol.');
        }
    }
}
