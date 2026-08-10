<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Inertia\CatalogPageController;
use App\Http\Controllers\Inertia\ResolbebIndexController;
use App\Http\Controllers\Inertia\UserController as InertiaUserController;
use App\Http\Controllers\Onboarding\TenantOnboardingController;
use App\Http\Controllers\Web\ClientController;
use App\Models\Plan;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| AQUÍ SOLO VIVE LÓGICA DE FRONTEND AUTENTICADO POR COOKIES (SESIÓN).
| - Verificación de sesión: /check-auth (guard web, middleware auth).
| - Vistas Inertia (login, app autenticada) y sesión por cookies.
| NO mezclar con API: la API no valida sesiones; solo tokens.
*/

// ==========================
// VERIFICACIÓN DE AUTENTICACIÓN (SESIÓN)
// ==========================
// - Solo en web. Usa exclusivamente middleware('auth') y guard web. NO auth:api.
// - El frontend debe llamar /check-auth DESPUÉS del login o desde layout autenticado.
// - Llamar /check-auth desde /login dará 401 (correcto; no es bug).
// - Requests AJAX sin sesión reciben 401 JSON { authenticated: false } (nunca redirect HTML).
Route::get('/check-auth', App\Http\Controllers\Web\CheckAuthController::class)
    ->middleware('auth')
    ->name('check-auth');

// ==========================
// DIAGNÓSTICO (solo local / debug)
// ==========================
if (app()->environment('local') || config('app.debug')) {
    Route::get('/test-disco', function () {
        Storage::disk('public')->put('prueba.txt', 'OK');

        return 'OK';
    });
}

// ==========================
// AUTH (Inertia) — rutas públicas
// ==========================
Route::get('/login', fn () => Inertia::render('Auth/Login'))->middleware('guest')->name('login');
Route::get('/auth/google/redirect', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'redirect'])
    ->middleware('guest')
    ->name('auth.google.redirect');
// Sin 'guest': el callback debe ser alcanzable tanto al iniciar sesión (invitado)
// como al vincular Google desde Mi perfil (ya autenticado) -- ambos flujos
// comparten la misma redirect_uri fija configurada en Google (services.google.redirect).
// La identidad para el flujo de vínculo viaja en la sesión (google_oauth_link_user_id),
// no depende de Auth::check() aquí.
Route::get('/auth/google/callback', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'callback'])
    ->name('auth.google.callback');
Route::get('/auth/google/link', [\App\Http\Controllers\Auth\GoogleAuthController::class, 'linkRedirect'])
    ->middleware('auth')
    ->name('auth.google.link');
Route::get('/auth/microsoft/redirect', [\App\Http\Controllers\Auth\MicrosoftAuthController::class, 'redirect'])
    ->middleware('guest')
    ->name('auth.microsoft.redirect');
Route::get('/auth/microsoft/callback', [\App\Http\Controllers\Auth\MicrosoftAuthController::class, 'callback'])
    ->name('auth.microsoft.callback');
Route::get('/auth/microsoft/link', [\App\Http\Controllers\Auth\MicrosoftAuthController::class, 'linkRedirect'])
    ->middleware('auth')
    ->name('auth.microsoft.link');
Route::get('/register', function () {
    return Inertia::render('Auth/Register', [
        'plans' => Plan::activePublic()->get([
            'id',
            'name',
            'slug',
            'type',
            'price_monthly',
            'price_yearly',
            'max_clients',
            'max_users',
            'max_agents',
            'highlighted',
            'trial_days',
        ]),
    ]);
})->middleware('guest')->name('register');
Route::get('/register/accept', [AcceptInvitationController::class, 'show'])
    ->middleware('guest')
    ->name('invitation.accept');
Route::post('/register/accept', [AcceptInvitationController::class, 'store'])
    ->middleware('guest')
    ->name('invitation.accept.store');
Route::get('/forgot-password', fn () => Inertia::render('Auth/ForgotPassword'))->middleware('guest')->name('password.request');
Route::get('/reset-password', fn () => Inertia::render('Auth/ResetPassword'))->middleware('guest')->name('password.reset');
Route::get('/verify-email', fn () => Inertia::render('Auth/VerifyEmail'))->middleware('guest')->name('verification.verify');
Route::get('/force-change-password', fn () => Inertia::render('Auth/ForceChangePassword'))->middleware('auth')->name('password.force-change');

Route::get('/manual', fn () => Inertia::render('Manual'))->name('manual');

// Landing pública
Route::get('/', [LandingController::class, 'index'])->name('landing');

// Onboarding de tenant nuevo (Fase 7, 2026-08-09 -- reemplaza por completo al
// wizard legacy de operador/OperatorProfile, retirado en esta misma fase).
// auth sin middleware onboarding — evita bucle.
Route::middleware('auth')->group(function () {
    Route::get('/onboarding', [TenantOnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding', [TenantOnboardingController::class, 'store'])->name('onboarding.store');

    Route::redirect('/clientes', '/clients');
    // Sedes ya no es un catálogo aparte -- se administran dentro del
    // formulario de cliente (Clients/Form.jsx, paso 4).
    Route::redirect('/sedes', '/clients');
    Route::redirect('/ubicaciones', '/locations');
    Route::redirect('/calendario', '/calendar');

    Route::prefix('clients')->name('clients.')->group(function () {
        Route::get('/', [ClientController::class, 'index'])->name('index');
        Route::get('/create', [ClientController::class, 'create'])->name('create');
        Route::post('/', [ClientController::class, 'store'])->name('store');
        Route::get('/{client}', [ClientController::class, 'show'])->name('show');
        Route::get('/{client}/edit', [ClientController::class, 'edit'])->name('edit');
        Route::put('/{client}', [ClientController::class, 'update'])->name('update');
        Route::delete('/{client}', [ClientController::class, 'destroy'])->name('destroy');
        Route::patch('/{client}/cancel', [ClientController::class, 'cancel'])->name('cancel');
        Route::patch('/{client}/reactivate', [ClientController::class, 'reactivate'])->name('reactivate');
        Route::patch('/{client}/plan', [ClientController::class, 'updatePlan'])->name('plan.update');
        Route::post('/{client}/plan-request', [ClientController::class, 'requestPlanChange'])->name('plan.request');
    });

    Route::prefix('company')->name('company.')->group(function () {
        Route::get('/', [CompanyController::class, 'show'])->name('show');
        Route::get('/edit', [CompanyController::class, 'edit'])->name('edit');
        Route::put('/', [CompanyController::class, 'update'])->name('update');
        Route::delete('/logo', [CompanyController::class, 'destroyLogo'])->name('logo.destroy');
    });

    Route::get('/areas', [CatalogPageController::class, 'areas'])->name('areas.index');
    Route::get('/priorities', [CatalogPageController::class, 'priorities'])->name('priorities.index');
    Route::get('/impact-levels', [CatalogPageController::class, 'impactLevels'])->name('impact-levels.index');
    Route::get('/urgency-levels', [CatalogPageController::class, 'urgencyLevels'])->name('urgency-levels.index');
    Route::get('/campaigns', [CatalogPageController::class, 'campaigns'])->name('campaigns.index');
    Route::get('/positions', [CatalogPageController::class, 'positions'])->name('positions.index');
    Route::get('/roles', [CatalogPageController::class, 'roles'])->name('roles.index');
    Route::get('/sessions', [CatalogPageController::class, 'sessions'])->name('sessions.index');

    Route::get('/home', fn () => Inertia::render('Home/Dashboard'))
        ->middleware('onboarding')
        ->name('home');

    Route::redirect('/dashboard', '/home');

    $catalogPages = CatalogPageController::class;

    Route::get('/resolbeb', fn (CatalogPageController $catalogs) => Inertia::render('Resolbeb/Dashboard', [
        'catalogs' => $catalogs->resolbebDashboardCatalogs(),
    ]))
        ->middleware('onboarding')
        ->name('resolbeb.dashboard');

    Route::get('/tickets/wallboard', fn (CatalogPageController $catalogs) => Inertia::render('Resolbeb/Wallboard', [
        'catalogs' => $catalogs->resolbebDashboardCatalogs(),
    ]))->name('resolbeb.wallboard');

    Route::get('/resolbeb/estados', [$catalogPages, 'ticketStates'])->name('resolbeb.estados');
    Route::get('/resolbeb/tipos', [$catalogPages, 'ticketTypes'])->name('resolbeb.tipos');

    Route::get('/resolbeb/tickets/new', fn (CatalogPageController $catalogs) => Inertia::render('Resolbeb/Create', [
        'catalogs' => $catalogs->resolbebCreateCatalogs(),
    ]))
        ->middleware('onboarding')
        ->name('resolbeb.create');

    Route::get('/resolbeb/tickets', [ResolbebIndexController::class, 'index'])
        ->middleware('onboarding')
        ->name('resolbeb.tickets');

    Route::get('/resolbeb/mis-tickets', [ResolbebIndexController::class, 'misTickets'])
        ->middleware('onboarding')
        ->name('resolbeb.mis-tickets');

    Route::get('/resolbeb/pending-requests', fn () => Inertia::render('Resolbeb/PendingRequests'))
        ->middleware(['onboarding', 'perm:tickets.review_pending|tickets.manage_all'])
        ->name('resolbeb.pending-requests');

    Route::get('/resolbeb/tickets/{id}', function (int $id, CatalogPageController $catalogs) {
        return Inertia::render('Resolbeb/Detalle', [
            'ticketId' => $id,
            'catalogs' => $catalogs->resolbebDetalleCatalogs(),
        ]);
    })->where('id', '[0-9]+')
        ->middleware('onboarding')
        ->name('resolbeb.detalle');

    Route::get('/locations', [$catalogPages, 'locations'])->name('locations.index');
    Route::get('/ticket-macros', [$catalogPages, 'ticketMacros'])->name('ticket-macros.index');
    Route::get('/priority-matrix', [$catalogPages, 'priorityMatrix'])->name('priority-matrix.index');
    Route::get('/permissions', [$catalogPages, 'permissions'])->name('permissions.index');

    Route::get('/audit-command', fn () => Inertia::render('System/AuditCommandCenter'))->name('audit.index');

    Route::get('/incident-types', [$catalogPages, 'incidentTypes'])->middleware('onboarding')->name('incident-types.index');
    Route::get('/incident-severities', [$catalogPages, 'incidentSeverities'])->middleware('onboarding')->name('incident-severities.index');
    Route::get('/incident-statuses', [$catalogPages, 'incidentStatuses'])->middleware('onboarding')->name('incident-statuses.index');

    Route::get('/incidents', fn (CatalogPageController $catalogs) => Inertia::render('Incidents/Index', [
        'catalogs' => $catalogs->incidentIndexCatalogs(),
    ]))->middleware('onboarding')->name('incidents.index');

    Route::get('/incidents/{id}', function (int $id, CatalogPageController $catalogs) {
        return Inertia::render('Incidents/Show', [
            'incidentId' => $id,
            'catalogs' => $catalogs->incidentDetalleCatalogs(),
        ]);
    })->where('id', '[0-9]+')->middleware('onboarding')->name('incidents.detalle');

    Route::get('/calendar', fn () => Inertia::render('Calendar'))->name('calendar.index');

    Route::get('/profile', fn () => Inertia::render('Profile'))->name('profile.index');

    Route::get('/users', [InertiaUserController::class, 'index'])
        ->middleware('onboarding')
        ->name('users.inertia.index');

    Route::get('/users/invitations', fn () => Inertia::render('Users/Invitations'))
        ->middleware('onboarding')
        ->name('users.invitations.index');

    Route::get('/settings', fn () => Inertia::render('Settings'))
        ->middleware('onboarding')
        ->name('settings.index');

    // URLs legacy → Resolbeb (redirects)
    Route::redirect('/tickets', '/resolbeb/tickets');
    Route::redirect('/mis-tickets', '/resolbeb/mis-tickets');
    Route::redirect('/tickets/new', '/resolbeb/tickets/new');
    Route::get('/tickets/{id}', fn (string $id) => redirect("/resolbeb/tickets/{$id}"))
        ->where('id', '[0-9]+');
    Route::redirect('/ticket-states', '/resolbeb/estados');
    Route::redirect('/ticket-types', '/resolbeb/tipos');
});

// ==========================
// PORTAL DE CLIENTE — {slug}.tikara.test / {slug}.tikara.mx
// Rutas para usuarios finales de cada empresa cliente.
// El middleware 'tenant' resuelve el slug y hace abort(404) si no existe.
// ==========================
$baseDomain = config('tenancy.base_domain', 'tikara.mx');
Route::domain('{tenantSlug}.' . $baseDomain)
    ->middleware(['tenant'])
    ->group(function () {
        // Auth del portal (sin sesión)
        Route::middleware('guest')->group(function () {
            Route::get('/login', function (string $tenantSlug) {
                return Inertia::render('Portal/Auth/Login', [
                    'tenantSlug' => $tenantSlug,
                ]);
            })->name('portal.login');
        });

        // Rutas del portal autenticadas
        Route::middleware(['auth', 'tenant.rls'])->group(function () {
            Route::get('/', function (string $tenantSlug) {
                return Inertia::render('Portal/Dashboard', [
                    'tenantSlug' => $tenantSlug,
                ]);
            })->name('portal.dashboard');

            Route::get('/tickets', function (string $tenantSlug) {
                return Inertia::render('Portal/Tickets/Index', [
                    'tenantSlug' => $tenantSlug,
                ]);
            })->name('portal.tickets.index');

            Route::get('/tickets/new', function (string $tenantSlug) {
                $ticketTypes = \DB::table('ticket_types')->orderBy('id')->get(['id', 'name']);
                $areas = \DB::table('areas')
                    ->whereNull('client_id')
                    ->orWhere('client_id', auth()->user()?->client_id)
                    ->orderBy('id')
                    ->get(['id', 'name']);
                $defaultAreaId = $areas->first()?->id;
                $defaultStateId = (int) (\DB::table('ticket_states')
                    ->where(fn ($q) => $q->whereNull('is_final')->orWhere('is_final', false))
                    ->orderBy('id')
                    ->value('id') ?? 1);

                return Inertia::render('Portal/Tickets/Create', [
                    'tenantSlug'     => $tenantSlug,
                    'ticketTypes'    => $ticketTypes,
                    'defaultAreaId'  => $defaultAreaId,
                    'defaultStateId' => $defaultStateId,
                ]);
            })->name('portal.tickets.create');
        });
    });

// Compatibilidad URLs SPA legacy → Inertia (docs/INERTIA_MIGRATION.md)
require __DIR__.'/inertia_legacy.php';
