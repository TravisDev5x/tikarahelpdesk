<?php

namespace App\Services;

use App\Models\AuthorizationObject;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * RBAC v2 (Fase 6), Paso 5: crea/edita plantillas (roles team-scoped) a
 * partir de una selección Full/Solo lectura/Ninguno por objeto del
 * catálogo (AuthorizationObject), en vez de pedirle al admin del tenant
 * que arme una lista de nombres de permisos de Spatie a mano.
 */
class RoleTemplateService
{
    public const LEVEL_FULL = 'full';

    public const LEVEL_READ = 'read';

    public const LEVEL_NONE = 'none';

    /**
     * @param  array<int, array{key: string, level: string}>  $objectSelections
     * @return array<int, string> nombres de permisos de Spatie (ya existentes, ninguno se inventa)
     */
    public function resolvePermissionNames(array $objectSelections): array
    {
        $keys = collect($objectSelections)->pluck('key')->filter()->unique()->all();
        $objects = AuthorizationObject::whereIn('key', $keys)->get()->keyBy('key');

        $names = collect();

        foreach ($objectSelections as $selection) {
            $object = $objects->get($selection['key'] ?? null);
            if (! $object) {
                continue;
            }

            $level = $selection['level'] ?? self::LEVEL_NONE;

            if ($level === self::LEVEL_FULL && $object->full_permission) {
                $names->push($object->full_permission);
            } elseif ($level === self::LEVEL_READ && $object->read_permission) {
                $names->push($object->read_permission);
            }
        }

        return $names->unique()->values()->all();
    }

    /**
     * Crea una plantilla nueva dentro del team_id vigente
     * (getPermissionsTeamId(), fijado por ApplyPgsqlTenantRls para el
     * admin del tenant que hace la llamada).
     *
     * @param  array<int, array{key: string, level: string}>  $objectSelections
     */
    public function createTemplate(string $name, string $scopeArchetype, array $objectSelections, string $guard = 'web'): Role
    {
        $teamId = getPermissionsTeamId();

        $role = Role::create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'guard_name' => $guard,
            'scope_archetype' => $scopeArchetype,
        ]);

        $role->syncPermissions($this->resolvePermissionNames($objectSelections));

        return $role;
    }

    /**
     * @param  array<int, array{key: string, level: string}>  $objectSelections
     */
    public function updateTemplate(Role $role, string $name, string $scopeArchetype, array $objectSelections): Role
    {
        $role->update([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'scope_archetype' => $scopeArchetype,
        ]);

        $role->syncPermissions($this->resolvePermissionNames($objectSelections));

        return $role->fresh();
    }

    /**
     * Solo lectura: qué permisos vienen de la(s) plantilla(s) del usuario
     * vs. cuáles son overrides directos (Spatie nativo, sin tabla
     * paralela) -- getPermissionsViaRoles() vs getDirectPermissions().
     *
     * @return array{via_roles: Collection<int, string>, direct: Collection<int, string>, roles: Collection<int, string>}
     */
    public function permissionBreakdownFor(User $user): array
    {
        return [
            'via_roles' => $user->getPermissionsViaRoles()->pluck('name')->unique()->values(),
            'direct' => $user->getDirectPermissions()->pluck('name')->unique()->values(),
            'roles' => $user->roles()->pluck('name')->values(),
        ];
    }
}
