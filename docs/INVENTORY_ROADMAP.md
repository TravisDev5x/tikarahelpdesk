# Roadmap del módulo Inventario (port desde HelpdeskECD2026)

Punto de retoma oficial de este port — no depender de memoria de conversación. HelpdeskECD2026 vive en `/home/ericpc96/HelpdeskECD2026` (mismo usuario, fuera de este repo) si hace falta comparar contra el original; solo su módulo **V2** (`inv_*`) es relevante — V1 (`assets`/`components`/`maintenances`) es legado ya descartado, confirmado por auditoría 2026-08-20.

## Fases cerradas

| Fase | Contenido | Piezas clave |
|---|---|---|
| 1 | Catálogos base | `inv_categories`, `inv_statuses`, `inv_labels`, `inv_maintenance_origins`, `inv_maintenance_modalities` — compartidos a nivel operador/plataforma, NO aislados por `client_id` por defecto (ver `docs/CATALOG_TENANCY_MODEL.md`, decisión de producto ya tomada) |
| 2 | Registro de activos | `inv_assets` + `inv_asset_images`, `InvAssetController`, `StoreInvAssetRequest` |
| 3 | Ciclo de vida | `inv_movements` (bitácora inmutable: `CHECKOUT`/`CHECKIN`/`TRASLADO`/`BAJA`), `InvMovementController` |
| 4 | Componentes + despiece | `inv_components`, `inv_component_movements`, `InvComponentController`, `InvAssetDisassemblyController` |
| 5 | Mantenimientos | `inv_maintenances` (editable, no bitácora), `InvMaintenanceController`, genera `InvMovement` tipo `MAINTENANCE` al abrir |
| 6 | Import masivo por Excel/CSV | `InvAssetImportService` (síncrono, sin cola ni historial persistido — decisión deliberada, ver "Pendientes" abajo) |
| — | Cuota de activos por plan (previo a fase 7) | `plans.max_assets` (nullable = ilimitado), enforcement en `InvAssetController::store()` e `InvAssetImportService::import()`, mismo patrón que `Plan.max_users`/`seatUsage()` |
| 7.1 | Dashboard de alertas | `InvMonitorPageController`, página `Inventory/Monitor.jsx` — 4 alertas: garantías por vencer, sin responsable, traslados repetidos, mantenimientos estancados |
| 7.2 | Exports (Excel/CSV) | `InvAssetExportController`/`InvAssetExport` (4 hojas), `InvMovementExportController` (CSV real, `applyInventoryMovementScope` nuevo en `ClientScopeService`), `InvMonitorExportController`/`InvMonitorExport` (workbook con las 4 alertas). Filtro `assigned` nuevo en `InvAssetController::index()` y en `Assets/Index.jsx` |
| 7.3 | Refinamiento de `Assets/Index.jsx` (detalle en modal, paginación real, filtros ágiles) | Detalle de activo pasa de página dedicada (`Assets/Show.jsx`, retirada) a modal (`Assets/AssetDetailDialog.jsx`), mismo patrón que alta/edición (`AssetFormDialog.jsx`); `show()` de la API ahora carga `movements`/`components`/`maintenances` completos; paginación real con selector de tamaño de página (antes `InvAssetPageController::index()` ignoraba por completo los filtros del formulario); orden PEPS/UEPS por fecha de compra; filtros restyleados al patrón compacto y de aplicación instantánea de la lista de Tickets (búsqueda con debounce, Selects que aplican solos, badge de conteo, botón Limpiar — sin botón "Aplicar filtros"); paginación con el mismo estilo visual que Tickets. Corrige de paso un bug donde un array PHP vacío serializaba como JSON `[]` en vez de `{}`, tumbando `useState()` en el navegador (`filters.sort` colisionaba con `Array.prototype.sort`), y un N+1 real en `InvMovementExportController` (`cursor()` no respeta `with()`) encontrado con datos de volumen realista |
| 7.4 | Permisos granulares de Activos (ver/editar/gestionar) | Retomada tras la pausa de media conversación. Decisiones de producto: "editar" = todo lo operativo EXCEPTO eliminar (3 niveles reales, no 2); el split aplica SOLO a Activos, Catálogos/Config sigue atómico. `inventory.view_assets` (solo lectura) e `inventory.edit_assets` (todo lo operativo, SIN eliminar) nuevos, junto al ya existente `inventory.manage_assets` (ahora el nivel completo, incluye eliminar). `routes/api.php` divide el bloque único de Activos en 3 grupos de middleware `perm:a\|b\|c`; `routes/web.php` acepta cualquiera de los 3 para entrar a `/inventory/assets`/`/inventory/monitor`. Catálogo RBAC v2 (`AuthorizationObject`) gana una columna `edit_permission` nueva (nullable, solo la usa "Activos" — todo lo demás sigue siendo full/read de 2 niveles) para que `RoleTemplateFormDialog.jsx` ofrezca un tercer nivel real "Editar (sin eliminar)" junto a Full/Solo lectura/Ninguno. Frontend (`Assets/Index.jsx`, `AssetDetailDialog.jsx`) esconde cada botón de mutar según el nivel real del usuario (`auth.user.permissions`), coherente con lo que la API ya acepta. Config (`inventory.manage_config`) NO se tocó — decisión de producto: el split solo aplica a Activos |

Todas las fases tienen tests de aislamiento cross-tenant en `tests/Feature/Inventory*Test.php` (11 archivos, 42 tests propios de Inventario; suite completa del proyecto en 357 tests pasando a fecha 2026-08-20).

## Pendiente

### Vista de asignación consolidada

No iniciada. Parte del roadmap original "Fase 7: vistas de asignación, dashboard, exports" — 7.1 (dashboard) ya cerrada, falta una vista tipo "roster" que muestre de un vistazo qué usuario tiene qué activos asignados (join sobre `inv_assets.current_user_id`), distinta del historial de movimientos que ya existe por activo individual en el modal de detalle (`Assets/AssetDetailDialog.jsx`).

### Import con historial persistente + preview antes de confirmar

Decisión de diseño reconsiderable, no un pendiente urgente. Fase 6 decidió deliberadamente síncrono y sin tabla de historial (ver comentario en `app/Services/InvAssetImportService.php`). HelpdeskECD2026 sí lo tiene: `inv_import_batches` (usuario, archivo, modo, resumen, status `previewed`/...) + `inv_import_rows` (una fila por renglón, con `payload`/`parsed`/`errors`/`action`/`status`, guardado indefinidamente) — soporta previsualizar antes de confirmar y auditar "quién importó qué y cuándo". Vale la pena reabrir esta decisión si el negocio pide esa trazabilidad; no reintroducir sin que alguien lo pida explícitamente.

## Deuda técnica documentada (de fases ya cerradas, no bloqueante)

- **Export de activos sin filtro de rango de fechas** (`created_at`/`purchase_date`) — el original (`InventoryExportController`) sí lo tiene; se dejó fuera en 7.2 a propósito (requiere un date-range picker que no existe todavía en `Assets/Index.jsx`), no es un olvido.
- **Export de historial de movimientos sin UI de filtros** — el backend (`InvMovementExportController`) ya acepta `search`/`type`/`user_id`/`admin_id`/`date_from`/`date_to`/`batch_uuid`, pero el botón "Exportar historial" de `Assets/Index.jsx` exporta todo el tenant sin exponer esos filtros en pantalla todavía.

- `inv_assets.specs` es texto libre (`{notes: string}`), no estructurado — decisión fase 2 (`InvAssetController::packSpecs()`).
- `inv_movements.responsiva_path` (fase 3, checkout) e intención de `attachments` en mantenimientos (fase 5) — el campo/intención existe pero sin UI de subida de archivo real todavía. Cuando se construya la de mantenimientos, fase 5 dejó anotado usar una tabla hija tipo `InvAssetImage`, no un campo JSON de rutas sueltas.
- No hay panel de administración de `plans.*` (incluye el nuevo `max_assets`) — los 5 planes se seedan vía `PlanSeeder.php`, sin UI de edición; deuda preexistente del sistema de licenciamiento en general (ver `CLAUDE.md` sección "Fase 8 — Licenciamiento"), no específica de Inventario.
