<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientScopeService
{
    public function __construct(
        protected OperatorScopeService $operatorScope,
        protected TenantClientResolver $tenantResolver,
        protected TenantContextService $tenantContext
    ) {}

    /** Portal empresa final por subdominio: solo este client_id. */
    public function applyStrictPortalClient(Builder $query, User $user, string $clientColumn = 'client_id'): Builder
    {
        $enforced = $this->tenantContext->enforcedClientId();
        if (! $enforced) {
            return $query;
        }

        return $query->where($clientColumn, $enforced);
    }

    /**
     * Sin restricción a un único cliente (plataforma o todos los clientes del MSP).
     */
    public function bypassesClientScope(User $user): bool
    {
        return $this->operatorScope->bypassesOperatorScope($user)
            || $this->operatorScope->hasMspWideAccess($user);
    }

    /**
     * Asigna sitio y cliente del usuario solicitante al crear un ticket.
     *
     * Requester sin site "hogar" ya NO es error (Fase 2 del sprint maestro):
     * el ticket se crea con site_id NULL — estado "sin site asignado",
     * visible solo para admin/supervisor hasta que alguien lo asigne.
     *
     * @return \Illuminate\Http\JsonResponse|null Error 422 o null si OK
     */
    public function stampTicketSiteFromUser(User $user, array &$data): ?\Illuminate\Http\JsonResponse
    {
        $user->loadMissing('site:id,name,client_id');

        $data['site_id'] = $user->site_id ? (int) $user->site_id : null;
        $data['client_id'] = $this->resolveUserClientId($user);

        return null;
    }

    /**
     * Bloquea acceso operativo si tiene permiso de área sin area_id (config incompleta).
     */
    public function guardOperationalModuleAccess(User $user, string $module): ?\Illuminate\Http\JsonResponse
    {
        // Admin de plataforma (super_admin) sin permiso platform.view_internals:
        // puede ver clientes y estadísticas, pero no datos operativos internos.
        if ($this->operatorScope->isPlatformAdminBlockedFromInternals($user)) {
            $label = $module === 'incidents' ? 'incidencias' : 'tickets';

            return response()->json([
                'message' => "Acceso de plataforma: los {$label} internos de cada cliente no están disponibles en este perfil.",
                'code'    => 'platform_admin_restricted',
            ], 403);
        }

        if ($this->operatorScope->bypassesOperatorScope($user) || $this->operatorScope->hasMspWideAccess($user)) {
            return null;
        }

        if ($this->tenantResolver->hasAreaPermissionWithoutArea($user, $module)) {
            $label = $module === 'incidents' ? 'incidencias' : 'tickets';

            return response()->json([
                'message' => "Asigna tu área para acceder a {$label}",
            ], 403);
        }

        return null;
    }

    public function syncTicketClientFromSite(int $siteId): ?int
    {
        return $this->syncClientIdFromSite($siteId);
    }

    public function syncClientIdFromSite(int $siteId): ?int
    {
        $clientId = Site::where('id', $siteId)->value('client_id');

        return $clientId ? (int) $clientId : null;
    }

    /** Restringe incidencias al cliente o al operador MSP del usuario. */
    public function applyIncidentScope(Builder $query, User $user): Builder
    {
        if ($this->tenantContext->enforcedClientId()) {
            return $this->applyStrictPortalClient($query, $user);
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->operatorScope->hasMspWideAccess($user)) {
            return $this->operatorScope->applyOnIncidents($query, $user);
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            return $query->where(function ($q) use ($clientId) {
                $q->where('client_id', $clientId)
                    ->orWhereIn('site_id', $this->siteIdsSubquery($clientId));
            });
        }

        return $this->applyIncidentsWithoutTenant($query, $user);
    }

    /**
     * Restringe activos de inventario al cliente o al operador MSP del
     * usuario (fase 2, port desde HelpdeskECD2026). Mismo esqueleto que
     * applyIncidentScope, delega el ramal MSP-wide a
     * OperatorScopeService::applyOnInventoryAssets().
     */
    public function applyInventoryAssetScope(Builder $query, User $user): Builder
    {
        if ($this->tenantContext->enforcedClientId()) {
            return $this->applyStrictPortalClient($query, $user);
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->operatorScope->hasMspWideAccess($user, 'inventory')) {
            return $this->operatorScope->applyOnInventoryAssets($query, $user);
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            return $query->where(function ($q) use ($clientId) {
                $q->where('client_id', $clientId)
                    ->orWhereIn('site_id', $this->siteIdsSubquery($clientId));
            });
        }

        return $query->whereRaw('0 = 1');
    }

    /**
     * Restringe componentes de inventario al cliente o al operador MSP del
     * usuario (fase 4, port desde HelpdeskECD2026). Filtra por client_id
     * directo -- a diferencia de assets, un componente no tiene site_id
     * propio (puede estar suelto, sin activo), así que no hay columna de
     * sede por la que hacer OR.
     */
    public function applyInventoryComponentScope(Builder $query, User $user): Builder
    {
        if ($this->tenantContext->enforcedClientId()) {
            return $this->applyStrictPortalClient($query, $user);
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->operatorScope->hasMspWideAccess($user, 'inventory')) {
            return $this->operatorScope->applyOnInventoryComponents($query, $user);
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('0 = 1');
    }

    /**
     * Restringe mantenimientos de inventario al cliente o al operador MSP
     * del usuario (fase 5, port desde HelpdeskECD2026). Calcado de
     * applyInventoryComponentScope -- inv_maintenances también filtra por
     * client_id directo, sin columna de sede propia.
     */
    public function applyInventoryMaintenanceScope(Builder $query, User $user): Builder
    {
        if ($this->tenantContext->enforcedClientId()) {
            return $this->applyStrictPortalClient($query, $user);
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->operatorScope->hasMspWideAccess($user, 'inventory')) {
            return $this->operatorScope->applyOnInventoryMaintenances($query, $user);
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('0 = 1');
    }

    /**
     * Restringe movimientos de inventario al cliente o al operador MSP del
     * usuario (fase 7.2, port desde HelpdeskECD2026 -- export de historial
     * de asignaciones). Calcado de applyInventoryComponentScope --
     * inv_movements también filtra por client_id directo. Hasta fase 7.1
     * los movimientos solo se leían indirectamente vía el activo padre
     * (InvMovementController::index() ya autoriza el activo primero); este
     * scope es para el export, que lee todo el tenant de una vez.
     */
    public function applyInventoryMovementScope(Builder $query, User $user): Builder
    {
        if ($this->tenantContext->enforcedClientId()) {
            return $this->applyStrictPortalClient($query, $user);
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->operatorScope->hasMspWideAccess($user, 'inventory')) {
            return $this->operatorScope->applyOnInventoryMovements($query, $user);
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('0 = 1');
    }

    public function incidentVisibleToUser(User $user, \App\Models\Incident $incident): bool
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $this->incidentBelongsToClient($incident, $enforced);
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return true;
        }

        if ($this->operatorScope->hasMspWideAccess($user)) {
            if ($this->operatorScope->usesOperatorMspWideScope($user)) {
                if ($this->operatorScope->usesLegacyMspWideAccess($user)) {
                    return true;
                }

                $operatorId = $this->operatorScope->resolveOperatorUserId($user);
                if (! $operatorId) {
                    return false;
                }
                $incident->loadMissing('site:id,client_id', 'client:id,operator_user_id');

                if ($incident->client_id) {
                    $op = Client::query()->where('id', $incident->client_id)->value('operator_user_id');

                    return (int) $op === $operatorId;
                }

                if ($incident->site?->client_id) {
                    $op = Client::where('id', $incident->site->client_id)->value('operator_user_id');

                    return (int) $op === $operatorId;
                }

                return false;
            }
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            if ($incident->client_id) {
                return (int) $incident->client_id === $clientId;
            }
            $incident->loadMissing('site:id,client_id');

            return $incident->site && (int) $incident->site->client_id === $clientId;
        }

        if ($this->tenantResolver->isAreaScopedWithoutTenant($user, 'incidents')) {
            return $user->area_id && (int) $incident->area_id === (int) $user->area_id;
        }

        return (int) $incident->reporter_id === (int) $user->id;
    }

    /** @see TenantClientResolver */
    public function resolveUserClientId(User $user): ?int
    {
        return $this->tenantResolver->resolve($user);
    }

    /** Restringe tickets al cliente o al operador MSP del usuario. */
    public function applyTicketScope(Builder $query, User $user): Builder
    {
        return $this->operatorScope->applyOnTickets($query, $user);
    }

    /** Restringe listado de usuarios al mismo cliente u operador MSP. */
    public function applyUserScope(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where(function ($q) use ($enforced) {
                $q->where('users.client_id', $enforced)
                    ->orWhereIn('users.site_id', $this->siteIdsSubquery($enforced));
            });
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->operatorScope->usesOperatorMspWideScope($user)) {
            if ($this->operatorScope->usesLegacyMspWideAccess($user)) {
                return $query;
            }

            $operatorId = $this->operatorScope->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $query->whereRaw('0 = 1');
            }

            return $query->whereIn('users.site_id', function ($sub) use ($operatorId) {
                $sub->select('sites.id')
                    ->from('sites')
                    ->join('clients', 'clients.id', '=', 'sites.client_id')
                    ->where('clients.operator_user_id', $operatorId);
            });
        }

        $clientId = $this->resolveUserClientId($user);
        if (! $clientId) {
            return $query->where('users.id', $user->id);
        }

        return $query->whereIn('users.site_id', $this->siteIdsSubquery($clientId));
    }

    public function ticketVisibleToUser(User $user, \App\Models\Ticket $ticket): bool
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $this->ticketBelongsToClient($ticket, $enforced);
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return true;
        }

        if ($this->operatorScope->hasMspWideAccess($user)) {
            if ($this->operatorScope->usesOperatorMspWideScope($user)) {
                if ($this->operatorScope->usesLegacyMspWideAccess($user)) {
                    return true;
                }

                $operatorId = $this->operatorScope->resolveOperatorUserId($user);
                if (! $operatorId) {
                    return false;
                }
                $ticket->loadMissing('site:id,client_id', 'client:id,operator_user_id');

                if ($ticket->client_id) {
                    $op = \App\Models\Client::where('id', $ticket->client_id)->value('operator_user_id');

                    return (int) $op === $operatorId;
                }

                if ($ticket->site?->client_id) {
                    $op = \App\Models\Client::where('id', $ticket->site->client_id)->value('operator_user_id');

                    return (int) $op === $operatorId;
                }

                return false;
            }
        }

        $clientId = $this->resolveUserClientId($user);
        if (! $clientId) {
            return (int) $ticket->requester_id === (int) $user->id;
        }

        if ($ticket->client_id) {
            return (int) $ticket->client_id === $clientId;
        }

        $ticket->loadMissing('site:id,client_id');

        return $ticket->site && (int) $ticket->site->client_id === $clientId;
    }

    public function assertSiteAccessible(User $user, int $siteId): bool
    {
        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return Site::where('id', $siteId)->exists();
        }

        if ($this->operatorScope->usesOperatorMspWideScope($user)) {
            if ($this->operatorScope->usesLegacyMspWideAccess($user)) {
                return Site::where('id', $siteId)->exists();
            }

            $operatorId = $this->operatorScope->resolveOperatorUserId($user);
            if (! $operatorId) {
                return false;
            }

            return Site::where('id', $siteId)
                ->whereIn('client_id', function ($sub) use ($operatorId) {
                    $sub->select('id')->from('clients')->where('operator_user_id', $operatorId);
                })
                ->exists();
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            return Site::where('id', $siteId)->where('client_id', $clientId)->exists();
        }

        if ($this->tenantResolver->isAreaScopedWithoutTenant($user, 'tickets')
            || $this->tenantResolver->isAreaScopedWithoutTenant($user, 'incidents')) {
            return false;
        }

        return (int) $user->site_id === $siteId;
    }

    public function assertUserAccessible(User $user, int $targetUserId): bool
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            if ((int) $user->id === $targetUserId) {
                return true;
            }

            return DB::table('users')
                ->where('id', $targetUserId)
                ->where(function ($q) use ($enforced) {
                    $q->where('client_id', $enforced)
                        ->orWhereIn('site_id', $this->siteIdsSubquery($enforced));
                })
                ->exists();
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return true;
        }

        if ((int) $user->id === $targetUserId) {
            return true;
        }

        // Hallazgo real (2026-08-11): esta rama usaba hasMspWideAccess()
        // directo -- sin $module (default 'any'), da true con solo
        // tickets.manage_all, que TODO admin de tenant plano ya trae
        // (TenantRoleSeeder vía $allWebPermissions), sin ser operador MSP
        // de verdad. usesOperatorMspWideScope() es el guard correcto ya
        // establecido para este mismo caso en applyUserScope() e
        // incidentVisibleToUser() de este mismo archivo -- excluye a un
        // admin de tenant plano que sí resuelve su propio client_id/site,
        // aunque tenga manage_all. Sin este cambio, un admin de tenant
        // normal quedaba evaluado contra los sites del operador que
        // registró SU tenant (clients.operator_user_id, un dato de
        // procedencia) en vez de contra su propio tenant -- negaba acceso
        // a su propio equipo.
        if ($this->operatorScope->usesOperatorMspWideScope($user)) {
            if ($this->operatorScope->usesLegacyMspWideAccess($user)) {
                return true;
            }

            $operatorId = $this->operatorScope->resolveOperatorUserId($user);
            if (! $operatorId) {
                return false;
            }

            return DB::table('users')
                ->where('id', $targetUserId)
                ->whereIn('site_id', function ($sub) use ($operatorId) {
                    $sub->select('sites.id')
                        ->from('sites')
                        ->join('clients', 'clients.id', '=', 'sites.client_id')
                        ->where('clients.operator_user_id', $operatorId);
                })
                ->exists();
        }

        $clientId = $this->resolveUserClientId($user);
        if (! $clientId) {
            return false;
        }

        // Mismo OR client_id/site que ya usa la rama de portal estricto de
        // arriba (líneas 330-336) -- esta rama solo comparaba site_id, así
        // que un usuario vinculado directo por client_id sin site propio
        // (fundador de un tenant recién creado vía onboarding, o cualquier
        // alta con site_id null -- ver TenantClientResolver, que documenta
        // client_id como señal válida igual que site_id) salía como "no
        // accesible" para su propio tenant. Hallazgo real, no una regla
        // deliberada: la rama de portal ya lo hacía bien, esta no.
        return DB::table('users')
            ->where('id', $targetUserId)
            ->where(function ($q) use ($clientId) {
                $q->where('client_id', $clientId)
                    ->orWhereIn('site_id', $this->siteIdsSubquery($clientId));
            })
            ->exists();
    }

    /** Filtro opcional client_id (validado contra el alcance del usuario). */
    public function applyClientFilter(Request $request, User $user, Builder $query): void
    {
        if (! $request->filled('client_id')) {
            return;
        }

        $clientId = (int) $request->input('client_id');
        if ($clientId < 1) {
            return;
        }

        if (! $this->operatorScope->bypassesOperatorScope($user)) {
            if ($this->operatorScope->hasMspWideAccess($user)) {
                if (! $this->operatorScope->assertClientIdInScope($user, $clientId)) {
                    return;
                }
            } else {
                $own = $this->resolveUserClientId($user);
                if ($own !== $clientId) {
                    return;
                }
            }
        }

        $query->where(function ($q) use ($clientId) {
            $q->where('client_id', $clientId)
                ->orWhere(function ($sub) use ($clientId) {
                    $this->whereTicketSiteInClient($sub, $clientId);
                });
        });
    }

    /** Valida site_id en filtros de listados. */
    public function applySiteFilter(Request $request, User $user, Builder $query, string $column = 'site_id'): void
    {
        if (! $request->filled('site_id')) {
            return;
        }

        $siteId = (int) $request->input('site_id');
        if ($siteId < 1 || ! $this->assertSiteAccessible($user, $siteId)) {
            return;
        }

        $query->where($column, $siteId);
    }

    public function clientsForCatalog(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $this->operatorScope->clientsForCatalog($user);
    }

    public function sitesQueryForUser(?User $user): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('sites')->where('is_active', true);

        if (! $user) {
            return $q->whereRaw('0 = 1');
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $q;
        }

        if ($this->operatorScope->hasMspWideAccess($user)) {
            if ($this->operatorScope->usesLegacyMspWideAccess($user)) {
                return $q;
            }

            $operatorId = $this->operatorScope->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $q->whereRaw('0 = 1');
            }

            return $q->whereIn('client_id', function ($sub) use ($operatorId) {
                $sub->select('id')->from('clients')->where('operator_user_id', $operatorId);
            });
        }

        $clientId = $this->resolveUserClientId($user);
        if ($clientId) {
            $q->where('client_id', $clientId);
        } else {
            $q->whereRaw('0 = 1');
        }

        return $q;
    }

    public function usersQueryForCatalog(?User $user): \Illuminate\Database\Query\Builder
    {
        if (! $user) {
            return DB::table('users')->whereRaw('0 = 1');
        }

        $q = DB::table('users')->whereNull('deleted_at');

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $q;
        }

        if ($this->operatorScope->hasMspWideAccess($user)) {
            if ($this->operatorScope->usesLegacyMspWideAccess($user)) {
                return $q;
            }

            $operatorId = $this->operatorScope->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $q->whereRaw('0 = 1');
            }

            return $q->whereIn('site_id', function ($sub) use ($operatorId) {
                $sub->select('sites.id')
                    ->from('sites')
                    ->join('clients', 'clients.id', '=', 'sites.client_id')
                    ->where('clients.operator_user_id', $operatorId);
            });
        }

        if ($user->can('tickets.view_area') || $user->can('incidents.view_area')) {
            if ($user->area_id) {
                $q->where('area_id', $user->area_id);
            }
            $clientId = $this->resolveUserClientId($user);
            if ($clientId) {
                $q->whereIn('site_id', $this->siteIdsSubquery($clientId));
            }

            return $q;
        }

        return $q->whereRaw('0 = 1');
    }

    private function whereTicketSiteInClient(Builder $query, int $clientId): Builder
    {
        return $query->whereIn('site_id', $this->siteIdsSubquery($clientId));
    }

    private function applyIncidentsWithoutTenant(Builder $query, User $user): Builder
    {
        if ($this->tenantResolver->isAreaScopedWithoutTenant($user, 'incidents')) {
            return $query;
        }

        if ($user->can('incidents.view_own')) {
            return $query->where('reporter_id', $user->id);
        }

        return $query->whereRaw('0 = 1');
    }

    private function siteIdsSubquery(int $clientId): \Closure
    {
        return function ($sub) use ($clientId) {
            $sub->select('id')->from('sites')->where('client_id', $clientId);
        };
    }

    private function ticketBelongsToClient(\App\Models\Ticket $ticket, int $clientId): bool
    {
        if ($ticket->client_id) {
            return (int) $ticket->client_id === $clientId;
        }

        $ticket->loadMissing('site:id,client_id');

        return $ticket->site && (int) $ticket->site->client_id === $clientId;
    }

    private function incidentBelongsToClient(\App\Models\Incident $incident, int $clientId): bool
    {
        if ($incident->client_id) {
            return (int) $incident->client_id === $clientId;
        }

        $incident->loadMissing('site:id,client_id');

        return $incident->site && (int) $incident->site->client_id === $clientId;
    }
}
