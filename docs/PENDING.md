# Pendientes — índice consolidado

Estado al 2026-08-09. Este documento junta, en un solo lugar, todo lo que
quedó fuera del alcance de las últimas sesiones de trabajo: qué está
diseñado pero no construido, qué está construido pero requiere
configuración externa que no puedo hacer yo, y bugs conocidos sin
resolver. Cada sección enlaza al doc detallado correspondiente.

## 1. Requiere configuración externa (infraestructura ya lista)

Estas features están completamente construidas en código. Lo único que
falta es que alguien con acceso a la consola externa correspondiente
(Google Cloud, Azure) cree las credenciales y las ponga en `.env`.

| Feature | Doc | Qué falta |
|---|---|---|
| Login con Google | (patrón ya en producción, sin doc propio — ver `GoogleAuthController`) | `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` en `.env` |
| Mapas y geocodificación (clientes/sedes) | [`GOOGLE_MAPS_SETUP.md`](./GOOGLE_MAPS_SETUP.md) | Proyecto en Google Cloud Console, 2 API keys restringidas, `GOOGLE_MAPS_SERVER_KEY` + `VITE_GOOGLE_MAPS_EMBED_KEY` |
| Login con Outlook / Microsoft 365 (Azure AD) | [`MICROSOFT_LOGIN_SETUP.md`](./MICROSOFT_LOGIN_SETUP.md) | Registrar app en Azure Portal (Entra ID), `AZURE_CLIENT_ID`/`AZURE_CLIENT_SECRET`/`AZURE_TENANT_ID`/`AZURE_REDIRECT_URI` |

Todas siguen el mismo patrón: si la config no está, el botón/feature se
muestra deshabilitado en vez de romper la app (`googleConfigured()` /
`microsoftConfigured()` / chequeo de `GOOGLE_MAPS_SERVER_KEY`).

## 2. Diseñado, no construido

| Feature | Doc | Resumen |
|---|---|---|
| Consolidar dashboards de tickets (fase 2) | — | Auditoría de arquitectura 2026-08-08: hay 3 dashboards de métricas/gráficas (`/home` admin, `/resolbeb`, y el rol `soporte`/`supervisor` de Inicio usa un 4º componente más simple), alimentados por 2 controllers con propósitos genuinamente distintos (Analytics = reporte histórico filtrable; Resolbeb = tablero operativo en vivo con modo TV). Fase 1 (unificar la lógica de filtros duplicada) ya se hizo — ver sección 3. **Falta decisión de producto**: ¿pestañas dentro de una sola página, o dejarlos separados pero con nombres que no den a entender que es un duplicado accidental? |
| Pasarela de pago real para "Mi empresa" (self-service de plan/facturación) | — | 2026-08-09: se construyó el flujo de solicitud (`ClientController::requestPlanChange`, botones "Solicitar cambio de plan"/"Solicitar cancelación" en `/clients/{id}` cuando `is_own_company`) — pero **no cobra ni cancela nada**, solo notifica al operador/super_admin (`TenantContextService::notifyPlanRequest`) para gestión manual. Falta decidir: qué pasarela (Stripe/Conekta/Openpay), si el cambio de plan se auto-aplica tras el pago o requiere aprobación, y manejo de webhooks de suscripción. Hoy `plan_id`/`billing_email`/`subscription_expires_at` en `clients` solo los edita `super_admin` a mano vía `updatePlan()`. |

## 3. Bugs/features resueltos recientemente

| Ítem | Doc | Resumen |
|---|---|---|
| Logout: toast de error falso + redirigía a `/login` en vez de la landing | — | Arreglado 2026-08-09. Condición de carrera: `POST /api/logout` mataba la sesión mientras otro request (ej. el fetch de tickets de la página activa) seguía en vuelo; su `401` posterior disparaba `redirectToLogin()`, que le "ganaba la carrera" al `redirectToLanding()` correcto del propio `logout()` (compartían la bandera `redirectInFlight`) y de paso mostraba un toast de error real. Nueva bandera `explicitLogoutInProgress` (`authNavigation.js`) que `logout()` activa antes de llamar al backend (ventana de gracia ~3s); el interceptor de axios etiqueta esos `401`/`419` como `duringLogout` en vez de redirigir, y las páginas que ya mostraban su propio toast de error (`Resolbeb/Index.jsx`, `Calendar.jsx`) lo ignoran cuando viene con esa etiqueta. |
| Colisión de nombres de ruta `api.php` vs `web.php` | [`ROUTE_NAME_COLLISIONS.md`](./ROUTE_NAME_COLLISIONS.md) | Arreglado 2026-08-05. Los 13 `apiResource` de `routes/api.php` que colisionaban con nombres de página de `routes/web.php` (16 nombres en total) ahora se registran con prefijo `api.`. Verificado con `route:list` (0 colisiones) y `composer test`. |
| Sidebar: "Mi empresa" duplicaba "Clientes" para `super_admin` | — | Arreglado 2026-08-05. `/company` siempre redirige a `/clients` para `super_admin` (no tiene `OperatorProfile`); el link ya no se muestra para ese rol. |
| "Mi empresa" mandaba a `/home` para el admin de un tenant (client_id propio) | — | Arreglado 2026-08-09. `CompanyController` solo contemplaba `super_admin` y operador MSP (`OperatorProfile`); un admin de tenant sin operador asignado (ej. `Client.operator_user_id` null) caía al fallback "sin empresa asociada" → `/home`. Ahora `/company` y `/company/edit` redirigen a su propio `Client` (`/clients/{id}`) — mismo patrón que ya usa `super_admin`, vía nuevo `OperatorScopeService::viewsOwnCompany()`. |
| Admin de tenant podía borrar/crear tenants vía `/clients` | — | Arreglado 2026-08-09 (auditado al resolver el punto anterior). `destroy()` usaba `authorizeClient()` (pensado para "puedo ver/editar"), que también deja pasar el caso "es mi propio tenant" — un admin con `clients.delete` podía **hard-delete su propia empresa**. `store()`/`create()` no tenían ningún gate de autorización, cualquier usuario autenticado podía dar de alta un tenant nuevo. Nuevos `OperatorScopeService::canDeleteClient()` / `canCreateClients()` (solo `super_admin` u operador MSP dueño del cliente) — deliberadamente NO reusan `assertClientAccessible()`. Botones "Eliminar"/"Nuevo cliente" ahora también se ocultan en el frontend cuando no aplica. Verificado con `composer test` (241 pass) + pruebas manuales 403 contra el backend real. |
| `/clients/{id}` mostraba tarjetas de tickets abiertos/cerrados/vencidos también en "Mi empresa" | — | Arreglado 2026-08-09. Esa página sirve dos roles distintos: supervisión (operador/super_admin viendo OTRO tenant) y autoservicio (tenant viendo su propia entidad vía "Mi empresa"). Las tarjetas de tickets solo aportan en el primer caso — ahora se ocultan cuando `is_own_company` (`OperatorScopeService::viewsOwnCompany()`). |
| Sidebar: sección "Catálogos" sin gate de permiso | — | Arreglado 2026-08-05. Ahora requiere `catalogs.manage`, igual que el backend. Antes cualquier usuario autenticado la veía aunque el backend le negara el acceso. |
| Monitoreo cross-tenant (`MULTITENANT_ROADMAP.md` 5.5) | [`MULTITENANT_ROADMAP.md`](./MULTITENANT_ROADMAP.md) | Construido 2026-08-05. `EnforceTenantBoundary` y el login rechazado por portal incorrecto ahora escriben a `audit_logs`; pestaña "Seguridad" nueva en `/audit-command`. |
| Alertas proactivas de seguridad cross-tenant | — | Construido 2026-08-06. `TenantContextService::notifyBoundaryViolation()` notifica (canal `database`, campanita existente) a todos los `super_admin` + al operador dueño del cliente atacado, cada vez que ocurre un `login_rejected` o `access_blocked`. Dedup 5 min por (evento, usuario, cliente) vía cache para no saturar si un cliente queda atorado reintentando. **Queda pendiente**: solo canal in-app — sin email/Slack (fácil de agregar: la notificación ya declara `via()`, solo falta sumar el canal). |
| Tickets duplicados por reintento de webhook de correo | — | Arreglado 2026-08-06 (auditoría de concurrencia, hueco 1). Unique constraint en `tickets(client_id, origin_message_id)` + chequeo previo en `ProcessInboundTicket` + catch de `UniqueConstraintViolationException` como backstop. Verificado en SQLite y Postgres real. |
| Condición de carrera en `take()`/`assign()` de tickets e incidencias | — | Arreglado 2026-08-06 (auditoría de concurrencia, hueco 2). El chequeo de "¿ya asignado?" corría antes de abrir la transacción, sin lock -- dos requests casi simultáneos podían pisarse en silencio (notificación duplicada, el otro agente creyendo dueño de algo que no era suyo). Ahora relee con `lockForUpdate()` y re-chequea adentro de la transacción, en `TicketController`/`IncidentController`. Verificado contra Postgres real que el `SELECT ... FOR UPDATE` se emite (SQLite no soporta row-locking real, se salta ahí). **Queda pendiente**: mismo patrón sin corregir en `unassign()` (menor severidad -- doble unassign es idempotente, solo duplica el registro de historial). |
| Filtros de query duplicados entre Analytics y Resolbeb (fase 1 de la consolidación de dashboards) | — | Arreglado 2026-08-08. `TicketAnalyticsController` y `ResolbebController` tenían cada uno su propio `applyFilters()` -- ya habían divergido: Resolbeb filtraba `site_id` con un `where()` crudo sin validar que la sede perteneciera al scope del usuario (Analytics sí, vía `ClientScopeService::applySiteFilter()`). Unificado en `TicketQueryFilterService` (`apply()` + `topResolvers()`), sin cambiar el contrato de API de ninguno de los dos. Test nuevo confirma que un `site_id` ajeno ahora se ignora en vez de vaciar los resultados del usuario (falla contra el código viejo, pasa con el nuevo). |
| Cola de revisión manual para correos no reconocidos | [`PENDING_TICKET_REVIEW.md`](./PENDING_TICKET_REVIEW.md) | Construido 2026-08-09. Tabla `pending_ticket_requests` (dedup por `(client_id, from_email)` mientras sigue `pending`, unique real en `(client_id, origin_message_id)` contra reintento de webhook) + `PendingTicketReviewService` (único punto de decisión: registrar, notificar, aprobar, rechazar) + notificación `database` a quien tenga `tickets.review_pending`/`tickets.manage_all` en el tenant + pantalla `/resolbeb/pending-requests`. "Crear usuario nuevo y vincular" reusa la pantalla de Usuarios existente (`?create=1&first_name=&email=`) en vez de duplicar su validación — dos pasos, cero lógica repetida. `wrong_tenant` solo permite rechazar (`users.email` es único global). Verificado con 7 tests nuevos + `composer test` completo (248 pass) + flujo real end-to-end contra el dev server (testco). |
| Calendario de tickets sin hora exacta | — | Arreglado 2026-08-09. El toggle "ver por fecha de alta/cierre" y la lectura de `resolved_at` ya estaban construidos (commit previo sin documentar en este índice). Faltaba la hora: en vista Semana/Día `react-big-calendar` ya mostraba un rango horario automático (`.rbc-event-label`), pero en vista Mes el chip del evento (`CalendarEvent` en `Calendar.jsx`) solo mostraba número de ticket y asunto. Ahora muestra hora exacta junto al folio (`#00123 · 09:30`) en todas las vistas. |
| Toasts: fondo oscuro unificado (los 4 tipos) | — | Arreglado 2026-08-06. `success`/`error`/`warning`/`info` ahora comparten fondo casi negro fijo (`--toast-*: 0 0% 9%`, independiente de tema claro/oscuro), acento de color por tipo en título/badge (mismo tono que `badgeStyles.js`), estilo referencia sileo.aaryan.design. De paso se corrigió un bug real de posicionamiento: el viewport de sileo (`fixed, top:0`) colisionaba con el header sticky de `AuthenticatedLayout`, dejando el toast pegado/cortado detrás de él -- ahora con offset `top: 4.5rem`. Verificado con Chromium headless real contra el dev server. |

## 4. Roadmaps vivos (referencia, no acción pendiente puntual)

- [`RBAC_ROADMAP.md`](./RBAC_ROADMAP.md) — bitácora de fases del modelo de
  roles/permisos por equipo (`team_id`), incluye el bug ya corregido de
  `model_has_roles.team_id` desincronizado para `super_admin`.
- [`MULTITENANT_ROADMAP.md`](./MULTITENANT_ROADMAP.md) — aislamiento por
  tenant (routing, RLS, catálogos).

## Notas

- **Footgun real de RBAC v2, ya mordió dos veces (2026-08-09)**: `Role::findByName()`
  de Spatie (usado por `assignRole()`/`syncRoles()` cuando se les pasa un
  *nombre* en vez de un modelo) **no filtra por el team_id activo**, solo por
  `name`+`guard_name`. Si existe más de un role con el mismo nombre en
  distintos `team_id` (típico: un role global legacy `id=1` + el
  tenant-scoped de `TenantRoleSeeder`), resuelve el primero que encuentre,
  no el del team correcto — sin error, sin warning. Pasó primero en
  producción (`admin@testco.test` quedó con el rol `admin` global en vez del
  de su tenant) y de nuevo en un test nuevo de esta misma sesión
  (`PendingTicketReviewTest`, mismo síntoma). **Ya lo hacía bien**
  `UserController::store()`/`InvitationAcceptanceService` (resuelven el
  modelo `Role` exacto por `id`+`team_id` antes de asignarlo) — ese es el
  patrón a copiar siempre, nunca `assignRole('nombre')`/`syncRoles(['nombre'])`
  a secas cuando el nombre puede repetirse entre teams.
- Todo lo de la sección 1 y 2 fue una decisión explícita de alcance: se
  construyó la infraestructura/diseño y se dejó documentado en vez de
  construir lo que requiere una cuenta externa o una decisión de producto
  más grande (ej. UX de la cola de revisión manual).
- Este archivo debe actualizarse cada vez que se resuelva o se agregue un
  pendiente — es el punto de entrada, los docs individuales tienen el
  detalle.
