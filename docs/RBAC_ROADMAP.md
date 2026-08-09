# Bitácora de fases — RBAC y autorización de tickets

> **Nota de alcance**: este documento y `docs/MULTITENANT_ROADMAP.md` usan
> ambos el patrón "Fase N" pero son **hilos distintos** — no comparten
> numeración ni contenido, aunque coincidan en el número. El roadmap
> multi-tenant cubre aislamiento por tenant (routing, RLS, catálogos); este
> documento cubre el modelo de roles/permisos y su aplicación en tickets
> (site scoping, reasignación, visibilidad de agente, notificaciones, y
> ahora roles por equipo). Si un commit dice "Fase 4" sin más contexto,
> hay que mirar el mensaje completo para saber a cuál de los dos hilos
> pertenece.
>
> Reconstruido desde `git log` (los mensajes de commit referencian un
> "sprint maestro" que nunca se documentó por escrito) — no es un plan
> prospectivo, es el registro de lo que ya se construyó, para no perder
> el hilo hacia adelante.

## Fase 3 — Roles nuevos (identidad y autorización)

| Commit | Qué se hizo |
|---|---|
| `ee9edf6` (2026-07-12) | `TenantRoleSeeder` crea 4 roles nuevos (`admin`, `supervisor`, `agente`, `solicitante`) mapeados 1:1 desde los roles legacy (mismo set de permisos, sin rediseño). Único permiso nuevo: `tickets.reassign`. Los roles legacy no se tocan ni se eliminan; `roles:migrate-legacy` asigna el nuevo rol de forma aditiva. |

## Fase 4 — Scoping de tickets por site

| Commit | Qué se hizo |
|---|---|
| `2e9fee7` (2026-07-14) | Migra la autorización de `TicketPolicy` de área (`area_current_id`) a `site_user` (pivote staff↔site). Corrige bug donde `update()`/`assign()`/`release()`/`escalate()` seguían gateados por área legacy mientras `view()` ya usaba sites — un agente podía ver un ticket pero fallar todas las mutaciones. Nuevo `withinStaffSiteScope()` centraliza el criterio. |

## Fase 5 — Reasignación, visibilidad e identidad de agente

| Sub-fase | Commit | Qué se hizo |
|---|---|---|
| 5.1 | `73cf578` (2026-07-14) | Separa `assign()` (ticket sin dueño) de `reassign()` (mover uno ya asignado) como abilities distintas con permisos distintos (`tickets.assign` vs `tickets.reassign`). Elimina dos obstáculos legacy en `TicketController::assign()` que anulaban lo que la policy ya permitía. Agrega `TicketReassignedMail`. |
| 5.2 | `d9d6d86` (2026-07-14) | `clients.show_agent_names` (default `true`, sin cambio de comportamiento por defecto): cuando está en `false`, `MyTicketsController` enmascara nombre/email del agente ante el solicitante. Comando `tenants:set-agent-visibility`. |
| 5.3 (cierre) | `784b554` (2026-07-14) | `SendTicketNotification::recipients()` resolvía destinatarios por `area_current_id` (legacy) — un agente vinculado solo por `site_user` nunca recibía notificaciones de un ticket que sí podía atender. Nuevo `TicketPolicy::notifiableStaff()` reutiliza el mismo criterio de `withinStaffSiteScope()`. |

## Fase 6 — RBAC v2: roles por equipo, plantillas y overrides

| Commit | Qué se hizo |
|---|---|
| `2caab15` (2026-08-04) | Activa `teams` de spatie/laravel-permission (`team_id` = `clients.id`, `super_admin_team_id=0` como centinela cross-tenant) para aislar roles/permisos por operador. Agrega catálogo `AuthorizationObject`, plantillas de rol editables (`RoleTemplateController`/`Service`) con `scope_archetype` + permisos por objeto, y overrides directos de permiso por usuario (`UserPermissionOverrideController`). Esto **es** la tarea 3.1 de `docs/MULTITENANT_ROADMAP.md` ("Roles Spatie por operador — `operator_user_id` o teams"), completada bajo la numeración de este hilo, no la del roadmap. |

## Fase 7 — Plantilla "Encargado TI"

| Commit | Qué se hizo |
|---|---|
| (pendiente de commit, 2026-08-09) | `TenantRoleSeeder` agrega una 5ta plantilla por defecto, "Encargado TI" (`scope_archetype='agente'` + `tickets.review_pending`), para revisar la cola de correos no reconocidos (`docs/PENDING_TICKET_REVIEW.md`) sin necesitar `tickets.manage_all` completo. A diferencia de los 4 originales (nombres código en minúscula), el nombre de esta plantilla es libre/legible ("Encargado TI") — el slug se deriva con `Str::slug()`. Backfill para tenants ya existentes: `php artisan tenants:seed-default-roles {portal_slug}` (idempotente, solo agrega lo que falte). Verificado con test nuevo en `TenantRoleSeederTest` + `composer test` completo (249 pass) + verificación manual contra el tenant `testco` real. |

## Hallazgo relacionado, sin resolver (candidato a una futura fase)

`App\Console\Commands\SeedDefaultTenantRoles`'s docblock ya decía, desde
Fase 6: *"Es exactamente lo que Fase 7 (onboarding) va a invocar al
completar el alta de un tenant nuevo -- deliberadamente NO conectado a
onboarding todavía"*. Confirmado en esta sesión que sigue sin conectar:
`OperatorOnboardingController::store()`/`storeClient()` no invocan
`TenantRoleSeeder` ni `tenants:seed-default-roles` en ningún punto — un
tenant nuevo dado de alta hoy vía onboarding queda **sin ningún rol de
RBAC v2 sembrado** hasta que alguien corra el comando a mano. No se tocó
en esta fase (el alcance acordado fue solo la plantilla nueva) pero es la
pieza más grande que falta de este hilo.

## Próximas fases (sin empezar)

Ninguna definida todavía más allá del hallazgo de arriba. Cuando arranque
la siguiente fase de este hilo, agregarla aquí con el mismo formato antes
de hacer el commit correspondiente — no después — para no repetir el
problema que motivó este documento.
