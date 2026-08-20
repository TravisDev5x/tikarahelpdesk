<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * super_admin recibió una COPIA FIJA de los permisos de 'admin' en
 * 2026_07_09_000013_seed_permissions_and_roles.php -- no se vuelve a
 * sincronizar automáticamente cuando se agregan permisos nuevos (mismo gap
 * ya existente para tickets.reassign, que tampoco lo tiene; no introducido
 * por esta migración, solo evitado para inventory.*).
 *
 * Sin esto, un usuario con AMBOS roles admin+super_admin (como el admin
 * demo de FullDemoSeeder) nunca ve estos permisos: bypassesOperatorScope()
 * detecta 'super_admin' primero y deja fijo el team_id centinela para el
 * resto del request (ver App\Http\Middleware\ApplyPgsqlTenantRls),
 * ignorando los permisos del rol 'admin' (team_id real) aunque el usuario
 * también lo tenga.
 */
return new class extends Migration
{
    private array $permissions = [
        'inventory.manage_config',
        'inventory.manage_assets',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->value('id');
        if (! $roleId) {
            return;
        }

        foreach ($this->permissions as $name) {
            $permId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            if (! $permId) {
                continue;
            }
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permId,
                'role_id' => $roleId,
            ]);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->value('id');
        if (! $roleId) {
            return;
        }

        $permIds = DB::table('permissions')->whereIn('name', $this->permissions)->pluck('id');
        DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permIds)
            ->delete();
    }
};
