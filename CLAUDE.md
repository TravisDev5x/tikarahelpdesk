# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Tikara** is a multi-module CRM/Ticketing System for IT support teams. It uses a **Laravel 12 REST API backend** with a **React 19 + Inertia.js frontend**. Authentication is via Laravel Sanctum (stateful cookies).

---

## Common Commands

### Development

```bash
composer dev
# Starts concurrently: artisan serve + queue:listen + pail (logs) + vite dev
```

### Build

```bash
npm run build       # Production Vite build
composer optimize   # Cache config, views, events for production
```

### Database

```bash
php artisan migrate
php artisan migrate:fresh --seed   # Reset + seed demo data
```

### Testing

```bash
composer test       # Clears config cache, then runs PHPUnit
php artisan test --filter=TicketTest   # Single test class
```

### Linting / Code Style

```bash
./vendor/bin/pint   # Laravel Pint (PHP)
```

---

## Architecture

### High-Level Stack

- **Backend**: Laravel 12, PHP 8.2+, MySQL/SQLite, Sanctum (auth), Spatie Permission (RBAC)
- **Frontend**: React 19, Inertia.js, Vite 7, Tailwind CSS 4, Radix UI, Zod + React Hook Form, Recharts, TanStack Table
- **Pages**: `resources/js/Inertia/Pages/` (rutas en `web.php`); UI compartida en `resources/js/components/`. API en `app/Http/Controllers/Api/`.

### Request Flow

```
Inertia (Vite → inertia.jsx → páginas en Inertia/Pages/)
  → Datos: Axios (CSRF + cookie) → /api/*
  → Navegación: visitas Inertia (routes/web.php)
    → auth:sanctum + perm:* en API
```

**Session handshake**: Frontend calls `GET /check-auth` (a web route) to verify session on load. All `/api/*` routes use `auth:sanctum` and work via cookie, not Bearer tokens.

### Key Directories

| Path | Purpose |
|------|---------|
| `app/Http/Controllers/Api/` | Active REST controllers |
| `app/Http/Controllers/Sigua/` | SIGUA module controllers |
| `app/Models/` | Eloquent models (35+) |
| `app/Models/Sigua/` | SIGUA-namespace models |
| `app/Services/` | Business logic (scoping, onboarding, listing, requester ops) |
| `app/Policies/` | Gate-registered authorization policies |
| `app/Http/Requests/` | Form validation classes |
| `app/Http/Middleware/` | Custom middleware |
| `resources/js/Inertia/Pages/` | Inertia page components (rutas en `web.php`) |
| `resources/js/components/` | UI compartida, dashboards, formularios |
| `resources/js/components/ui/` | Radix-based reusable UI |
| `resources/js/context/` | Auth, Sidebar, I18n, Theme providers |
| `resources/js/lib/` | Axios config, catalog cache utility |
| `resources/js/services/` | API service wrappers |
| `routes/api.php` | All REST endpoints |
| `routes/web.php` | Inertia routes, `/check-auth`, landing, redirects legacy |
| `routes/sigua.php` | SIGUA module routes (auto-registered in bootstrap/app.php) |

---

## Authorization

**Middleware alias `perm`** = `EnsurePermissionOrAdmin`. Usage: `perm:tickets.manage_all|tickets.view_area`

- **Bypasses**: admin users, the first user in DB, or users without any role yet (onboarding)
- **Policies**: `TicketPolicy`, `IncidentPolicy`, `RequesterTicketPolicy` — registered in `AppServiceProvider`
- **Note**: `RequesterTicketPolicy` is NOT registered as a Gate policy; it's instantiated and called explicitly inside `MyTicketsController`

**Ticket scope levels**:
- `manage_all` — full visibility across all areas/clients
- `view_area` — own area + personally assigned tickets
- `view_own` — only self-created or self-assigned

---

## Core Modules

| Module | API prefix | Key controllers |
|--------|-----------|-----------------|
| Helpdesk / Tickets | `/api/tickets` | `TicketController`, `TicketAttachmentController`, `TicketAnalyticsController` |
| Mis Tickets (Requester) | `/api/my-tickets` | `MyTicketsController` |
| Incidents | `/api/incidents` | `IncidentController` |
| Users & RBAC | `/api/users`, `/api/roles`, `/api/permissions` | `UserController`, `RoleController`, `PermissionController` |
| Catalogs | `/api/catalogs` | `CatalogController` (monolithic read; separate CRUD routes per catalog) |
| TimeDesk (Attendance/HR) | `/api/timedesk` | `AttendanceController`, `ScheduleController`, `TimeDeskEmployeeController` |
| SIGUA | `/api/sigua` | Controllers in `App\Http\Controllers\Sigua\*` |
| Notifications | `/api/notifications`, `/api/admin/notifications` | `NotificationController`, `AdminNotificationController` |
| Audit / Seguridad cross-tenant | `/api/tickets/audit-logs`, `/api/security/tenant-boundary-events` | `TicketController::indexAuditLogs`, `TenantSecurityController` — ambos leen la tabla polimórfica `audit_logs`, UI en `/audit-command` (pestañas "Tickets" / "Seguridad") |

---

## Patterns & Conventions

### Backend

- **Validation**: Always in Form Request classes (`app/Http/Requests/`), not inline in controllers
- **Audit logging**: dos patrones sobre la misma tabla `audit_logs` (polimórfica). (1) `Ticket` usa el trait `Auditable` (`app/Traits/Auditable.php`) para auto-loguear cambios de modelo (`auditable_type` = clase real); incidents **no** usa este trait aún. (2) Eventos que no son cambios de modelo (ej. accesos cross-tenant rechazados) usan un `auditable_type` sintético como string (`'tenant_boundary'`) vía `TenantContextService::logBoundaryViolation()` — mismo patrón try/catch que nunca debe romper la petición principal
- **Events/Listeners**: `TicketCreated` / `TicketUpdated` → `SendTicketNotification` listener
- **Client scoping (multi-tenant)**: Use `ClientScopeService` to filter queries by `client_id`—don't apply scoping manually in controllers
- **API responses**: Return plain JSON; use Laravel's `response()->json()` with appropriate HTTP status codes

### Frontend

- **State management**: React Context + hooks only (no Redux/Zustand/TanStack Query)
- **HTTP**: Axios (`resources/js/lib/axios.js`) — already configured with CSRF, interceptors, and base URL
- **Forms**: React Hook Form + Zod schemas
- **Routing**: Add routes in `routes/web.php` with `Inertia::render('Module/Page')`; create page under `resources/js/Inertia/Pages/` with optional `.layout = AuthenticatedLayout`
- **Catalog data**: Always read from `lib/catalogCache.js` (TTL 600s client-side cache), not raw API calls
- **Styling**: Tailwind CSS + shadcn tokens (`resources/css/app.css`); `class-variance-authority` + `cn()` (`lib/utils`). Tema global light/dark/system (opción A): auth/landing/app; utilidades `lib/chartColors.js`, `lib/badgeStyles.js` (badges, `userStatusClass`, `tableActionIcon`, `statValue`); badges `components/badges/EntityBadges.jsx`, KPI `components/dashboard/KpiCard.jsx`; auth/onboarding shells; marketing `lib/marketingTheme.js` + `.mkt-*`. Evitar `slate-*` y hex sueltos en pantallas nuevas.
- **TypeScript**: SIGUA pages use `.ts`/`.tsx`; rest of app is `.jsx`. New code in new SIGUA files should be TypeScript; new Helpdesk/Users/etc. files can remain JSX

### Testing

- **Gotcha: cambiar de actor (`actingAs()`) dos veces en un mismo test, con una request HTTP real de por medio, no basta** (2026-08-11). El guard `sanctum` (el que usan las rutas de `api.php`) cachea su propio usuario resuelto en la instancia de guard ya creada dentro del contenedor de la app — `actingAs($nuevoUsuario, 'web')` solo muta el guard `'web'`, y esa mutación no se propaga automáticamente al guard `'sanctum'` si este ya resolvió un usuario en una request anterior dentro del mismo método de test. Síntoma: la segunda request se procesa como el actor VIEJO (mismo mensaje de error genérico "No autorizado" tanto del gate `perm:` como de checks de `assertUserAccessible()` — hay que revisar cuál usuario realmente llegó a la request, no solo el código de status). Fix: `$this->app['auth']->forgetGuards();` justo antes de la segunda llamada a `actingAs()` (fuerza a que TODOS los guards se re-resuelvan). Alternativa ya usada en `InvitationFlowTest.php` para un caso relacionado: `Auth::guard('web')->logout();` — no siempre basta por sí sola para el guard `sanctum` específicamente, `forgetGuards()` es la más confiable. Ejemplo real en `tests/Feature/UserSiteAssignmentTest.php::test_assigning_a_site_from_this_panel_is_immediately_respected_by_ticket_scoping`.
- **`sites`/`customers`/`tickets`/`incidents` tienen RLS bajo Postgres real**: leer esas relaciones (o cualquier query directa) FUERA de una request real (ej. una aserción de test justo después de terminar un `$this->postJson(...)`, o un fixture armado antes de cualquier request) cae fuera del contexto que `ApplyPgsqlTenantRls` ya limpió en su `finally` — sale vacío, no falla con error. Envolver esa lectura puntual con `PgsqlRowLevelSecurity::setBypass(true)` / `::clear()` (patrón usado en decenas de tests de `tests/Feature/`, ej. `UserSiteAssignmentTest.php`, `TenantOnboardingTest.php`).

---

## Multi-Tenant / Onboarding de tenant nuevo (Fase 7 — CERRADA)

Jerarquía real: `Client` (el tenant, `mode` internal/msp/hybrid, `portal_slug`, `ticket_prefix`) → `Customer` (empresa atendida bajo ese Client; `is_internal=true` = el propio tenant, auto-creado) → `Site` (sede física, `belongsTo` Client y opcionalmente Customer). `client_id` existe en `users`, `tickets`, `incidents`, `sites` (llave de tenant). Ver **`docs/DATABASE_TENANCY.md`** para setup de PostgreSQL, índices y backfill (`App\Support\Database\TenantBackfill`).

`App\Http\Controllers\Onboarding\TenantOnboardingController` es el wizard vigente — reemplazó por completo al wizard legacy `OperatorOnboardingController` (retirado en 7.1-7.2), sin tocar `is_operator`/`OperatorProfile`/`OperatorScopeService`, que siguen siendo un sistema aparte (ver "Decisiones y footguns críticos" abajo). Recorrido completo, en orden:

| Sub-paso | Qué hace | `Client.onboarding_step` resultante |
|---|---|---|
| 7.1 | Registro + consentimiento LFPDPPP (`AuthController::register`) | — |
| 7.2 | Nombre del tenant → crea `Client`, Customer interno + Site por defecto ("Oficina principal", `InternalCustomerService::ensureForClient()`), 5 roles sembrados (`TenantRoleSeeder`: admin/supervisor/agente/solicitante/Encargado TI), fundador promovido a `admin` de su propio team | `tenant_named` |
| 7.3 | Datos de empresa (solo dirección/ciudad/país obligatorios) | `company_data` |
| 7.4 | Modalidad real (internal/msp/hybrid) | `modality_set` |
| 7.5 | Customers/Sites externos (solo MSP/Hybrid; Internal salta) | `customers_added` / `customers_skipped` |
| 7.6 | Invitar personal — reusa `InvitationController`/`InvitationAcceptanceService` tal cual, cupo contra `Plan.max_users` (activos + pendientes sin expirar) | `staff_invited` |
| 7.7 | Asignar staff que ya aceptó su invitación a sites vía `site_user` | — |
| 7.8 | `TenantWelcomeMail` al admin fundador (`ShouldQueue`) | `completed` |

`App\Services\OnboardingRedirectService::redirectPath()` es la ÚNICA fuente de verdad de a qué paso le toca ir un usuario a medio proceso — cada `show()` del controlador se la pregunta en vez de reimplementar la máquina de estados. `EnsureOnboardingComplete` middleware (alias `onboarding`) usa el mismo servicio para bloquear el resto de la app hasta terminar. No bypasear este middleware para rutas nuevas.

---

## Roadmap de fases (estado general)

Punto de retoma oficial de este proyecto — no depender de memoria de conversación, todo lo agendado vive aquí o en los docs que enlaza.

| Fase | Estado | Notas |
|---|---|---|
| Fase 5 (reassign + notificaciones + `show_agent_names`) | **CERRADA** | — |
| Fase 6 (RBAC v2 estilo SAP B1 — Spatie teams, plantillas por tenant, `scope_archetype`, catálogo de objetos, overrides por usuario) | **CERRADA** | Ver `docs/RBAC_ROADMAP.md` |
| Fase 7 (onboarding completo de un tenant nuevo, 7.1-7.8) | **CERRADA** (2026-08-10) | Ver sección "Multi-Tenant / Onboarding" arriba para el recorrido completo |
| Fase 8 (licenciamiento) | **NO INICIADA** — bloqueada por una pregunta de producto sin resolver | Ver sección "Fase 8 — Licenciamiento" abajo |
| Módulo Inventario (port desde HelpdeskECD2026) | **EN PROGRESO** — fases 1-7.5 (port + dashboard/wallboard) cerradas; auditoría ITAM/CMDB 2026-08-21: fase Crítico + fases 2.1-2.3 ITAM + fase 3.1 y 3.2 CMDB cerradas; fase 4.1 (2026-08-22, pantalla de integraciones opcionales Entra ID/Intune/AD) cerrada — falta la sincronización real de datos (fase 4, resto) y fase 5 (inteligencia) | Ver `docs/INVENTORY_ROADMAP.md` |
| M0-M6 (migración a base de datos física por tenant, `stancl/tenancy`) | **NO INICIADA** — pospuesta deliberadamente, sin presión de tiempo | — |
| UX/UI de todo el flujo | **NO INICIADA** — deliberadamente al final, después de M0-M6 | — |

---

## Decisiones y footguns críticos (respetar en trabajo futuro)

- **Footgun Spatie + teams**: `Role::findByName()` / `assignRole('nombre')` / `syncRoles(['nombre'])` (pasando un STRING, no un modelo) **no filtran por `team_id`** — resuelven el primer role con ese `name` que encuentren, sin importar el team. Mordió 2 veces en Fase 6 (ej. `admin@testco.test` quedó con el role global en vez del de su tenant). SIEMPRE resolver primero `Role::where('name', ...)->where('team_id', $clientId)->firstOrFail()` y asignar el modelo ya resuelto. Patrón ya aplicado correctamente en todo Fase 7 (`InvitationController`, `InvitationAcceptanceService`, `TenantOnboardingController::assignFoundingAdminRole()`, `TenantRoleSeeder`).
- **Footgun Spatie + teams en tests, `givePermissionTo()` directo**: `model_has_permissions` también es `team_id`-scoped (no solo `model_has_roles`) — `setPermissionsTeamId(config('tenancy.super_admin_team_id')); $user->givePermissionTo(...)` en un test crea el permiso bajo el team_id centinela, pero `ApplyPgsqlTenantRls` reescribe el team_id vigente a `TenantClientResolver::resolve($user)` (el `client_id` real del usuario) en cada request real, salvo que el usuario sea `super_admin`/bypasse `OperatorScopeService::bypassesOperatorScope()`. Para un usuario de tenant normal, el permiso otorgado bajo el centinela queda invisible durante la request real — 403 aunque `givePermissionTo()` "haya servido" en el setup. Hay que otorgar bajo `setPermissionsTeamId($client->id)` (encontrado escribiendo `tests/Feature/InventoryGranularPermissionsTest.php`, fase 7.4). Nota aparte: varios tests preexistentes (`InventoryAssetScopeTest` y similares) "pasan" con el patrón del centinela solo porque nunca asignan ningún `role` a nadie en toda la clase, dejando permanentemente abierta la ventana de arranque de `EnsurePermissionOrAdmin` (`model_has_roles` vacía) — no es que el team_id correcto no importe, es que esos tests no llegan a probar la ruta de chequeo real. No es una regresión introducida por fase 7.4, es preexistente; no se tocó por no ser parte del pedido.
- **`is_operator`/`OperatorProfile`/`OperatorScopeService` NO son legacy aislado** — siguen siendo el cimiento real de autorización (RLS de Postgres, `TicketPolicy`, `IncidentPolicy`). Nunca tocarlos sin una auditoría propia y aparte, explícitamente autorizada. El wizard de onboarding de Fase 7 es un camino PARALELO que no depende de ellos — reemplazó solo el wizard legacy de formularios (`OperatorOnboardingController`), no el sistema de autorización.
- **RLS de Postgres + inserts fuera de una request real** (ej. el Customer/Site implícitos que crea el sistema, no un usuario): requiere `PgsqlRowLevelSecurity::withBypass(callable)`, que guarda y restaura el valor previo — nunca un `setBypass(false)` a secas, que podría pisar el bypass legítimo de otro caller.
- **`super_admin_team_id` = 0** (centinela explícito, no `NULL`) — siempre vía `config('tenancy.super_admin_team_id')`, nunca un literal `0` repetido en el código.
- **SAT/EFOS-EDOS**: retirado por completo como requisito de producto (decisión 2026-08-10) — no reintroducir ningún stub/hook/TODO relacionado salvo instrucción explícita nueva.
- **Horarios/disponibilidad de staff**: fuera de alcance del onboarding, decisión de producto explícita. No confundir con el endpoint roto `/api/my-schedule` que llama `MyScheduleView.jsx` — esa ruta no existe en ningún archivo de rutas, es un hallazgo preexistente sin relación con onboarding, todavía sin arreglar.

---

## Fase 8 — Licenciamiento (siguiente fase, NO iniciada)

Diseño acordado hasta ahora:
- Cada tenant nuevo arranca gratis, con hasta 10 usuarios ESTÁNDAR incluidos sin costo (reemplaza la idea anterior de "mínimo 5 de pago desde el día uno" — ya no aplica).
- El cobro real entra por usuarios AVANZADOS — cualquier tenant que agregue uno paga por cada uno, sin importar cuántos estándar tenga.
- `Plan.max_users` ya existe y ya tiene enforcement básico desde Fase 7.6 (`TenantOnboardingController::seatUsage()`: usuarios activos + invitaciones pendientes sin expirar, verificado antes de cada invitación nueva). Fase 8 construye sobre esto, no lo reemplaza desde cero.

**Pregunta de producto sin resolver, bloqueante para empezar el diseño**: ¿qué capacidad concreta tiene un usuario AVANZADO que uno ESTÁNDAR no tiene? La idea original era "puede ver la base de datos de su propia empresa, aislada" — pero eso depende de M0-M6 (base física por tenant), pospuesto sin fecha. Sin una respuesta a esto, "avanzado" es solo un precio más alto sin beneficio real detrás. **No empezar el diseño de `Plan.user_type`/precios hasta que esta pregunta tenga respuesta explícita.**

---

## Documentación del proyecto (mapa)

Este archivo es la guía rápida. Para detalle, cada doc vive en `docs/` (o raíz) y tiene un tema fijo — no dupliques su contenido aquí, enlázalo.

| Doc | Tema |
|---|---|
| `README.md` | Overview general, quickstart |
| `ARCHITECTURE.md` | Arquitectura maestra: módulos de negocio, stack, convenciones — mapa de onboarding |
| `INSTALACION.md` | Instalar el proyecto en otra PC/servidor desde cero |
| `API_CONTRACT.md` | Contrato mínimo de API consumido por la SPA y clientes externos |
| `USUARIOS_DEMO.md` | Credenciales de los usuarios semilla (admin, demo por rol) |
| `MIGRATIONS_NOTES.md` | RBAC con Spatie Permission — tablas activas, notas de compatibilidad |
| `docs/DATABASE_TENANCY.md` | Modelo de aislamiento multi-tenant en PostgreSQL, índices, `TenantBackfill` |
| `docs/API_TENANCY_AUDIT.md` | Auditoría de qué rutas de `api.php` respetan scope de tenant y cuáles no |
| `docs/CATALOG_TENANCY_MODEL.md` | Decisión de producto: catálogos compartidos del operador vs por cliente |
| `docs/CLIENT_PORTAL.md` | Portal por subdominio (`{slug}.tikara.mx`) para la empresa final atendida |
| `docs/SANCTUM_TENANCY.md` | Cookies de sesión Sanctum + subdominios (Laragon/producción) |
| `docs/TENANT_SUPPORT_EMAIL.md` | Correo único por tenant para levantar tickets desde fuera de la plataforma |
| `docs/INERTIA_MIGRATION.md` | Historial: migración de SPA legacy a Inertia único |
| `docs/ROUTE_NAME_COLLISIONS.md` | Colisión de nombres `api.php`/`web.php` — **arreglado 2026-08-05** |
| `docs/GOOGLE_MAPS_SETUP.md` | Checklist externo: API keys de Google Maps (geocodificación, mapa embebido) |
| `docs/MICROSOFT_LOGIN_SETUP.md` | Checklist externo: registrar app en Azure Portal para login con Microsoft 365 |
| `docs/MAILGUN_OUTBOUND.md` | Checklist externo: verificar Mailgun para correo transaccional saliente |
| `docs/DEPLOY_OCI.md` | Runbook de despliegue en Oracle Cloud (Docker Compose, sin dominio propio) |
| `docs/PENDING_TICKET_REVIEW.md` | Cola de revisión de correos no reconocidos — construida 2026-08-09 |
| `docs/RBAC_ROADMAP.md` | Bitácora de fases del modelo de roles/permisos (`team_id`, RBAC v2) |
| `docs/MULTITENANT_ROADMAP.md` | Roadmap de aislamiento multi-tenant hasta "100% sólido" |
| `docs/INVENTORY_ROADMAP.md` | Bitácora de fases del port de Inventario (HelpdeskECD2026 → Tikara) + roadmap de la auditoría ITAM/CMDB (2026-08-21/22) — Crítico/ITAM/CMDB cerrados, fase 4.1 (pantalla de integraciones Entra ID/Intune/AD) cerrada, falta la sincronización real y fase 5 |
| `docs/PENDING.md` | **Índice consolidado de pendientes** — punto de entrada, ver sección siguiente |

---

## Pendientes (resumen — detalle y estado actualizado en `docs/PENDING.md`)

**Bloqueado por config externa** (código listo, falta cuenta/credenciales que Claude no puede crear): login con Google, Google Maps/geocodificación, login con Microsoft 365 (Azure AD).

**Diseñado, no construido**: ver `docs/PENDING.md` sección 2 (consolidar dashboards de tickets, pasarela de pago real para "Mi empresa").

**Roadmaps vivos, sin fecha objetivo**:
- `RBAC_ROADMAP.md`: fase 6 (roles por equipo/Spatie teams) cerrada 2026-08-04; fase 7 (plantilla "Encargado TI") cerrada 2026-08-09. El hallazgo que quedaba anotado ahí sin resolver ("onboarding de un tenant nuevo no siembra roles de RBAC v2 automáticamente") **se resolvió con la Fase 7 del roadmap general** (`TenantOnboardingController` corre `TenantRoleSeeder` en 7.2 para cada tenant nuevo) — no confundir la numeración de fases de este doc (interna del RBAC) con la Fase 7 del roadmap general (onboarding).
- `MULTITENANT_ROADMAP.md`: fases 0-4 (aislamiento estructural: RLS en prod, índices únicos por tenant, revisión de queries raw) sin empezar. Fase 5 (producto/operaciones): 5.5 (monitoreo cross-tenant) **hecho 2026-08-05**; quedan 5.1 (aislar SIGUA), 5.2 (subdominio MSP `operador.tikara.test`), 5.3 (SSO/OIDC por cliente), 5.4 (panel "URL de portal" + copiar enlace en ficha cliente).
- `INVENTORY_ROADMAP.md`: fases 1-7.5 del port cerradas (2026-08-20/21, incluye panel general ligero + wallboard/modo TV). Auditoría técnica ITAM/CMDB (2026-08-21): fase Crítico + fases 2.1-2.3 ITAM (ficha técnica, documentos/bajas estructuradas, garantías/fabricantes/ubicación jerárquica) + fase 3.1 CMDB (relación Activo↔Ticket, la brecha más importante marcada por el negocio -- `inv_asset_ticket`, `TicketAssetController`) + fase 3.2 CMDB (relaciones entre activos, ej. laptop+dock+monitor -- `inv_asset_relationships`, `InvAssetRelationshipController`) cerradas. Fase 4.1 (2026-08-22, decisión de producto: integraciones opcionales por tenant) también cerrada -- `/inventory/integrations`, tabla `inv_integrations` (`config` cifrado con el cast nativo `encrypted:array`, nunca en `audit_logs`), botón "Probar conexión" real vía `App\Services\Integrations\*ConnectionTester` (Entra ID/Intune por Microsoft Graph OAuth2 client-credentials, AD por bind LDAP nativo -- sin sincronización de datos todavía, eso queda para el resto de fase 4). Solo faltan la sincronización real (resto de fase 4, requiere que el admin ya haya conectado algo aquí) y fase 5 (inteligencia), ninguna accionable sin más decisiones de producto. Pendiente aparte, fuera de la auditoría: vista de asignación consolidada, y una decisión reconsiderable sobre historial persistente de imports.

**Deuda técnica documentada, no bloqueante** (Fase 7):
- `sites.unique(client_id, name)` es por tenant completo, no por Customer — compensado con validación de aplicación en `TenantOnboardingController::storeCustomer()`, no de esquema.
- `site_user` no tiene panel de administración fuera de `/onboarding/teams` — un invitado que acepta después de que el admin ya terminó el onboarding no puede recibir un site asignado hoy (ni siquiera el wizard, que no vuelve a mostrarse).
- Flake conocido de aislamiento en 1 test de PostgreSQL+RLS (pasa en aislamiento; preexistente, no relacionado a ningún sprint reciente).

---

## Ideas / backlog no formalizado (no están en `docs/PENDING.md` todavía)

Cosas identificadas en sesiones de trabajo que valen la pena pero no se han escrito como pendiente formal ni tienen doc propio:

- ~~Alertas proactivas sobre eventos de seguridad cross-tenant~~ — **construido 2026-08-06**, ver `docs/PENDING.md` sección 3. Queda como idea abierta: sumar canal `mail` (la clase ya declara `via()`, un solo array a extender) una vez que `MAIL_MAILER` esté en algo distinto de `log` en producción.
- **Auditar gates de permiso del sidebar contra el backend**: se encontraron y arreglaron dos casos (`Sidebar.jsx`) donde un link se mostraba sin el permiso real que exige el backend detrás ("Mi empresa" duplicado para `super_admin`, "Catálogos" sin `catalogs.manage`). Vale la pena un barrido completo del resto de `NAV` en `Sidebar.jsx` contra los middleware `perm:` de `routes/api.php` para encontrar más casos del mismo patrón.
- **Vista dedicada de "Operadores" (MSP)**: hoy `super_admin` ve el operador dueño de cada cliente como columna en `/clients` (`ClientController::index`), pero no hay una pantalla para administrar operadores como entidad propia (suspender, ver métricas agregadas por operador). Relacionado con roadmap 5.2 (subdominio MSP).
- **Página de vista/edición de página server-side para catálogos con view no gateada**: al arreglar el gate de "Catálogos" en el sidebar se confirmó que las páginas Inertia (`/campaigns`, `/areas`, `/positions`, `/locations`) no tienen ningún chequeo de permiso en `routes/web.php` — solo las mutaciones vía API están protegidas (`perm:catalogs.manage`). Hoy no es explotable de forma útil (la página cargaría pero las llamadas a la API fallarían con 403), pero es defensa en profundidad pendiente si se quiere cerrar del todo.

---

## SIGUA Module

A separate domain for account management, CA-01 forms, HR data cross-reference, and alert generation. Uses its own:
- Model namespace: `App\Models\Sigua\`
- Controller namespace: `App\Http\Controllers\Sigua\`
- Route file: `routes/sigua.php` (registered separately in `bootstrap/app.php`)
- Console commands: `sigua:generar-alertas`, `sigua:cruce`, `sigua:verificar-ca01`, `sigua:verificar-bitacora`, `sigua:resumen-semanal`
- Service classes: `ImportacionService`, `CruceService`, `AlertaService`, `CA01Service`, `BitacoraService`, `ReporteService`
