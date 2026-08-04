<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\OperatorScopeService;
use App\Services\TenantClientResolver;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establece variables de sesión PostgreSQL para RLS en cada petición
 * autenticada, y (RBAC v2, Fase 6) el team_id de spatie/laravel-permission.
 *
 * El team_id se resuelve SIEMPRE que haya usuario autenticado, sin
 * importar si RLS está habilitado -- a diferencia de RLS (solo aplica con
 * Postgres + TENANCY_PGSQL_RLS=true), el sistema de roles/permisos de
 * Spatie corre en cualquier driver, así que este es el único punto que ya
 * resuelve el tenant lo bastante temprano (prependeado a la lista de
 * prioridad en bootstrap/app.php) como para engancharlo ahí también, en
 * vez de duplicar la resolución en un middleware nuevo.
 */
class ApplyPgsqlTenantRls
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $this->applyPermissionsTeam($user);
        }

        if (! PgsqlRowLevelSecurity::enabled()) {
            return $next($request);
        }

        if (! $user) {
            return $next($request);
        }

        PgsqlRowLevelSecurity::applyForUser($user);

        try {
            return $next($request);
        } finally {
            PgsqlRowLevelSecurity::clear();
        }
    }

    /**
     * super_admin (OperatorScopeService::bypassesOperatorScope) usa el
     * team_id centinela config('tenancy.super_admin_team_id') -- NUNCA
     * null, porque model_has_roles.team_id/model_has_permissions.team_id
     * son parte de una primary key compuesta y ninguna columna de PK
     * admite NULL (verificado). El resto de los usuarios usan su
     * client_id efectivo (TenantClientResolver, el mismo que ya usa
     * PgsqlRowLevelSecurity::applyForUser() para USER_CLIENT_ID).
     *
     * Ampliación sobre el diseño confirmado en el Paso 0.5 (ese solo
     * cubría super_admin explícitamente): CUALQUIER usuario sin client_id
     * resolvible -- típicamente is_operator (MSP) sin un client/site
     * propio, porque un operador puede administrar varios clients -- cae
     * al mismo centinela de plataforma, no a null. Null no es una opción
     * válida aquí por la misma razón que en super_admin (violaría la PK
     * compuesta al asignar/chequear roles), así que "sin tenant único
     * resoluble" y "de plataforma" comparten el mismo bucket. No es una
     * distinción perfecta (un operador no es super_admin), pero evita
     * dejar a esos usuarios con team_id=null, que rompe en seco
     * cualquier chequeo de rol/permiso posterior.
     */
    private function applyPermissionsTeam(User $user): void
    {
        // Dependencia circular real (encontrada por test, no anticipada en
        // el diseño original): bypassesOperatorScope() llama
        // hasRole('super_admin'), que YA es team-scoped -- si no fijamos
        // ALGO antes de preguntar, esa comprobación hereda el team_id que
        // dejó el request ANTERIOR (de otro usuario, quizás de otro
        // tenant), no el de este usuario. Se fija el centinela primero
        // como punto de partida limpio y determinista -- el pivote de
        // super_admin vive ahí, así que si el usuario SÍ es super_admin la
        // comprobación ya sale correcta con ese contexto. Si no lo es, se
        // sobrescribe abajo con su client_id real.
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));

        if (app(OperatorScopeService::class)->bypassesOperatorScope($user)) {
            return;
        }

        $clientId = app(TenantClientResolver::class)->resolve($user);

        setPermissionsTeamId($clientId ?? config('tenancy.super_admin_team_id'));

        // bypassesOperatorScope() de arriba ya llamó hasRole('super_admin'),
        // que cachea la relación 'roles' de Eloquent EN ESTA instancia de
        // $user -- bajo el team_id centinela, no el real recién fijado. Sin
        // invalidarla, cualquier chequeo de rol/permiso posterior en la
        // misma request (middleware perm:, TicketPolicy::
        // highestScopeArchetype()) reutiliza esa lista vacía en vez de
        // volver a consultarla bajo el team correcto. hasRole() solo toca
        // 'roles' (via loadMissing), no 'permissions', así que no hace
        // falta invalidar nada más aquí.
        $user->unsetRelation('roles');
    }
}
