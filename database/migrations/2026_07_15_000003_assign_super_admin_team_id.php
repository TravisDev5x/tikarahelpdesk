<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC v2 (Fase 6), Paso 0.5/1: la fila `super_admin` (sembrada por
 * 2026_07_09_000013_seed_permissions_and_roles sin team_id, ahora NULL
 * tras 2026_07_15_000001) necesita el team_id centinela de plataforma
 * -- ver config('tenancy.super_admin_team_id') para el porqué de 0 en vez
 * de NULL (model_has_roles.team_id es parte de una primary key compuesta,
 * ninguna columna de PK admite NULL). ApplyPgsqlTenantRls ya fija ese
 * mismo centinela como contexto para cualquier usuario
 * OperatorScopeService::bypassesOperatorScope() -- sin este ajuste, el
 * rol quedaría con team_id NULL y jamás calzaría con el team_id concreto
 * que el middleware fija en cada request.
 *
 * NO se toca la fila legacy 'admin' (también sembrada sin team_id ahí) --
 * queda huérfana a propósito: cada tenant sembrará su propia plantilla
 * 'admin' con team_id real vía tenants:seed-default-roles (Paso 2), y
 * TenantRoleSeeder ya sabe convivir con esa fila huérfana (usa
 * Role::query()->create(), no Role::create(), para no chocar con el
 * chequeo de nombre-global de Spatie).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', 'super_admin')
            ->whereNull('team_id')
            ->update(['team_id' => config('tenancy.super_admin_team_id')]);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'super_admin')
            ->where('team_id', config('tenancy.super_admin_team_id'))
            ->update(['team_id' => null]);
    }
};
