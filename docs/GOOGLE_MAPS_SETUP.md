# Google Maps Platform — dirección + mapa en clientes y sedes

Checklist para que la geocodificación de direcciones y el mapa embebido
funcionen en producción. Todo esto es configuración en Google Cloud Console —
no lo puedo hacer yo, requiere tu cuenta/tarjeta.

## Qué se construyó (ya listo, solo falta la key)

- `address` + `latitude`/`longitude` en `clients` y `sites`.
- `POST /api/geocode` (server-side, gateado por `catalogs.manage` + throttle
  20/min) — usa `GoogleGeocodingService` para convertir una dirección en
  coordenadas. La key de este lado **nunca** llega al navegador.
- Formulario de cliente (`Clients/Form.jsx`): dirección propia + dirección
  por cada sede, cada una con botón "Buscar en el mapa".
- Vista de cliente (`Clients/Show.jsx`): mapa embebido + link "Cómo llegar"
  para el cliente y cada sede.
- El link "Cómo llegar" (deep link a Google Maps) **funciona sin ninguna
  key** — solo el mapa embebido y la búsqueda de dirección la necesitan.

## 1. Proyecto y facturación

1. [Google Cloud Console](https://console.cloud.google.com/) → crear o
   seleccionar un proyecto.
2. Activar facturación (piden tarjeta aunque te quedes en el nivel gratis —
   $200 USD de crédito mensual, ~40,000 geocodificaciones).

## 2. Habilitar las APIs

En el proyecto, "APIs & Services" → "Library", habilitar:

- **Geocoding API** (dirección → coordenadas, la usa `GoogleGeocodingService`).
- **Maps Embed API** (el mapa que se ve en la pantalla del cliente).

## 3. Crear dos API keys, cada una restringida distinto

**Key de servidor** (`GOOGLE_MAPS_SERVER_KEY`, va en `.env` del backend, nunca
se expone):
- "Credentials" → "Create credentials" → "API key".
- Restricción de aplicación: **IP addresses** — la(s) IP de tu servidor de
  producción.
- Restricción de API: solo **Geocoding API**.

**Key de embed** (`VITE_GOOGLE_MAPS_EMBED_KEY`, esta SÍ es visible en el
HTML — se protege con restricción de dominio, no con secreto):
- Otra API key nueva.
- Restricción de aplicación: **HTTP referrers** — tu(s) dominio(s)
  (`https://tikara.mx/*`, `https://*.tikara.mx/*`).
- Restricción de API: solo **Maps Embed API**.

## 4. Variables de entorno

```env
# .env del backend -- server-side, secreta
GOOGLE_MAPS_SERVER_KEY=

# .env también, pero con prefijo VITE_ -- Vite la incluye en el bundle del
# navegador en build time. Sin esto, la app sigue funcionando (el link
# "Cómo llegar" no la necesita), solo no se ve el mapa embebido.
VITE_GOOGLE_MAPS_EMBED_KEY=
```

Después de agregar `VITE_GOOGLE_MAPS_EMBED_KEY`, hay que correr `npm run
build` de nuevo — Vite la incluye al momento de compilar, no en runtime.

## 5. Probar

```bash
# Con sesión autenticada (perm catalogs.manage), debe regresar lat/lng:
curl -X POST https://tu-dominio/api/geocode \
  -H "Content-Type: application/json" \
  -d '{"address":"Av. Insurgentes Sur 1602, CDMX"}'
```

Si falla con "No se pudo ubicar esa dirección": revisar que
`GOOGLE_MAPS_SERVER_KEY` esté en `.env`, que la Geocoding API esté
habilitada, y que la restricción de IP incluya el servidor real.
