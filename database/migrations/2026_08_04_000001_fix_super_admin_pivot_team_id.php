<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC v2 (Fase 6), fix: 2026_07_15_000003 fijó `roles.team_id` = 0 para
 * la fila `super_admin`, pero NUNCA tocó `model_has_roles.team_id` -- el
 * pivote que registra a QUÉ team pertenece la ASIGNACIÓN de un usuario
 * concreto. Ese pivote se había poblado antes (2026_07_15_000001, backfill
 * genérico) con el team_id "operativo" del usuario en ese momento (su
 * client_id/site efectivo), no con el centinela de plataforma.
 *
 * Resultado real (encontrado en vivo, no teórico): ApplyPgsqlTenantRls fija
 * getPermissionsTeamId()=0 antes de llamar hasRole('super_admin') (para
 * evaluarlo con el team_id correcto de la fila `roles`), pero Spatie
 * también filtra model_has_roles.team_id=0 en esa consulta -- si el pivote
 * quedó en otro team_id, hasRole('super_admin') devuelve false en
 * silencio. OperatorScopeService::bypassesOperatorScope() depende
 * directamente de ese hasRole(), así que un super_admin real cae al
 * scope de operador/site y deja de ver casi todo (visto en local: listado
 * de usuarios vacío para el admin de la demo).
 *
 * Fix: alinear cada asignación de `super_admin` en model_has_roles con el
 * team_id que ya tiene la fila `roles` (el centinela de plataforma).
 */
return new class extends Migration
{
    public function up(): void
    {
        $superAdminRoleIds = DB::table('roles')
            ->where('name', 'super_admin')
            ->pluck('team_id', 'id');

        foreach ($superAdminRoleIds as $roleId => $roleTeamId) {
            DB::table('model_has_roles')
                ->where('role_id', $roleId)
                ->where(function ($q) use ($roleTeamId) {
                    $roleTeamId === null ? $q->whereNotNull('team_id') : $q->where('team_id', '!=', $roleTeamId);
                })
                ->update(['team_id' => $roleTeamId]);
        }
    }

    public function down(): void
    {
        // No reversible: no se guardó el team_id previo de cada pivote.
    }
};
