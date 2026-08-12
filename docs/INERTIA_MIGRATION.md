# Migración frontend — SPA legacy → Inertia único

**Épica:** consolidar UI en `resources/js/inertia.jsx` + `resources/js/Inertia/Pages/`.  
**Estado:** CERRADA — el SPA legacy (`app.jsx`, `Pages/**`, `app.blade.php`) ya no existe en el repo; `routes/web.php` y `routes/sigua.php` renderizan 100% vía `Inertia::render(...)`, sin ningún `view()` fuera de Inertia. Verificado 2026-08-11. Solo queda viva, a propósito, la capa de compatibilidad de la sección siguiente.

## Arquitectura actual

| Antes | Ahora |
|--------|--------|
| `resources/js/app.jsx` + `resources/js/Pages/**` | Eliminado |
| `resources/views/app.blade.php` | Eliminado |
| Entrada Vite | `resources/js/inertia.jsx` |
| Plantilla Blade | `resources/views/inertia.blade.php` |
| Rutas web | `Inertia::render('Module/Page')` en `routes/web.php` |
| Páginas | `resources/js/Inertia/Pages/**/*.jsx` |
| Layout autenticado | `Inertia/Layouts/AuthenticatedLayout.jsx` |
| API | Sin cambios (`routes/api.php`, Axios + Sanctum) |

## Compatibilidad (bookmarks y enlaces viejos)

### Servidor — `routes/inertia_legacy.php`

Redirects 302 para URLs históricas (auth + públicas). Incluye:

- `/dashboard`, `/app` → `/home`
- `/tickets*`, `/resolvev1/*`, `/resolbeb/resolvev1/*` → `/resolbeb/*`
- `/ticket-states`, `/ticket-estados` → `/resolbeb/estados`
- `/incidentes` → `/incidents`
- `/audit-command-center` → `/audit-command`
- `/clientes` → `/clients` (en `web.php`)
- `/invitation/accept` → `/register/accept` (conserva query `token`)

### Cliente — `resources/js/lib/legacyRoutes.js`

`normalizeLegacyAppPath()` reescribe rutas en navegación SPA (Sidebar, `shouldUseInertiaLink`) para que un `<Link href="/tickets">` visite `/resolbeb/tickets` sin recarga completa.

## Checklist PR (cerrado)

- [x] `npm run build` sin errores
- [x] `php artisan test` verde
- [x] Login → `/home` carga dashboard
- [x] Bookmark `/tickets/123` redirige a `/resolbeb/tickets/123`
- [x] Bookmark `/resolvev1/tickets` redirige a `/resolbeb/tickets`
- [x] API sigue en `/api/*` (sin cambio de contrato)
- [x] Landing `/` y auth (`/login`, `/register/accept`) OK

## Fuera de alcance (no confundir con pendiente de esta migración)

- Multi-tenant (fases 1.5–2.x en `docs/MULTITENANT_ROADMAP.md`)
- Renombrar “Resolvev1” en copy interno de componentes (solo rutas unificadas)
- **`routes/inertia_legacy.php` y `resources/js/lib/legacyRoutes.js` se mantienen indefinidamente a propósito** — siguen referenciados activamente por `Sidebar.jsx`/`MobileBottomBar.jsx`/`inertiaNavigation.js`, no son código muerto. No eliminar salvo decisión explícita de retirar soporte a bookmarks/enlaces antiguos.

## Añadir página Inertia nueva

1. Crear `resources/js/Inertia/Pages/Modulo/Page.jsx`
2. Ruta en `routes/web.php`: `Inertia::render('Modulo/Page')`
3. Añadir prefijo en `resources/js/lib/inertiaNavigation.js` si el menú usa `shouldUseInertiaLink`
4. Opcional: redirect legacy en `inertia_legacy.php` si sustituye URL antigua
