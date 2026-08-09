# Cola de revisión manual para correos no reconocidos

**Estado: construido 2026-08-09.** Diseño original decidido el 2026-08-04 (más
abajo, sin editar — sigue siendo la referencia de intención). Ver
`docs/PENDING.md` sección 3 para el resumen de qué se construyó y qué quedó
fuera de alcance (MVP: "crear usuario nuevo" reusa `/users` en vez de un
formulario embebido).

## Comportamiento actual (ya construido, funcionando)

`ProcessInboundTicket` resuelve el tenant desde el correo destino (ver
`docs/TENANT_SUPPORT_EMAIL.md`) y luego valida al remitente vía
`TicketCreationService::resolveActiveTenantUser()`. Si el remitente **no** es un
usuario ya registrado y activo de ese tenant, el sistema:

1. No crea ningún ticket.
2. Le manda `RegistrationRequiredMail` pidiéndole que se registre.
3. Solo queda un `Log::warning` — nadie del tenant se entera.

Política original, explícita en el código (`ProcessInboundTicket.php`): *"un email =
un tenant, y solo usuarios ya registrados pueden generar tickets por correo — nada de
cuentas guest implícitas"*.

## Comportamiento deseado

En vez de rechazar en silencio, que un humano (agente o encargado de TI del tenant)
decida caso por caso: aceptar (vinculando a un usuario existente o creando uno nuevo)
o rechazar.

## Por qué no es un cambio chico

`tickets.requester_id` es `NOT NULL` con FK a `users` — no existe forma de crear un
`Ticket` sin un usuario real al que atarlo, y esa columna se usa para scoping/
visibilidad en policies, notificaciones y servicios en todo el código. Ampliarla a
nullable es riesgoso y toca muchos archivos — no es el camino.

## Diseño propuesto (sin tocar `tickets`)

1. **Tabla nueva** `pending_ticket_requests` — no pasa por las constraints de
   `tickets`. Columnas: `client_id`, `from_email`, `from_name`, `subject`, `body`,
   adjuntos (si aplica), `reason` (unregistered / wrong_tenant / inactive — mismos
   motivos que ya calcula `resolveActiveTenantUser()`), `status`
   (pending/approved/rejected), `reviewed_by`, `reviewed_at`.
2. **`ProcessInboundTicket`**: cuando el remitente se rechaza, además de mandar
   `RegistrationRequiredMail` (se mantiene, transparencia con quien escribió), crea
   la fila en `pending_ticket_requests` y dispara una notificación nueva a los
   agentes/encargados del tenant en vez de solo loguearlo.
3. **Pantalla nueva** ("Solicitudes pendientes") con las acciones:
   - Vincular a un usuario existente → crea el `Ticket` real vía
     `TicketCreationService`, igual que los demás flujos.
   - Crear usuario nuevo y vincular → si es alguien legítimo sin cuenta aún.
   - Rechazar.
4. **Permiso** nuevo o reutilizar `tickets.manage_all` para gatear quién revisa —
   candidato natural para una plantilla de rol tipo "Encargado TI" vía la UI de
   RBAC v2 (Fase 7).

## Alcance real

Migración + modelo + cambios en el job de correo entrante + notificación nueva +
controlador/rutas + pantalla de UI completa. Más grande que el trabajo de esta
sesión (Fase 7 UI + correo por tenant) junto.
