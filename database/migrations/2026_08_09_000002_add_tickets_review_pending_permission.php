<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Nuevo permiso para la cola de revisión manual de correos no reconocidos
 * (docs/PENDING_TICKET_REVIEW.md). No requiere backfill de roles/tenants:
 * EnsurePermissionOrAdmin bypasea por hasRole('admin') antes de revisar
 * permisos, así que los admins ya lo tienen gratis; el resto se concede
 * explícitamente desde la UI de roles por tenant cuando se quiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['web', 'sanctum'] as $guard) {
            DB::table('permissions')->insertOrIgnore([
                'name' => 'tickets.review_pending',
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'tickets.review_pending')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
