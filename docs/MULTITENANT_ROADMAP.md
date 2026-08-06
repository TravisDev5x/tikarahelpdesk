# Plan de trabajo — multi-tenant al 100%

Estado base (2026-06): aislamiento en aplicación (MSP + portal + login tenant-aware + tests). Sprint actual: auditoría API, RLS mínimo PG, documentación Sanctum.

## Fase 0 — Infra y configuración (1 semana)

| # | Tarea | Criterio de hecho |
|---|--------|-------------------|
| 0.1 | Ejecutar migraciones en staging/prod | `migrate` OK + `tenant:client-id verify` |
| 0.2 | DNS + TLS wildcard o por cliente | Portales resuelven a la app |
| 0.3 | `.env` según `docs/SANCTUM_TENANCY.md` | Login API + Inertia con cookies en raíz y subdominios |
| 0.4 | Usuario BD PostgreSQL **no superuser** para RLS | `TENANCY_PGSQL_RLS=true` filtra filas en tickets/incidents/sites |
| 0.5 | Backups y restore probados por entorno | Runbook documentado |

## Fase 1 — Cierre de fugas en aplicación (2–3 semanas)

| # | Tarea | Criterio de hecho |
|---|--------|-------------------|
| 1.1 | `ManagesOperatorCatalog` en todos los controladores de catálogo API | ✅ Completado (`CatalogApiOperatorScopeTest`) |
| 1.2 | Modelo catálogos operador+plataforma (no por `client_id`) | ✅ `docs/CATALOG_TENANCY_MODEL.md`, `TENANCY_CATALOG_PER_CLIENT`, `CatalogPortalTenancyTest` |
| 1.3 | `SessionMonitorController` + exports/jobs con scope | ✅ `SessionMonitorScopeTest`, scope en `applyUserScope` / audit export |
| 1.4 | Invitaciones: validar `client_id` vs host del portal | ✅ Flujo B + `InvitationFlowTest`, Google OAuth opcional |
| 1.5 | Eliminar `usesLegacyMspWideAccess` en prod (migrar admins a `is_operator`) | ✅ `TENANCY_LEGACY_MSP_WIDE_ACCESS` (default false) + `tenant:promote-legacy-operators` |
| 1.6 | Branding `tenant` en layout autenticado | ✅ Sidebar, header móvil, acento `--brand`; consola MSP sin cambios |
| 1.7 | Comando `tenant:seed-catalogs {operator_user_id}` | ✅ Idempotente; plantilla fallback; herencia compatible con filas plataforma |

## Fase 2 — Tests y CI (1–2 semanas)

| # | Tarea | Criterio de hecho |
|---|--------|-------------------|
| 2.1 | Suite `TenantApiIsolationTest` (2 clientes, mismo MSP) | ✅ tickets, incidents, sedes, clientes; portal estricto + `usesOperatorMspWideScope` |
| 2.2 | Job CI con PostgreSQL + `TENANCY_PGSQL_RLS=true` | ✅ `.github/workflows/tests.yml` (SQLite + PG/RLS), `phpunit.pgsql.xml`, `PgsqlTenantRlsTest` |
| 2.3 | `php artisan tenant:client-id verify --strict` en CI post-migrate | ✅ Falla build si huérfanos o `client_id` NULL |
| 2.4 | Checklist release en `API_TENANCY_AUDIT.md` | ✅ Obligatorio en cada deploy |

## Fase 3 — Identidad y autorización (2 semanas)

| # | Tarea | Criterio de hecho |
|---|--------|-------------------|
| 3.1 | Roles Spatie por operador (`operator_user_id` o teams) | ✅ `teams` activo (`team_id`=`clients.id`), commit `2caab15` — detalle en `docs/RBAC_ROADMAP.md` (Fase 6 de ese hilo) |
| 3.2 | Auditoría: operador entra a portal cliente | `audit_logs` con `client_id` + actor |
| 3.3 | Límites por plan (`max_clients`, `max_users`) en middleware | 403 al superar cuota |
| 3.4 | Rate limit login por `portal_slug` + IP | Mitigar fuerza bruta |

## Fase 4 — Base de datos defensiva (2 semanas)

| # | Tarea | Criterio de hecho |
|---|--------|-------------------|
| 4.1 | RLS en `clients`, `users` (sede), `audit_logs` | Políticas + variables sesión |
| 4.2 | FK `client_id` NOT NULL + ON DELETE RESTRICT donde aplique | Sin huérfanos |
| 4.3 | Índices únicos compuestos `(client_id, code)` en sedes/catálogos por tenant | Migraciones |
| 4.4 | Revisión queries raw / dashboards | `SqlDialect` + scope en todos |

## Fase 5 — Producto y operaciones (continuo)

| # | Tarea | Criterio de hecho |
|---|--------|-------------------|
| 5.1 | SIGUA: aislar por `operator_user_id` o despliegue separado | Documentado + código |
| 5.2 | Subdominio MSP (`operador.tikara.test`) | Modo `msp_console` |
| 5.3 | SSO/OIDC por cliente (opcional) | Login federado en portal |
| 5.4 | Panel “URL de portal” en ficha cliente + copiar enlace | UX operador |
| 5.5 | ✅ Monitoreo: intentos login portal incorrecto, 403 tenant | Hecho 2026-08-05 — `TenantContextService::logBoundaryViolation()` escribe a `audit_logs`, pestaña "Seguridad" en `/audit-command`. Falta: alertas proactivas (hoy hay que entrar a verla, no notifica solo). |

## Definición de “100 % sólido”

1. **Ningún endpoint** operativo devuelve datos de otro `client_id` / operador MSP (tests + auditoría API).  
2. **PostgreSQL RLS** activo en prod con usuario de aplicación restringido.  
3. **Sesión** correcta en raíz y portales según política de cookies elegida.  
4. **Sin legacy** admin sin operador en producción.  
5. **Runbook** migrate, verify, rollback, y checklist de release.

## Orden recomendado (próximos 30 días)

```text
Semana 1: Fase 0 + 1.1 + 1.7
Semana 2: Fase 1.2–1.6 + Fase 2.1
Semana 3: Fase 2.2–2.4 + Fase 3.2
Semana 4: Fase 4.1 + 4.2 + revisión SIGUA (5.1)
```

## Documentos relacionados

| Documento | Contenido |
|-----------|-----------|
| `docs/DATABASE_TENANCY.md` | Modelo BD, scopes, RLS |
| `docs/CLIENT_PORTAL.md` | Subdominios y portal |
| `docs/API_TENANCY_AUDIT.md` | Rutas API y gaps |
| `docs/SANCTUM_TENANCY.md` | Cookies y Laragon/prod |
| `docs/RBAC_ROADMAP.md` | Fases del hilo de roles/permisos de tickets (numeración propia, no coincide con las Fases de este documento) |
| `config/tenancy.php` | Flags |
