<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\InvCategoryController;
use App\Http\Controllers\Api\InvStatusController;
use App\Http\Controllers\Api\InvLabelController;
use App\Http\Controllers\Api\InvIntegrationController;
use App\Http\Controllers\Api\InvManufacturerController;
use App\Http\Controllers\Api\InvMaintenanceOriginController;
use App\Http\Controllers\Api\InvMaintenanceModalityController;
use App\Http\Controllers\Api\InvAssetController;
use App\Http\Controllers\Api\InvAssetDocumentController;
use App\Http\Controllers\Api\InvAssetRelationshipController;
use App\Http\Controllers\Api\InvAssetWarrantyController;
use App\Http\Controllers\Api\InvAssetImageController;
use App\Http\Controllers\Api\InvMovementController;
use App\Http\Controllers\Api\InvComponentController;
use App\Http\Controllers\Api\InvComponentMovementController;
use App\Http\Controllers\Api\InvAssetDisassemblyController;
use App\Http\Controllers\Api\InvMaintenanceController;
use App\Http\Controllers\Api\InvAssetImportController;
use App\Http\Controllers\Api\InvAssetExportController;
use App\Http\Controllers\Api\InvMonitorExportController;
use App\Http\Controllers\Api\InvMonitorSummaryController;
use App\Http\Controllers\Api\InvMovementExportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\UserRoleController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfileSecurityController;
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

// Panel de asignación de site_user fuera de onboarding (auditoría
// 2026-08-11): permiso propio sites.assign_staff, separado de
// users.manage a propósito -- no el mismo gate que el bloque de arriba.
Route::middleware(['auth:sanctum','locale','perm:sites.assign_staff'])->group(function () {
    Route::get('users/{user}/sites', [\App\Http\Controllers\Api\UserSiteController::class, 'show']);
    Route::post('users/{user}/sites', [\App\Http\Controllers\Api\UserSiteController::class, 'sync']);
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

    // Nombres prefijados con "api." -- routes/web.php ya usa estos mismos
    // nombres (clients.index, areas.index, etc.) para sus páginas Inertia;
    // sin el prefijo, route('clients.index') en PHP resolvía a esta ruta
    // JSON en vez de a la página. Ver docs/ROUTE_NAME_COLLISIONS.md.
    Route::name('api.')->group(function () {
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
    });

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
    // Activo↔Ticket (auditoría de Inventario, fase 3.1) -- gateado por
    // TicketPolicy, no por un permiso de Inventario (ver TicketAssetController).
    Route::get('tickets/{ticket}/assets/search', [\App\Http\Controllers\Api\TicketAssetController::class, 'search']);
    Route::post('tickets/{ticket}/assets', [\App\Http\Controllers\Api\TicketAssetController::class, 'store']);
    Route::delete('tickets/{ticket}/assets/{link}', [\App\Http\Controllers\Api\TicketAssetController::class, 'destroy']);
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
    Route::get('incidents/{incident}/attachments/{attachment}/download', [\App\Http\Controllers\Api\IncidentAttachmentController::class, 'download']);
    Route::name('api.')->group(function () {
        Route::apiResource('incidents', \App\Http\Controllers\Api\IncidentController::class)
            ->only(['index', 'store', 'update', 'show']);
    });
});

// Seguridad: eventos de frontera de tenant (login/acceso cross-tenant rechazado).
// Mismo permiso que el centro de auditoría de tickets (tickets.manage_all).
Route::middleware(['auth:sanctum','locale','perm:tickets.manage_all'])->group(function () {
    Route::get('security/tenant-boundary-events', [\App\Http\Controllers\Api\TenantSecurityController::class, 'index']);
});

// Cola de revisión manual de correos no reconocidos (docs/PENDING_TICKET_REVIEW.md).
Route::middleware(['auth:sanctum','locale','perm:tickets.review_pending|tickets.manage_all'])->group(function () {
    Route::get('pending-ticket-requests', [\App\Http\Controllers\Api\PendingTicketRequestController::class, 'index']);
    Route::post('pending-ticket-requests/{pendingTicketRequest}/approve', [\App\Http\Controllers\Api\PendingTicketRequestController::class, 'approve']);
    Route::post('pending-ticket-requests/{pendingTicketRequest}/reject', [\App\Http\Controllers\Api\PendingTicketRequestController::class, 'reject']);
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

Route::middleware(['auth:sanctum','locale','perm:catalogs.manage'])->name('api.')->group(function () {
    Route::apiResource('areas', AreaController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});

// ==========================
// PUESTOS
// ==========================

Route::middleware(['auth:sanctum','locale','perm:catalogs.manage'])->name('api.')->group(function () {
    Route::apiResource('positions', PositionController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});


// ==========================
// INVENTARIO — CATÁLOGOS (fase 1, port desde HelpdeskECD2026)
// ==========================

Route::middleware(['auth:sanctum','locale','perm:inventory.manage_config'])->name('api.')->group(function () {
    Route::apiResource('inv-categories', InvCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('inv-statuses', InvStatusController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('inv-labels', InvLabelController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('inv-manufacturers', InvManufacturerController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('inv-maintenance-origins', InvMaintenanceOriginController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('inv-maintenance-modalities', InvMaintenanceModalityController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    // Fase 4.1 de la auditoría -- integraciones opcionales por tenant
    // (Entra ID/Intune/AD). {provider} no es un modelo (una fila por
    // client_id+provider, no siempre existe todavía), por eso son rutas
    // explícitas y no un apiResource.
    Route::get('inv-integrations', [InvIntegrationController::class, 'index']);
    Route::put('inv-integrations/{provider}', [InvIntegrationController::class, 'store']);
    Route::post('inv-integrations/{provider}/test', [InvIntegrationController::class, 'test']);
    Route::delete('inv-integrations/{provider}', [InvIntegrationController::class, 'destroy']);
});

// ==========================
// INVENTARIO — ACTIVOS (fase 2, port desde HelpdeskECD2026)
// ==========================
//
// Fase 7.4 (permisos granulares, retomada tras pausa -- ver
// docs/INVENTORY_ROADMAP.md): 3 niveles reales en vez de un solo
// inventory.manage_assets para todo.
//   - VER (inventory.view_assets): solo lectura -- listar/ver/exportar.
//   - EDITAR (inventory.edit_assets): todo lo operativo -- crear, editar,
//     ciclo de vida, componentes, mantenimientos, import. SIN eliminar.
//   - GESTIONAR (inventory.manage_assets, ya existía): todo lo anterior +
//     eliminar (activos, componentes, mantenimientos). Sigue siendo el
//     único requerido en middleware de arriba en el resto del módulo
//     (cada grupo de abajo acepta manage_assets O el nivel específico que
//     le toca, vía el patrón perm:a|b|c ya usado en Tickets/Incidents).

Route::middleware(['auth:sanctum','locale','perm:inventory.manage_assets|inventory.edit_assets|inventory.view_assets'])->name('api.')->group(function () {
    // Exports (fase 7.2) -- antes de apiResource, mismo cuidado que import.
    Route::get('inv-assets/export', InvAssetExportController::class);
    Route::get('inv-assets/monitor/export', InvMonitorExportController::class);
    // Resumen ligero para el panel general de Inicio (fase 1, separación de
    // dashboards) -- antes de monitor/export por el mismo cuidado de rutas
    // fijas antes que segmentos dinámicos ya usado en el resto del archivo.
    Route::get('inv-assets/monitor/summary', InvMonitorSummaryController::class);
    Route::get('inv-movements/export', InvMovementExportController::class);
    Route::get('inv-assets/import/template', [InvAssetImportController::class, 'template']);

    Route::apiResource('inv-assets', InvAssetController::class)->only(['index', 'show']);

    // Ver una foto es una lectura -- fase 1 de la auditoría de Inventario:
    // antes se servía directo en disco público sin este chequeo.
    Route::get('inv-assets/{inv_asset}/images/{image}', [InvAssetImageController::class, 'show']);
    // Documentos y evidencias (fase 2.2) -- descarga, mismo criterio que fotos.
    Route::get('inv-assets/{inv_asset}/documents/{document}', [InvAssetDocumentController::class, 'show']);

    // Ciclo de vida (fase 3) -- solo la lectura de la bitácora aquí.
    Route::get('inv-assets/{inv_asset}/movements', [InvMovementController::class, 'index']);

    // Componentes + despiece (fase 4) -- solo lectura aquí.
    Route::apiResource('inv-components', InvComponentController::class)->only(['index', 'show']);
    Route::get('inv-components/{inv_component}/movements', [InvComponentMovementController::class, 'index']);

    // Mantenimientos (fase 5) -- solo lectura aquí.
    Route::get('inv-maintenances', [InvMaintenanceController::class, 'index']);
    Route::get('inv-maintenances/{inv_maintenance}', [InvMaintenanceController::class, 'show']);
});

Route::middleware(['auth:sanctum','locale','perm:inventory.manage_assets|inventory.edit_assets'])->name('api.')->group(function () {
    // Import masivo (fase 6) -- crea activos, no los elimina.
    Route::post('inv-assets/import', [InvAssetImportController::class, 'store']);

    Route::apiResource('inv-assets', InvAssetController::class)->only(['store', 'update']);
    Route::post('inv-assets/{inv_asset}/images', [InvAssetImageController::class, 'store']);
    // Borrar una foto es corregir un upload propio, no "eliminar un
    // activo" -- se queda en el nivel EDITAR, no en GESTIONAR.
    Route::delete('inv-assets/{inv_asset}/images/{image}', [InvAssetImageController::class, 'destroy']);
    // Documentos y evidencias (fase 2.2) -- subir/eliminar.
    Route::post('inv-assets/{inv_asset}/documents', [InvAssetDocumentController::class, 'store']);
    Route::delete('inv-assets/{inv_asset}/documents/{document}', [InvAssetDocumentController::class, 'destroy']);
    // Garantías (fase 2.3) -- sin update a propósito, ver InvAssetWarrantyController.
    Route::post('inv-assets/{inv_asset}/warranties', [InvAssetWarrantyController::class, 'store']);
    Route::delete('inv-assets/{inv_asset}/warranties/{warranty}', [InvAssetWarrantyController::class, 'destroy']);
    // Relaciones entre activos (fase 3.2, CMDB) -- mismo criterio que garantías.
    Route::post('inv-assets/{inv_asset}/relationships', [InvAssetRelationshipController::class, 'store']);
    Route::delete('inv-assets/{inv_asset}/relationships/{relationship}', [InvAssetRelationshipController::class, 'destroy']);

    // Ciclo de vida (fase 3) -- mutaciones de la bitácora inmutable
    // (checkout/checkin/traslado/baja son altas nuevas, nunca update/
    // destroy sobre una fila existente).
    Route::post('inv-assets/{inv_asset}/checkout', [InvMovementController::class, 'checkout']);
    Route::post('inv-assets/{inv_asset}/checkin', [InvMovementController::class, 'checkin']);
    Route::post('inv-assets/{inv_asset}/transfer', [InvMovementController::class, 'transfer']);
    Route::post('inv-assets/{inv_asset}/retire', [InvMovementController::class, 'retire']);

    // Componentes + despiece (fase 4) -- asignar/desasignar/retirar es
    // cambiar estado, no destruir la fila.
    Route::apiResource('inv-components', InvComponentController::class)->only(['store', 'update']);
    Route::post('inv-components/{inv_component}/assign', [InvComponentMovementController::class, 'assign']);
    Route::post('inv-components/{inv_component}/unassign', [InvComponentMovementController::class, 'unassign']);
    Route::post('inv-components/{inv_component}/retire', [InvComponentMovementController::class, 'retire']);
    Route::post('inv-assets/{inv_asset}/disassemble', [InvAssetDisassemblyController::class, 'store']);

    // Mantenimientos (fase 5) -- recurso editable, no bitácora inmutable.
    Route::post('inv-assets/{inv_asset}/maintenances', [InvMaintenanceController::class, 'store']);
    Route::put('inv-maintenances/{inv_maintenance}', [InvMaintenanceController::class, 'update']);
});

Route::middleware(['auth:sanctum','locale','perm:inventory.manage_assets'])->name('api.')->group(function () {
    // Eliminar de verdad -- único requisito real de GESTIONAR sobre EDITAR.
    Route::apiResource('inv-assets', InvAssetController::class)->only(['destroy']);
    Route::apiResource('inv-components', InvComponentController::class)->only(['destroy']);
    Route::delete('inv-maintenances/{inv_maintenance}', [InvMaintenanceController::class, 'destroy']);
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

    // Seguridad de cuenta (self-service: solo lo propio, sin scope de cliente)
    Route::get('profile/sessions', [ProfileSecurityController::class, 'sessions']);
    Route::delete('profile/sessions/{id}', [ProfileSecurityController::class, 'revokeSession']);
    Route::post('profile/sessions/revoke-others', [ProfileSecurityController::class, 'revokeOtherSessions']);
    Route::get('profile/login-history', [ProfileSecurityController::class, 'loginHistory']);
    Route::delete('profile/connections/{provider}', [ProfileSecurityController::class, 'unlinkConnection']);
    Route::post('profile/request-deletion', [ProfileSecurityController::class, 'requestDeletion']);

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
