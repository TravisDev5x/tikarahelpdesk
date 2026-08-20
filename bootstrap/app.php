<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use App\Http\Middleware\EnsureSessionForAuth;
use App\Http\Middleware\EnforcePasswordChange;
use App\Http\Middleware\AuditReportAccess;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\EnsurePermissionOrAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\ApplyPgsqlTenantRls;
use App\Http\Middleware\EnforceTenantBoundary;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // Detrás de un reverse proxy/túnel (nginx, Cloudflare Tunnel) que termina
        // TLS antes de llegar a la app — sin esto, Laravel genera URLs con esquema
        // http:// aunque el navegador esté en https://, rompiendo assets (mixed content).
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        // Webhooks externos no mandan CSRF token — excluirlos explícitamente.
        $middleware->validateCsrfTokens(except: [
            'api/webhook/*',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Sanctum para SPA (React)
        |--------------------------------------------------------------------------
        | Permite que las rutas API reconozcan la sesión del navegador
        | usando cookies seguras (NO JWT).
        */

        $middleware->api(prepend: [
            EnsureSessionForAuth::class,  // Sesión siempre en login/logout/register (evita 401 por falta de cookie)
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            EnforceTenantBoundary::class,
            ApplyPgsqlTenantRls::class,
            EnforcePasswordChange::class,
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            EnforceTenantBoundary::class,
            ApplyPgsqlTenantRls::class,
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            EnsureOnboardingComplete::class,
        ]);

        // SubstituteBindings (route model binding) corre por defecto ANTES que
        // cualquier middleware nuestro appendeado al grupo — así, una ruta como
        // /api/tickets/{ticket} resolvía el modelo bajo RLS sin las variables de
        // sesión de tenant aún aplicadas (bloqueo total por FORCE ROW LEVEL
        // SECURITY, visto como 404 en un recurso propio). Debe ir justo antes de
        // SubstituteBindings, después del auth (que ya tiene prioridad por defecto).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ApplyPgsqlTenantRls::class,
        );

        // Alias para poder usarlo en rutas (y colocar después de auth)
        $middleware->alias([
            'locale' => SetLocale::class,
            'perm' => EnsurePermissionOrAdmin::class,
            'report.audit' => AuditReportAccess::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'onboarding' => EnsureOnboardingComplete::class,
            'tenant' => \App\Http\Middleware\ResolveTenantFromSubdomain::class,
            'tenant.rls' => ApplyPgsqlTenantRls::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Requests AJAX (expectsJson) nunca reciben redirect HTML en auth fallida.
         * Siempre JSON 401 con { authenticated: false }. Evita confusión en el frontend.
         */
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['authenticated' => false], 401);
            }

            return null;
        });

        /*
         * Páginas de error con el branding de Tikara (Inertia, no Blade) en vez
         * del error genérico de Laravel. Nunca para /api/* ni peticiones que
         * esperan JSON — esas siguen devolviendo JSON plano como siempre.
         *
         * 500/503 solo se reemplazan fuera de local/testing: en desarrollo
         * queremos seguir viendo el stack trace de Laravel (APP_DEBUG) para
         * depurar errores reales. 404/403/419 sí se muestran siempre porque
         * son navegación normal, no bugs.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with(['message' => 'Tu sesión expiró, intenta de nuevo.']);
            }

            $isServerError = in_array($status, [500, 503], true);
            $shouldRenderBranded = in_array($status, [403, 404], true)
                || ($isServerError && ! app()->environment(['local', 'testing']));

            if ($shouldRenderBranded) {
                // rootView explícito: en un 404 real (sin ruta coincidente) el
                // middleware HandleInertiaRequests -que normalmente fija la vista
                // raíz 'inertia'- nunca llega a correr.
                return Inertia::render('Error', ['status' => $status])
                    ->rootView('inertia')
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })
    ->create();

