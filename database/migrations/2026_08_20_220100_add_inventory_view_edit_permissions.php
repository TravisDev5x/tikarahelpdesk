<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permisos granulares de Inventario (fase 7.4, retomada tras pausa en
 * docs/INVENTORY_ROADMAP.md): 3 niveles reales sobre Activos --
 * inventory.view_assets (solo ver), inventory.edit_assets (crear/editar/
 * ciclo de vida/componentes/mantenimientos/import, SIN eliminar),
 * inventory.manage_assets (ya existía -- ahora es el nivel completo,
 * incluye eliminar). inventory.manage_config (catálogos) NO se toca --
 * decisión de producto: el split solo aplica a Activos.
 *
 * A diferencia de TenantRoleSeeder (que también hace insertOrIgnore de
 * estas filas para tenants nuevos), esta migración las crea aquí mismo en
 * vez de depender de que algún tenant ya haya onboardeado -- mismo gap ya
 * documentado en 2026_08_16_210000_backfill_super_admin_inventory_permissions
 * (esa migración asume que las filas de permissions ya existen y no
 * garantiza que existan en una instalación nueva).
 */
return new class extends Migration
{
    private array $permissions = [
        'inventory.view_assets',
        'inventory.edit_assets',
    ];

    public function up(): void
    {
        foreach (['web', 'sanctum'] as $guard) {
            foreach ($this->permissions as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // super_admin recibió una copia fija de los permisos de 'admin' en
        // seed time -- no se resincroniza sola cuando aparecen permisos
        // nuevos (mismo motivo que la migración de backfill de
        // inventory.manage_assets/manage_config).
        $roleId = DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->value('id');
        if ($roleId) {
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
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->value('id');
        if ($roleId) {
            $permIds = DB::table('permissions')->whereIn('name', $this->permissions)->pluck('id');
            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permIds)
                ->delete();
        }

        DB::table('permissions')->whereIn('name', $this->permissions)->delete();
    }
};
