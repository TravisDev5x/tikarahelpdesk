<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\UserRoleController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\SessionMonitorController;
use App\Http\Controllers\Api\LocationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| JSON para Inertia + Axios. auth:sanctum con cookies stateful (Sanctum SPA).
| Sesión inicial: props Inertia (HandleInertiaRequests) o GET /check-auth (web).
|
| Multi-tenant (auditoría 2026-06-05): docs/API_TENANCY_AUDIT.md
| - Middleware: EnforceTenantBoundary + ApplyPgsqlTenantRls (si TENANCY_PGSQL_RLS)
| - Tickets/incidents/my-tickets: Policy + ClientScopeService (nivel A)
| - Catálogos CRUD: OperatorScope / ManagesOperatorCatalog (nivel B; varios pendientes)
| - Roles/permissions: globales Spatie (nivel C)
| Cookies/subdominios: docs/SANCTUM_TENANCY.md
*/

// ==========================
// AUTH (login/logout por token o estado stateful; verificación de sesión → web /check-auth)
// ==========================

Route::post('login', [AuthController::class, 'login'])
    ->middleware(['throttle:login','locale']);
Route::post('register', [AuthController::class, 'register'])
    ->middleware(['throttle:register','locale']);
Route::get('register/verify', [AuthController::class, 'verifyEmail']);
Route::post('logout', [AuthController::class, 'logout'])
    ->middleware(['auth:sanctum','locale']);
Route::get('ping', [AuthController::class, 'ping'])
    ->middleware(['auth:sanctum','locale']);

// PASSWORD RESET
Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
    ->middleware(['throttle:5,1','locale']);
Route::post('password/reset', [PasswordResetController::class, 'reset'])
    ->middleware(['throttle:10,1','locale']);


// ==========================
// USUARIOS
// ==========================

Route::middleware(['auth:sanctum','locale','perm:users.manage'])->group(function () {

    Route::get('sessions', [SessionMonitorController::class, 'index'])
        ->middleware('throttle:30,1');

    Route::post('sessions/logout-user', [SessionMonitorController::class, 'logoutUser'])
        ->middleware('throttle:10,1');

    Route::post('users/mass-delete', [UserController::class, 'massDestroy'])
        ->middleware('throttle:5,1');

    Route::post('users/{id}/restore', [UserController::class, 'restore'])
        ->middleware('throttle:10,1');

    Route::delete('users/{id}/force', [UserController::class, 'forceDelete'])
        ->middleware('throttle:5,1');

    Route::patch('users/{user}/blacklist', [UserController::class, 'toggleBlacklist'])
        ->middleware('throttle:10,1');

    Route::apiResource('users', UserController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])
        ->middleware('throttle:10,1');
    Route::apiResource('invitations', InvitationController::class)
        ->only(['index', 'store', 'destroy']);

});


// ==========================
// ROLES
// ==========================

// Roles: GET accesible con auth; mutaciones requieren roles.manage
Route::middleware(['auth:sanctum','locale'])->get('roles', [RoleController::class, 'index']);
Route::middleware(['auth:sanctum','locale','perm:roles.manage'])->group(function () {
    Route::apiResource('roles', RoleController::class)
        ->only(['store', 'update', 'destroy']);
    Route::post('roles/{role}/permissions', [RolePermissionController::class, 'sync']);
});

// RBAC v2 (Fase 6, Paso 5): plantillas editables por tenant (nombre libre +
// scope_archetype + permisos por objeto del catálogo). GET del catálogo
// accesible con auth (no muta nada); crear/editar plantilla requiere
// roles.manage, igual que el resto del módulo de roles.
Route::middleware(['auth:sanctum','locale'])->get('authorization-objects', [\App\Http\Controllers\Api\RoleTemplateController::class, 'catalog']);
Route::middleware(['auth:sanctum','locale','perm:roles.manage'])->group(function () {
    Route::post('role-templates', [\App\Http\Controllers\Api\RoleTemplateController::class, 'store']);
    Route::put('role-templates/{role}', [\App\Http\Controllers\Api\RoleTemplateController::class, 'update']);
});

// RBAC v2 (Fase 6, Paso 5): overrides directos de permiso por usuario,
// mismo gate que el resto de gestión de usuarios.
Route::middleware(['auth:sanctum','locale','perm:users.manage'])->group(function () {
    Route::get('users/{user}/permissions', [\App\Http\Controllers\Api\UserPermissionOverrideController::class, 'show']);
    Route::post('users/{user}/permission-overrides', [\App\Http\Controllers\Api\UserPermissionOverrideController::class, 'store']);
    Route::delete('users/{user}/permission-overrides/{permission}', [\App\Http\Controllers\Api\UserPermissionOverrideController::class, 'destroy']);
});

// ==========================
// PERMISOS
// ==========================

// Permisos: GET accesible con auth; mutaciones requieren permisos.manage o roles.manage
Route::middleware(['auth:sanctum','locale'])->get('permissions', [PermissionController::class, 'index']);
Route::middleware(['auth:sanctum','locale','perm:permissions.manage|roles.manage'])->group(function () {
    Route::apiResource('permissions', PermissionController::class)
        ->only(['store', 'update', 'destroy']);
});

// ==========================
// UBICACIONES (lectura para usuarios y catálogos)
// ==========================
// Listar ubicaciones: quien gestiona usuarios o catálogos puede ver el listado
Route::middleware(['auth:sanctum','locale','perm:users.manage|catalogs.manage'])->get('locations', [LocationController::class, 'index']);

// ==========================
// CAMPAÑAS
// ==========================

Route::middleware(['auth:sanctum','locale','perm:catalogs.manage'])->group(function () {
    Route::post('geocode', \App\Http\Controllers\Api\GeocodeController::class)
        ->middleware('throttle:20,1');
    Route::apiResource('campaigns', CampaignController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('sites', \App\Http\Controllers\Api\SiteController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('locations', \App\Http\Controllers\Api\LocationController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('priorities', \App\Http\Controllers\Api\PriorityController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('impact-levels', \App\Http\Controllers\Api\ImpactLevelController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('urgency-levels', \App\Http\Controllers\Api\UrgencyLevelController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('ticket-states', \App\Http\Controllers\Api\TicketStateController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('ticket-types', \App\Http\Controllers\Api\TicketTypeController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('incident-types', \App\Http\Controllers\Api\IncidentTypeController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('incident-severities', \App\Http\Controllers\Api\IncidentSeverityController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('incident-statuses', \App\Http\Controllers\Api\IncidentStatusController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::get('priority-matrix', [\App\Http\Controllers\Api\PriorityMatrixController::class, 'index']);
    Route::post('priority-matrix/bulk', [\App\Http\Controllers\Api\PriorityMatrixController::class, 'updateBulk']);
});

// Mis Tickets: solo solicitante (requester_id = user). Sin permisos operativos. Desacoplado del módulo Tickets.
Route::middleware(['auth:sanctum', 'locale'])->prefix('my-tickets')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\MyTicketsController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\MyTicketsController::class, 'store']);
    Route::get('{ticket}/attachments/{attachment}/download', [\App\Http\Controllers\Api\MyTicketsController::class, 'downloadAttachment']);
    Route::post('{ticket}/attachments', [\App\Http\Controllers\Api\MyTicketsController::class, 'storeAttachment']);
    Route::post('{ticket}/comments', [\App\Http\Controllers\Api\MyTicketsController::class, 'addComment']);
    Route::get('{ticket}', [\App\Http\Controllers\Api\MyTicketsController::class, 'show']);
    Route::post('{ticket}/alert', [\App\Http\Controllers\Api\MyTicketsController::class, 'sendAlert']);
    Route::post('{ticket}/cancel', [\App\Http\Controllers\Api\MyTicketsController::class, 'cancel']);
});

// Tickets: acceso con auth + permisos específicos (Policies refuerzan alcance) + rate limit
Route::middleware(['auth:sanctum','locale','throttle:tickets','perm:tickets.manage_all|tickets.view_area|tickets.view_own|tickets.create'])->group(function () {
    // Analytics debe declararse antes de los params {ticket} para evitar binding
    Route::get('tickets/analytics', \App\Http\Controllers\Api\TicketAnalyticsController::class)
        ->middleware('report.audit');
    Route::get('tickets/dashboard-operativo', [\App\Http\Controllers\Api\ResolbebController::class, 'dashboardOperativo'])
        ->middleware('report.audit');
    Route::get('tickets/summary', [\App\Http\Controllers\Api\TicketController::class, 'summary'])
        ->middleware('report.audit');
    Route::get('tickets/audit-logs', [\App\Http\Controllers\Api\TicketController::class, 'indexAuditLogs']);
    Route::get('tickets/audit-export', [\App\Http\Controllers\Api\TicketController::class, 'exportAudit']);
    Route::get('tickets/export', [\App\Http\Controllers\Api\TicketController::class, 'export'])
        ->middleware('report.audit');
    Route::post('tickets/{ticket}/take', [\App\Http\Controllers\Api\TicketController::class, 'take']);
    Route::post('tickets/{ticket}/assign', [\App\Http\Controllers\Api\TicketController::class, 'assign']);
    Route::post('tickets/{ticket}/unassign', [\App\Http\Controllers\Api\TicketController::class, 'unassign']);
    Route::post('tickets/{ticket}/alert', [\App\Http\Controllers\Api\TicketController::class, 'sendAlert']);
    Route::post('tickets/{ticket}/cancel', [\App\Http\Controllers\Api\TicketController::class, 'cancel']);
    Route::post('tickets/{ticket}/escalate', [\App\Http\Controllers\Api\TicketController::class, 'escalate']);
    Route::post('tickets/{ticket}/attachments', [\App\Http\Controllers\Api\TicketAttachmentController::class, 'store']);
    Route::delete('tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\Api\TicketAttachmentController::class, 'destroy']);
    Route::get('tickets/{ticket}/attachments/{attachment}/download', [\App\Http\Controllers\Api\TicketAttachmentController::class, 'download']);
    Route::get('tickets/{ticket}/audit', [\App\Http\Controllers\Api\TicketController::class, 'audit']);
    Route::apiResource('tickets', \App\Http\Controllers\Api\TicketController::class)
        ->only(['index', 'store', 'update', 'show']);
    Route::get('ticket-macros', [\App\Http\Controllers\Api\TicketMacroController::class, 'index']);
    Route::apiResource('ticket-macros', \App\Http\Controllers\Api\TicketMacroController::class)
        ->only(['show', 'store', 'update', 'destroy']);
});

// Incidencias: acceso con auth + permisos especificos (Policies refuerzan alcance)
Route::middleware(['auth:sanctum','locale','perm:incidents.manage_all|incidents.view_area|incidents.view_own|incidents.create'])->group(function () {
    Route::post('incidents/{incident}/take', [\App\Http\Controllers\Api\IncidentController::class, 'take']);
    Route::post('incidents/{incident}/assign', [\App\Http\Controllers\Api\IncidentController::class, 'assign']);
    Route::post('incidents/{incident}/unassign', [\App\Http\Controllers\Api\IncidentController::class, 'unassign']);
    Route::post('incidents/{incident}/attachments', [\App\Http\Controllers\Api\IncidentAttachmentController::class, 'store']);
    Route::delete('incidents/{incident}/attachments/{attachment}', [\App\Http\Controllers\Api\IncidentAttachmentController::class, 'destroy']);
    Route::apiResource('incidents', \App\Http\Controllers\Api\IncidentController::class)
        ->only(['index', 'store', 'update', 'show']);
});

// Notificaciones (in-app, sólo lectura)
Route::middleware(['auth:sanctum','locale'])->group(function () {
    Route::get('notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
});

// ==========================
// AREAS
// ==========================

Route::middleware(['auth:sanctum','locale','perm:catalogs.manage'])->group(function () {
    Route::apiResource('areas', AreaController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});

// ==========================
// PUESTOS
// ==========================

Route::middleware(['auth:sanctum','locale','perm:catalogs.manage'])->group(function () {
    Route::apiResource('positions', PositionController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});


// ==========================
// ASIGNACIONES
// ==========================

Route::middleware(['auth:sanctum','locale','perm:users.manage'])->group(function () {
    Route::post('users/{user}/roles', [UserRoleController::class, 'sync']);
});


// ==========================
// CATÁLOGOS
// ==========================

// CatÃ¡logos leÃ­dos por la UI (solo lectura, cualquier usuario autenticado)
Route::get('catalogs', [CatalogController::class, 'index'])
    ->middleware(['auth:sanctum','locale']);


// ==========================
// PERFIL
// ==========================

Route::middleware(['auth:sanctum','locale'])->group(function () {

    Route::get('profile', [ProfileController::class, 'show']);
    Route::post('profile', [ProfileController::class, 'update']);
    Route::put('profile/password', [ProfileController::class, 'updatePassword']);
    Route::put('profile/theme', [ProfileController::class, 'updateTheme']);
    Route::put('profile/density', [ProfileController::class, 'updateDensity']);
    Route::put('profile/sidebar', [ProfileController::class, 'updateSidebar']);
    Route::put('profile/preferences', [ProfileController::class, 'updatePreferences']);

    // Admin notifications (básico)
    Route::middleware('perm:notifications.manage')->group(function () {
        Route::get('admin/notifications', [AdminNotificationController::class, 'index']);
        Route::post('admin/notifications/{id}/read', [AdminNotificationController::class, 'markRead']);
        Route::post('admin/notifications/{id}/resolve-password', [AdminNotificationController::class, 'resolvePasswordReset']);
    });

});

// Webhook inbound mail — fuera del grupo auth, sin CSRF, sin Sanctum.
// La verificación de firma HMAC del proveedor se hace dentro del controller.
Route::post('/webhook/inbound-mail/{provider}',
    [\App\Http\Controllers\Webhook\InboundMailController::class, 'handle']
)->name('webhook.inbound-mail');
