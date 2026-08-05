# Colisión de nombres de ruta: `routes/api.php` vs `routes/web.php`

**Estado: arreglado (2026-08-05).** Encontrado en vivo el 2026-08-04
probando el flujo de "Mi empresa" como `super_admin` (ver
`docs/RBAC_ROADMAP.md` para el bug de `team_id` que destapó esta ruta de
código por primera vez).

Fix aplicado en `routes/api.php`: los 13 `apiResource(...)` que colisionaban
con nombres de `routes/web.php` ahora están envueltos en
`Route::name('api.')->group(...)`, anidado dentro del grupo de middleware
existente (para no afectar las rutas sin nombre del mismo grupo, como
`geocode` o `priority-matrix/bulk`). Verificado con
`php artisan route:list` (0 colisiones de nombre en toda la app) y
`composer test` (231 passed). Confirmado en vivo: `GET /company` como
`super_admin` ahora redirige a `/clients` (página Inertia), no a
`/api/clients` (JSON).

## El problema

`routes/api.php` registra varios `Route::apiResource(...)` **sin prefijo de
nombre**, generando nombres estándar (`clients.index`, `clients.store`,
etc.) que coinciden exactamente con los nombres que `routes/web.php` ya usa
para sus propias páginas Inertia. Laravel no valida colisiones de nombre al
registrar rutas — la última definición registrada para un nombre dado gana
en la tabla de resolución de `route()`. En este proyecto, `api.php` se
carga después de `web.php` (`bootstrap/app.php`), así que **las rutas API
ganan siempre**: cualquier `route('clients.index')` en código PHP resuelve
a `/api/clients` (JSON), no a `/clients` (la página Inertia).

## Las 16 colisiones (confirmado con `php artisan route:list`)

Todas vienen de `Route::apiResource(...)` en `routes/api.php` sin
`->name('api.')`:

```
clients.index, clients.store, clients.update, clients.destroy
sites.index, areas.index, campaigns.index, positions.index
locations.index, priorities.index, impact-levels.index, urgency-levels.index
incidents.index, incident-types.index, incident-severities.index, incident-statuses.index
```

## Impacto real hoy (no teórico)

De las 16, **solo `clients.index` se usa vía `route()` en código PHP** —
las otras 15 son colisiones latentes (el mismo problema de fondo, pero
nada las invoca por nombre todavía, así que no rompen nada *hasta que
alguien agregue un `route('sites.index')` o similar*). Sitios afectados
ahora mismo:

- `app/Http/Controllers/CompanyController.php:42` — el redirect de
  `super_admin` a "ver clientes" cae en `/api/clients` (JSON) en vez de
  `/clients`, y termina llevando al usuario a Inicio.
- `app/Http/Controllers/Web/ClientController.php:255,270,285` — los
  redirects post-crear/editar/eliminar cliente probablemente tienen el
  mismo problema (no confirmado en vivo, mismo mecanismo).

## Fix recomendado (no aplicado, queda para después)

En `routes/api.php`, prefijar el nombre de los `apiResource` colisionando,
ej.:

```php
Route::name('api.')->group(function () {
    Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class);
    Route::apiResource('sites', \App\Http\Controllers\Api\SiteController::class);
    // ... el resto de los apiResource listados arriba
});
```

Esto cambia los nombres API a `api.clients.index`, etc. — no rompe nada del
frontend (Axios llama URLs literales tipo `/api/clients`, no nombres de
ruta), pero si algo interno referencia esos nombres por texto habría que
revisar antes de aplicar. Después de renombrar, verificar con
`php artisan route:list --name=clients.index` que solo quede la ruta web.
