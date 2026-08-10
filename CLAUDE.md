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

---

## Multi-Tenant / Operator Onboarding

Recent additions (2026 migrations): `clients`, `user_invitations`, `operator_profiles`, `plans` tables. The `client_id` column exists on `users`, `tickets`, `incidents`, and `sites` (tenant key for operational data). See **`docs/DATABASE_TENANCY.md`** for PostgreSQL setup, indexes, and backfill helpers (`App\Support\Database\TenantBackfill`).

`EnsureOnboardingComplete` middleware (alias `onboarding`) blocks access to the main app until the operator completes setup. Don't bypass this middleware for new protected routes.

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
| `docs/PENDING.md` | **Índice consolidado de pendientes** — punto de entrada, ver sección siguiente |

---

## Pendientes (resumen — detalle y estado actualizado en `docs/PENDING.md`)

**Bloqueado por config externa** (código listo, falta cuenta/credenciales que Claude no puede crear): login con Google, Google Maps/geocodificación, login con Microsoft 365 (Azure AD).

**Diseñado, no construido**:
- Calendario de tickets: falta hora exacta (no solo fecha), usar `tickets.resolved_at` (existe en backend, el frontend nunca lo pinta), toggle fecha de alta/cierre.

**Roadmaps vivos, sin fecha objetivo**:
- `RBAC_ROADMAP.md`: fase 6 (roles por equipo/Spatie teams) cerrada 2026-08-04; fase 7 (plantilla "Encargado TI") cerrada 2026-08-09. Queda anotado ahí un hallazgo relacionado sin resolver: onboarding de un tenant nuevo no siembra roles de RBAC v2 automáticamente.
- `MULTITENANT_ROADMAP.md`: fases 0-4 (aislamiento estructural: RLS en prod, índices únicos por tenant, revisión de queries raw) sin empezar. Fase 5 (producto/operaciones): 5.5 (monitoreo cross-tenant) **hecho 2026-08-05**; quedan 5.1 (aislar SIGUA), 5.2 (subdominio MSP `operador.tikara.test`), 5.3 (SSO/OIDC por cliente), 5.4 (panel "URL de portal" + copiar enlace en ficha cliente).

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
