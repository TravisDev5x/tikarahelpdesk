# Correo único por tenant para levantar tickets

Cada tenant tiene, automáticamente y sin configuración manual, una dirección de
correo propia para crear tickets desde fuera de la plataforma:

```
soporte@{portal_slug}.{TENANCY_BASE_DOMAIN}
```

Ej. cliente con `portal_slug=cargolift` → `soporte@cargolift.tikara.mx`.

Es el **único canal externo** (fuera de la plataforma) para levantar tickets por
tenant — el cliente comparte esa dirección con su equipo.

## Por qué no hizo falta código de resolución nuevo

`InboundEmailService::resolveTenant()` ya matchea cualquier subdominio de
`TENANCY_BASE_DOMAIN` contra `clients.portal_slug` (fallback #2, antes de
`email_domains` — ver `app/Services/Email/InboundEmailService.php`). Como cada
tenant ya tiene `portal_slug` desde que se crea, la dirección funciona sola.

Lo único que se agregó:
- `Client::getSupportEmailAttribute()` — solo compone el string, no resuelve nada.
- La UI en `Clients/Show.jsx` para que el operador la vea y la copie.

## Pendiente de infraestructura (no es código)

Para que esto funcione en producción, Mailgun necesita **una sola ruta wildcard**
que capture cualquier subdominio de `TENANCY_BASE_DOMAIN` y la reenvíe al webhook
inbound (`POST /api/webhook/inbound-mail/{provider}`) — no una ruta por tenant.

Revisar en Mailgun → Receiving → Routes:

- [ ] Existe una ruta con filtro tipo `match_recipient(".*@.*\.tikara\.mx")` (o el
      dominio base real) apuntando al webhook.
- [ ] El dominio base (`tikara.mx`) tiene registros MX apuntando a Mailgun —
      necesario para RECIBIR correo, además del SPF/DKIM de `MAILGUN_OUTBOUND.md`
      (que es para ENVIAR).
- [ ] Probar con un correo real a `soporte@{portal_slug-de-prueba}.tikara.mx` y
      confirmar que llega al webhook (logs de `ProcessInboundTicket`).

Documento separado de `docs/MAILGUN_OUTBOUND.md` a propósito: ese es sobre
correo saliente (SPF/DKIM del remitente), esto es sobre correo entrante (ruteo
al webhook) — configuraciones distintas en el dashboard de Mailgun.
