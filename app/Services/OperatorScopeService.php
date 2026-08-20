<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class OperatorScopeService
{
    public function __construct(
        protected TenantClientResolver $tenantResolver,
        protected TenantContextService $tenantContext
    ) {}

    /** Plataforma: ve todos los operadores MSP y clientes. */
    public function bypassesOperatorScope(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Admin de plataforma (super_admin) bloqueado de datos operativos internos.
     *
     * Un super_admin VE la lista de clientes y sus estadísticas agregadas, pero NO
     * puede acceder a tickets/incidencias internas de cada cliente a menos que tenga
     * el permiso explícito 'platform.view_internals'.
     *
     * Usuarios que NO son super_admin → siempre devuelve false (su propio scope aplica).
     */
    public function isPlatformAdminBlockedFromInternals(User $user): bool
    {
        if (! $this->bypassesOperatorScope($user)) {
            return false;
        }

        return ! $user->can('platform.view_internals');
    }

    /**
     * Acceso transversal dentro del MSP (todos los clientes del operador).
     * Ya no implica ver toda la plataforma (eso es solo super_admin).
     */
    public function hasMspWideAccess(User $user, string $module = 'any'): bool
    {
        if ($this->bypassesOperatorScope($user)) {
            return true;
        }

        if ($user->is_operator || $user->can('clients.view_all')) {
            return true;
        }

        return match ($module) {
            'tickets'   => $user->can('tickets.manage_all'),
            'incidents' => $user->can('incidents.manage_all'),
            'inventory' => $user->can('inventory.manage_assets'),
            default     => $user->can('tickets.manage_all') || $user->can('incidents.manage_all'),
        };
    }

    /**
     * Acceso transversal a todos los clientes del operador MSP (no solo el tenant vinculado por sede/client_id).
     * Acepta $module ('tickets'|'incidents'|'any') para evitar que permisos de un módulo
     * eleven el scope en otro (ej: incidents.manage_all no debe dar MSP-wide en tickets).
     */
    public function usesOperatorMspWideScope(User $user, string $module = 'any'): bool
    {
        if ($this->bypassesOperatorScope($user) || ! $this->hasMspWideAccess($user, $module)) {
            return false;
        }

        if ($user->is_operator) {
            return true;
        }

        return $this->tenantResolver->resolve($user) === null;
    }

    /**
     * Usuario dueño del MSP cuyos clientes (clients.operator_user_id) aplican.
     */
    /**
     * Instalación legacy (dominio raíz): manage_all sin operador MSP vinculado.
     * No aplica en portal estricto por subdominio.
     */
    public function usesLegacyMspWideAccess(User $user): bool
    {
        if (! config('tenancy.legacy_msp_wide_access', false)) {
            return false;
        }

        if ($this->tenantContext->isStrictClientPortal()) {
            return false;
        }

        if ($this->bypassesOperatorScope($user) || ! $this->hasMspWideAccess($user)) {
            return false;
        }

        return $this->resolveOperatorUserId($user) === null;
    }

    /**
     * Usuarios MSP-wide sin operador resuelto (candidatos a is_operator antes de desactivar legacy).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function legacyOperatorCandidates(): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('is_operator', false)
            ->where('status', 'active')
            ->get()
            ->filter(function (User $user) {
                if ($this->bypassesOperatorScope($user) || ! $this->hasMspWideAccess($user)) {
                    return false;
                }

                return $this->resolveOperatorUserId($user) === null;
            })
            ->values();
    }

    public function resolveOperatorUserId(User $user): ?int
    {
        if ($user->is_operator) {
            return (int) $user->id;
        }

        if ($user->client_id) {
            $operatorId = Client::where('id', $user->client_id)->value('operator_user_id');
            if ($operatorId) {
                return (int) $operatorId;
            }
        }

        $user->loadMissing('site:id,client_id');
        if ($user->site?->client_id) {
            $operatorId = Client::where('id', $user->site->client_id)->value('operator_user_id');
            if ($operatorId) {
                return (int) $operatorId;
            }
        }

        return null;
    }

    public function applyOnClients(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where('id', $enforced);
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user)) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->where('operator_user_id', $operatorId);
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('id', $clientId);
    }

    public function applyOnSites(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where('client_id', $enforced);
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user)) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->whereIn('client_id', $this->clientIdsSubquery($operatorId));
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('client_id', $clientId);
    }

    public function applyOnTickets(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where(function ($q) use ($enforced) {
                $q->where('client_id', $enforced)
                    ->orWhereIn('site_id', function ($sub) use ($enforced) {
                        $sub->select('id')->from('sites')->where('client_id', $enforced);
                    });
            });
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user, 'tickets')) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->where(function ($q) use ($operatorId) {
                $q->whereIn('client_id', $this->clientIdsSubquery($operatorId))
                    ->orWhereIn('site_id', $this->siteIdsSubquery($operatorId));
            });
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $this->applyTicketsWithoutTenant($query, $user);
        }

        return $query->where(function ($q) use ($clientId) {
            $q->where('client_id', $clientId)
                ->orWhereIn('site_id', function ($sub) use ($clientId) {
                    $sub->select('id')->from('sites')->where('client_id', $clientId);
                });
        });
    }

    public function applyOnIncidents(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where(function ($q) use ($enforced) {
                $q->where('client_id', $enforced)
                    ->orWhereIn('site_id', function ($sub) use ($enforced) {
                        $sub->select('id')->from('sites')->where('client_id', $enforced);
                    });
            });
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user, 'incidents')) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->where(function ($q) use ($operatorId) {
                $q->whereIn('client_id', $this->clientIdsSubquery($operatorId))
                    ->orWhereIn('site_id', $this->siteIdsSubquery($operatorId));
            });
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $this->applyIncidentsWithoutTenant($query, $user);
        }

        return $query->where(function ($q) use ($clientId) {
            $q->where('client_id', $clientId)
                ->orWhereIn('site_id', function ($sub) use ($clientId) {
                    $sub->select('id')->from('sites')->where('client_id', $clientId);
                });
        });
    }

    /**
     * Inventario (fase 2, port desde HelpdeskECD2026) -- mismo esqueleto que
     * applyOnIncidents/applyOnTickets. Sin fallback "sin tenant" propio de
     * incidencias (reporter_id): un activo sin client_id resoluble
     * simplemente no se ve, no hay un dueño individual al que replegarse.
     */
    public function applyOnInventoryAssets(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where(function ($q) use ($enforced) {
                $q->where('client_id', $enforced)
                    ->orWhereIn('site_id', function ($sub) use ($enforced) {
                        $sub->select('id')->from('sites')->where('client_id', $enforced);
                    });
            });
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user, 'inventory')) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->where(function ($q) use ($operatorId) {
                $q->whereIn('client_id', $this->clientIdsSubquery($operatorId))
                    ->orWhereIn('site_id', $this->siteIdsSubquery($operatorId));
            });
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function ($q) use ($clientId) {
            $q->where('client_id', $clientId)
                ->orWhereIn('site_id', function ($sub) use ($clientId) {
                    $sub->select('id')->from('sites')->where('client_id', $clientId);
                });
        });
    }

    /**
     * Componentes de inventario (fase 4, port desde HelpdeskECD2026) --
     * mismo esqueleto que applyOnInventoryAssets. Sin fallback "sin tenant"
     * propio, igual razón que assets: no hay un dueño individual al que
     * replegarse.
     */
    public function applyOnInventoryComponents(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where('client_id', $enforced);
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user, 'inventory')) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->whereIn('client_id', $this->clientIdsSubquery($operatorId));
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('client_id', $clientId);
    }

    /**
     * Mantenimientos de inventario (fase 5, port desde HelpdeskECD2026) --
     * mismo esqueleto que applyOnInventoryComponents.
     */
    public function applyOnInventoryMaintenances(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where('client_id', $enforced);
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user, 'inventory')) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->whereIn('client_id', $this->clientIdsSubquery($operatorId));
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('client_id', $clientId);
    }

    /**
     * Movimientos de inventario (fase 7.2, port desde HelpdeskECD2026 --
     * export de historial de asignaciones) -- mismo esqueleto que
     * applyOnInventoryComponents/applyOnInventoryMaintenances.
     */
    public function applyOnInventoryMovements(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where('client_id', $enforced);
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user, 'inventory')) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->whereIn('client_id', $this->clientIdsSubquery($operatorId));
        }

        $clientId = $this->tenantResolver->resolve($user);
        if (! $clientId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('client_id', $clientId);
    }

    public function assertClientAccessible(User $user, Client $client): bool
    {
        if ($this->bypassesOperatorScope($user)) {
            return true;
        }

        if ($this->usesOperatorMspWideScope($user)) {
            if ($this->usesLegacyMspWideAccess($user)) {
                return true;
            }

            $operatorId = $this->resolveOperatorUserId($user);

            return $operatorId && (int) $client->operator_user_id === $operatorId;
        }

        $clientId = $this->tenantResolver->resolve($user);

        return $clientId && (int) $client->id === $clientId;
    }

    public function authorizeClient(User $user, Client $client): void
    {
        if (! $this->assertClientAccessible($user, $client)) {
            abort(403, 'No tienes acceso a este cliente.');
        }
    }

    /**
     * Acciones destructivas/administrativas sobre un Client (borrar, cancelar,
     * reactivar cuenta) NO son lo mismo que poder ver/editar los datos básicos:
     * assertClientAccessible() permite el caso "es mi propio tenant" (staff de
     * un cliente editando su propia info vía "Mi empresa"), pero ese mismo
     * caso NUNCA debe poder autoadministrarse -- solo quien gestiona el
     * cliente desde afuera (super_admin, u operador MSP dueño de ese client)
     * puede borrarlo. Deliberadamente NO reusa assertClientAccessible().
     */
    public function canDeleteClient(User $user, Client $client): bool
    {
        if ($this->bypassesOperatorScope($user)) {
            return true;
        }

        if ($this->usesOperatorMspWideScope($user)) {
            if ($this->usesLegacyMspWideAccess($user)) {
                return true;
            }

            $operatorId = $this->resolveOperatorUserId($user);

            return $operatorId && (int) $client->operator_user_id === $operatorId;
        }

        return false;
    }

    /** Alta de un tenant NUEVO: mismo criterio que canDeleteClient -- nunca el propio staff del tenant. */
    public function canCreateClients(User $user): bool
    {
        return $this->bypassesOperatorScope($user) || $user->is_operator;
    }

    /**
     * True cuando quien ve este Client es staff DEL PROPIO tenant (ej. "Mi
     * empresa"), no alguien administrándolo desde afuera (super_admin,
     * operador MSP). Distingue el panel de autoservicio (sin métricas
     * operativas, con "Solicitar cambio de plan" en vez de acciones
     * administrativas directas) de la vista de supervisión de un operador.
     */
    public function viewsOwnCompany(User $user, Client $client): bool
    {
        if ($this->bypassesOperatorScope($user) || $user->is_operator) {
            return false;
        }

        return $this->tenantResolver->resolve($user) === $client->id;
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public function nameRules(User $user, ?int $ignoreClientId = null): array
    {
        $rules = ['required', 'string', 'min:2', 'max:255'];

        if ($this->bypassesOperatorScope($user)) {
            $rules[] = Rule::unique('clients', 'name')->ignore($ignoreClientId);

            return $rules;
        }

        $operatorId = $this->resolveOperatorUserId($user) ?? ($user->is_operator ? $user->id : null);

        $unique = Rule::unique('clients', 'name')->ignore($ignoreClientId);
        if ($operatorId !== null) {
            $unique = $unique->where(fn ($q) => $q->where('operator_user_id', $operatorId));
        } else {
            $unique = $unique->where(fn ($q) => $q->whereNull('operator_user_id'));
        }

        $rules[] = $unique;

        return $rules;
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public function codeRules(User $user, ?int $ignoreClientId = null): array
    {
        $rules = ['nullable', 'string', 'max:20'];

        if ($this->bypassesOperatorScope($user)) {
            $rules[] = Rule::unique('clients', 'code')->ignore($ignoreClientId);

            return $rules;
        }

        $operatorId = $this->resolveOperatorUserId($user) ?? ($user->is_operator ? $user->id : null);

        $unique = Rule::unique('clients', 'code')->ignore($ignoreClientId);
        if ($operatorId !== null) {
            $unique = $unique->where(fn ($q) => $q->where('operator_user_id', $operatorId));
        } else {
            $unique = $unique->where(fn ($q) => $q->whereNull('operator_user_id'));
        }

        $rules[] = $unique;

        return $rules;
    }

    public function operatorUserIdForNewClient(User $user): ?int
    {
        if ($this->bypassesOperatorScope($user) && ! $user->is_operator) {
            return null;
        }

        return $this->resolveOperatorUserId($user) ?? ($user->is_operator ? (int) $user->id : null);
    }

    /**
     * @return list<object{id: int, name: string}>
     */
    public function clientsForCatalog(User $user, bool $activeOnly = true): array
    {
        $query = Client::query()->orderBy('name');
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $this->applyOnClients($query, $user)
            ->get(['id', 'name'])
            ->all();
    }

    public function assertClientIdInScope(User $user, int $clientId): bool
    {
        if ($clientId < 1) {
            return false;
        }

        return $this->applyOnClients(Client::query()->where('id', $clientId), $user)->exists();
    }

    public function authorizeSite(User $user, \App\Models\Site $site): void
    {
        if (! $this->applyOnSites(\App\Models\Site::query()->where('id', $site->id), $user)->exists()) {
            abort(403, 'No tienes acceso a esta sede.');
        }
    }

    /**
     * Usuario con manage_all pertenece al mismo operador MSP que el ticket.
     */
    public function userInTicketOperatorScope(User $user, \App\Models\Ticket $ticket): bool
    {
        if ($this->bypassesOperatorScope($user)) {
            return true;
        }

        if ($this->usesLegacyMspWideAccess($user)) {
            return true;
        }

        $operatorId = $this->resolveOperatorIdForTicket($ticket);
        if (! $operatorId) {
            return false;
        }

        $userOperatorId = $this->resolveOperatorUserId($user);

        return $userOperatorId && (int) $userOperatorId === (int) $operatorId;
    }

    public function resolveOperatorIdForTicket(\App\Models\Ticket $ticket): ?int
    {
        $ticket->loadMissing('site:id,client_id', 'client:id,operator_user_id');

        if ($ticket->client_id && $ticket->client?->operator_user_id) {
            return (int) $ticket->client->operator_user_id;
        }

        if ($ticket->client_id) {
            $op = Client::where('id', $ticket->client_id)->value('operator_user_id');

            return $op ? (int) $op : null;
        }

        if ($ticket->site?->client_id) {
            $op = Client::where('id', $ticket->site->client_id)->value('operator_user_id');

            return $op ? (int) $op : null;
        }

        return null;
    }

    /** Restringe audit_logs al operador MSP del usuario. */
    public function applyOnAuditLogs(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where('client_id', $enforced);
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user)) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->whereIn('client_id', function ($sub) use ($operatorId) {
                $sub->select('id')->from('clients')->where('operator_user_id', $operatorId);
            });
        }

        $clientId = $this->tenantResolver->resolve($user);
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('0 = 1');
    }

    /** Misma forma que applyOnAuditLogs() -- pending_ticket_requests también tiene client_id plano. */
    public function applyOnPendingTicketRequests(Builder $query, User $user): Builder
    {
        if ($enforced = $this->tenantContext->enforcedClientId()) {
            return $query->where('client_id', $enforced);
        }

        if ($this->bypassesOperatorScope($user)) {
            return $query;
        }

        if ($this->usesOperatorMspWideScope($user)) {
            $operatorId = $this->resolveOperatorUserId($user);
            if (! $operatorId) {
                return $this->usesLegacyMspWideAccess($user) ? $query : $query->whereRaw('0 = 1');
            }

            return $query->whereIn('client_id', function ($sub) use ($operatorId) {
                $sub->select('id')->from('clients')->where('operator_user_id', $operatorId);
            });
        }

        $clientId = $this->tenantResolver->resolve($user);
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('0 = 1');
    }

    private function applyTicketsWithoutTenant(Builder $query, User $user): Builder
    {
        if ($this->tenantResolver->isAreaScopedWithoutTenant($user, 'tickets')) {
            return $query;
        }

        if ($user->can('tickets.view_own')) {
            return $query->where('requester_id', $user->id);
        }

        return $query->whereRaw('0 = 1');
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

    /** @return \Closure(\Illuminate\Database\Query\Builder): void */
    private function clientIdsSubquery(int $operatorUserId): \Closure
    {
        return function ($sub) use ($operatorUserId) {
            $sub->select('id')->from('clients')->where('operator_user_id', $operatorUserId);
        };
    }

    /** @return \Closure(\Illuminate\Database\Query\Builder): void */
    private function siteIdsSubquery(int $operatorUserId): \Closure
    {
        return function ($sub) use ($operatorUserId) {
            $sub->select('sites.id')
                ->from('sites')
                ->join('clients', 'clients.id', '=', 'sites.client_id')
                ->where('clients.operator_user_id', $operatorUserId);
        };
    }
}
