<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Panel de asignación de site_user fuera de onboarding (auditoría
 * 2026-08-11, deuda documentada en docs/PENDING.md desde Fase 7.7):
 * permiso nuevo, separado de users.manage a propósito -- "puede editar
 * datos/roles de un usuario" y "puede asignar sedes" son capacidades
 * distintas. No requiere backfill: EnsurePermissionOrAdmin bypasea por
 * hasRole('admin') antes de revisar permisos, así que los admins ya lo
 * tienen gratis; el resto se concede vía TenantRoleSeeder (supervisor) o
 * desde la UI de roles por tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['web', 'sanctum'] as $guard) {
            DB::table('permissions')->insertOrIgnore([
                'name' => 'sites.assign_staff',
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'sites.assign_staff')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
