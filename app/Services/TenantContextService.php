<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Notifications\Security\TenantBoundaryViolationNotification;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContext as Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantContextService
{
    protected ?TenantContext $resolved = null;

    protected ?int $resolvedRequestId = null;

    public function resolve(Request $request): TenantContext
    {
        $requestId = spl_object_id($request);
        if ($this->resolved !== null && $this->resolvedRequestId === $requestId) {
            return $this->resolved;
        }

        $subdomain = $this->extractSubdomain($request);

        if ($subdomain && config('tenancy.enforce_subdomain', true)) {
            $client = $this->findClientByPortalSlug($subdomain);
            if ($client) {
                return $this->storeResolved($requestId, new TenantContext(
                    mode: Ctx::MODE_CLIENT_PORTAL,
                    subdomain: $subdomain,
                    clientId: (int) $client->id,
                    client: $client,
                ));
            }
        }

        return $this->storeResolved($requestId, new TenantContext(mode: Ctx::MODE_PLATFORM, subdomain: $subdomain));
    }

    private function storeResolved(int $requestId, TenantContext $context): TenantContext
    {
        $this->resolvedRequestId = $requestId;
        $this->resolved = $context;

        return $context;
    }

    public function current(): TenantContext
    {
        if ($this->resolved) {
            return $this->resolved;
        }

        return $this->resolve(request());
    }

    /** ID de cliente forzado por subdominio (portal empresa final). */
    public function enforcedClientId(): ?int
    {
        $ctx = $this->current();

        return $ctx->enforcesStrictClientIsolation() ? $ctx->clientId : null;
    }

    public function isStrictClientPortal(): bool
    {
        return $this->current()->enforcesStrictClientIsolation();
    }

    /**
     * Usuario puede acceder al portal del subdominio actual.
     */
    public function userCanAccessCurrentPortal(?User $user): bool
    {
        $ctx = $this->current();
        if (! $ctx->isClientPortal() || ! $user) {
            return true;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        $clientId = $ctx->clientId;
        $userClientId = app(TenantClientResolver::class)->resolve($user);

        if ($userClientId && (int) $userClientId === (int) $clientId) {
            return true;
        }

        if ($user->is_operator || $user->can('clients.view_all') || $user->can('tickets.manage_all')) {
            $operatorId = app(OperatorScopeService::class)->resolveOperatorUserId($user);

            return $operatorId && (int) Client::where('id', $clientId)->value('operator_user_id') === (int) $operatorId;
        }

        return false;
    }

    /**
     * Registra en audit_logs un intento cross-tenant (login rechazado por portal
     * incorrecto, o acceso bloqueado de una sesión ya autenticada). Nunca debe
     * interrumpir la petición principal si falla -- mismo patrón que Auditable trait.
     *
     * @param 'login_rejected'|'access_blocked' $event
     */
    public function logBoundaryViolation(?User $user, string $event, ?int $attemptedClientId, array $context = []): void
    {
        try {
            AuditLog::create([
                'user_id' => $user?->id,
                'client_id' => $attemptedClientId,
                'auditable_type' => 'tenant_boundary',
                'auditable_id' => $attemptedClientId ?? 0,
                'action' => $event,
                'new_values' => $context,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Tenant boundary audit log failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        $this->notifyBoundaryViolation($user, $event, $attemptedClientId);
    }

    /**
     * Avisa a super_admin y al operador dueño del cliente atacado. Deduplicado
     * 5 min por (evento, usuario, cliente) vía cache -- evita saturar de
     * notificaciones si un cliente queda atorado reintentando (ej. bucle de
     * redirect por sesión/cookie inválida).
     */
    private function notifyBoundaryViolation(?User $user, string $event, ?int $attemptedClientId): void
    {
        try {
            $throttleKey = "tenant_boundary_alert:{$event}:".($user?->id ?? 'guest').':'.($attemptedClientId ?? 'none');
            if (! Cache::add($throttleKey, true, 300)) {
                return;
            }

            $client = $attemptedClientId ? Client::find($attemptedClientId) : null;
            $recipients = $this->platformRecipientsFor($client);
            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new TenantBoundaryViolationNotification(
                event: $event,
                userLabel: $user?->name ?? $user?->email,
                clientName: $client?->name,
                clientId: $attemptedClientId,
            ));
        } catch (\Throwable $e) {
            Log::error('Tenant boundary alert notification failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * super_admin(es) + operador dueño del cliente (clients.operator_user_id),
     * sin duplicar -- destinatarios estándar para avisos de plataforma sobre
     * un tenant. Compartido por notifyBoundaryViolation() y notifyPlanRequest().
     *
     * No usar el scope role() de Spatie aquí: con teams activo (RBAC v2), ese
     * scope filtra por el team_id ambiente del request actual, que casi nunca
     * coincide con el team_id centinela con el que se asignó super_admin (ver
     * docblock de SuperAdminCrossTenantTest). Consulta directa a la tabla pivote
     * para encontrar el rol sin importar el contexto de team activo.
     */
    private function platformRecipientsFor(?Client $client): \Illuminate\Support\Collection
    {
        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $superAdminIds = $superAdminRoleId
            ? DB::table('model_has_roles')
                ->where('role_id', $superAdminRoleId)
                ->where('model_type', User::class)
                ->pluck('model_id')
            : collect();
        $recipients = $superAdminIds->isNotEmpty() ? User::whereIn('id', $superAdminIds)->get() : collect();
        if ($client?->operator_user_id) {
            $operator = User::find($client->operator_user_id);
            if ($operator && ! $recipients->contains('id', $operator->id)) {
                $recipients->push($operator);
            }
        }

        return $recipients;
    }

    /**
     * Autoservicio del tenant (panel "Mi empresa"): no hay pasarela de pago
     * integrada, así que "cambiar de plan" / "cancelar" no cobra ni cancela
     * nada -- solo avisa a quien administra la cuenta (operador + super_admin)
     * para que lo gestione manualmente. Ver docs/PENDING.md.
     */
    public function notifyPlanRequest(Client $client, User $requester, string $type, ?string $note): void
    {
        try {
            $recipients = $this->platformRecipientsFor($client);
            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new \App\Notifications\Clients\ClientSelfServiceRequestNotification(
                type: $type,
                clientName: $client->name,
                clientId: $client->id,
                requestedByLabel: $requester->name ?? $requester->email,
                note: $note,
            ));
        } catch (\Throwable $e) {
            Log::error('Client plan request notification failed', [
                'client_id' => $client->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function loginUrlForClient(Client $client): ?string
    {
        if (! $client->portal_slug || ! config('tenancy.base_domain')) {
            return null;
        }

        $scheme = config('tenancy.portal_scheme', 'https');

        return "{$scheme}://{$client->portal_slug}.".config('tenancy.base_domain').'/login';
    }

    private function extractSubdomain(Request $request): ?string
    {
        $subdomain = $request->header('X-Tenant-Subdomain');

        if (! $subdomain && $request->getHost()) {
            $host = strtolower($request->getHost());
            $base = config('tenancy.base_domain');
            if ($base && str_ends_with($host, '.'.strtolower($base))) {
                $subdomain = substr($host, 0, -strlen('.'.strtolower($base)));
            } elseif (substr_count($host, '.') >= 2) {
                $parts = explode('.', $host);
                $first = $parts[0];
                if (! in_array($first, ['www', 'app', 'api'], true)) {
                    $subdomain = $first;
                }
            }
        }

        $subdomain = is_string($subdomain) ? strtolower(trim($subdomain)) : null;

        if ($subdomain === '' || in_array($subdomain, config('tenancy.reserved_subdomains', []), true)) {
            return null;
        }

        return $subdomain;
    }

    public static function clearPortalCache(string $slug): void
    {
        Cache::forget("tenant.portal.{$slug}");
    }

    private function findClientByPortalSlug(string $slug): ?Client
    {
        if (! Schema::hasColumn('clients', 'portal_slug')) {
            return null;
        }

        return Cache::remember("tenant.portal.{$slug}", 300, function () use ($slug) {
            return Client::query()
                ->where('portal_slug', $slug)
                ->where('is_active', true)
                ->first();
        });
    }

    public static function generateUniquePortalSlug(string $name, ?int $ignoreClientId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'cliente';
        }

        $slug = $base;
        $n = 0;
        while (Client::query()
            ->when($ignoreClientId, fn ($q) => $q->where('id', '!=', $ignoreClientId))
            ->where('portal_slug', $slug)
            ->exists()) {
            $n++;
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
