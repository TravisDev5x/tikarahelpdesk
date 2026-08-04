<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC v2, Paso 1: activa "teams" de spatie/laravel-permission (client_id
 * como team_id) para que admin/supervisor/agente/solicitante dejen de ser
 * 4 roles GLOBALES y pasen a ser plantillas por tenant.
 *
 * Adaptado del stub oficial del paquete
 * (vendor/spatie/laravel-permission/database/migrations/add_teams_fields.php.stub),
 * con dos ajustes deliberados sobre el stub:
 *
 * 1. NO se toca la tabla `permissions` -- el propio stub tampoco lo hace
 *    (confirmado leyendo su código y config/permission.php). Los permisos
 *    son un catálogo global igual para todos los tenants; lo que se vuelve
 *    por-tenant es quién tiene qué ROL (roles.team_id) y las asignaciones
 *    directas (model_has_roles.team_id / model_has_permissions.team_id).
 * 2. `roles` en este proyecto tiene una columna `slug` propia (no es de
 *    Spatie) con su propio unique(['slug','guard_name']) -- el stub oficial
 *    no sabe de su existencia, así que también hay que migrarlo a incluir
 *    team_id o quedaría con la misma colisión global que se está
 *    corrigiendo.
 *
 * team_id en model_has_roles/model_has_permissions es NOT NULL (es parte
 * de la primary key compuesta -- una PK no admite NULL en ningún motor).
 * Por eso super_admin (rol de plataforma, cross-tenant) NO usa NULL como
 * "team_id universal": usa el centinela config('tenancy.super_admin_team_id')
 * (0, nunca colisiona con un client_id real) de forma consistente en el
 * rol, en cada asignación y en cada request -- ver ApplyPgsqlTenantRls.
 *
 * Sin backfill de datos reales (confirmado: no hay tenants en producción).
 * Los model_has_roles/model_has_permissions existentes en dev/test quedan
 * con el default de Spatie (team_id=1, sin significado real) -- Paso 2
 * vuelve a sembrar las plantillas por tenant desde cero con team_id
 * correcto para los fixtures de test/dev.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'team_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->after('id');
                $table->index('team_id', 'roles_team_id_index');

                $table->dropUnique('roles_name_guard_name_unique');
                $table->dropUnique('roles_slug_guard_name_unique');
                $table->unique(['team_id', 'name', 'guard_name'], 'roles_team_id_name_guard_name_unique');
                $table->unique(['team_id', 'slug', 'guard_name'], 'roles_team_id_slug_guard_name_unique');
            });
        }

        if (! Schema::hasColumn('model_has_roles', 'team_id')) {
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->default(1);
                $table->index('team_id', 'model_has_roles_team_id_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['role_id']);
                }
                $table->dropPrimary();

                $table->primary(
                    ['team_id', 'role_id', 'model_id', 'model_type'],
                    'model_has_roles_role_model_type_primary'
                );

                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
                }
            });
        }

        if (! Schema::hasColumn('model_has_permissions', 'team_id')) {
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->default(1);
                $table->index('team_id', 'model_has_permissions_team_id_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['permission_id']);
                }
                $table->dropPrimary();

                $table->primary(
                    ['team_id', 'permission_id', 'model_id', 'model_type'],
                    'model_has_permissions_permission_model_type_primary'
                );

                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
                }
            });
        }

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Schema::table('model_has_permissions', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['permission_id']);
            }
            $table->dropPrimary();
            $table->dropColumn('team_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            }
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['role_id']);
            }
            $table->dropPrimary();
            $table->dropColumn('team_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_team_id_name_guard_name_unique');
            $table->dropUnique('roles_team_id_slug_guard_name_unique');
            $table->dropColumn('team_id');
            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
            $table->unique(['slug', 'guard_name'], 'roles_slug_guard_name_unique');
        });
    }
};
