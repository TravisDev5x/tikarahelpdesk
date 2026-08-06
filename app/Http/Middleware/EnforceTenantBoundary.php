<?php

namespace App\Http\Middleware;

use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En portal por subdominio (empresa final): bloquea usuarios de otro cliente/operador.
 */
class EnforceTenantBoundary
{
    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ctx = $this->tenantContext->resolve($request);

        $request->attributes->set('tenant_context', $ctx);
        if ($ctx->subdomain) {
            $request->attributes->set('tenant_subdomain', $ctx->subdomain);
        }
        if ($ctx->clientId) {
            $request->attributes->set('tenant_client_id', $ctx->clientId);
        }

        if (! $ctx->isClientPortal()) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $this->tenantContext->userCanAccessCurrentPortal($user)) {
            $this->tenantContext->logBoundaryViolation($user, 'access_blocked', $ctx->clientId, [
                'host' => $request->getHost(),
                'path' => $request->path(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'No tienes acceso a este portal. Inicia sesión en la URL de tu organización.',
                ], 403);
            }

            abort(403, 'No tienes acceso a este portal.');
        }

        return $next($request);
    }
}
