# Pendientes — índice consolidado

Estado al 2026-08-05. Este documento junta, en un solo lugar, todo lo que
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
| Cola de revisión manual para correos no reconocidos | [`PENDING_TICKET_REVIEW.md`](./PENDING_TICKET_REVIEW.md) | Hoy, un correo de alguien que no es usuario activo del tenant simplemente no genera ticket (solo un `Log::warning`, nadie se entera). Falta: tabla de correos pendientes + UI para que un agente los apruebe/rechace manualmente y cree el ticket/usuario según corresponda. |
| Mejorar calendario de tickets (`resources/js/Inertia/Pages/Calendar.jsx`) | — | Ya existe y funciona (agrupa tickets por día usando `created_at`, filtra por estado y por asignado/creado por mí). Falta: (1) mostrar hora exacta, no solo fecha, tanto de creación como en la lista de resultados; (2) mostrar fecha **y hora de cierre** (`tickets.resolved_at`, ya existe en el backend, el frontend nunca lo lee ni lo pinta) — hoy el calendario solo marca días por fecha de creación; (3) idealmente un toggle "ver por fecha de creación / por fecha de cierre" para que se pueda usar como reporte de cierre, no solo de alta. |

## 3. Bugs resueltos recientemente

| Bug | Doc | Resumen |
|---|---|---|
| Colisión de nombres de ruta `api.php` vs `web.php` | [`ROUTE_NAME_COLLISIONS.md`](./ROUTE_NAME_COLLISIONS.md) | Arreglado 2026-08-05. Los 13 `apiResource` de `routes/api.php` que colisionaban con nombres de página de `routes/web.php` (16 nombres en total) ahora se registran con prefijo `api.`. Verificado con `route:list` (0 colisiones) y `composer test`. |

## 4. Roadmaps vivos (referencia, no acción pendiente puntual)

- [`RBAC_ROADMAP.md`](./RBAC_ROADMAP.md) — bitácora de fases del modelo de
  roles/permisos por equipo (`team_id`), incluye el bug ya corregido de
  `model_has_roles.team_id` desincronizado para `super_admin`.
- [`MULTITENANT_ROADMAP.md`](./MULTITENANT_ROADMAP.md) — aislamiento por
  tenant (routing, RLS, catálogos).

## Notas

- Todo lo de la sección 1 y 2 fue una decisión explícita de alcance: se
  construyó la infraestructura/diseño y se dejó documentado en vez de
  construir lo que requiere una cuenta externa o una decisión de producto
  más grande (ej. UX de la cola de revisión manual).
- Este archivo debe actualizarse cada vez que se resuelva o se agregue un
  pendiente — es el punto de entrada, los docs individuales tienen el
  detalle.
