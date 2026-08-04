# Login con Outlook / Microsoft 365 (Azure AD)

Checklist para activar "Continuar con Microsoft" en login, registro e
invitaciones. Todo esto es configuración en el portal de Azure — no lo puedo
hacer yo, requiere una cuenta de administrador de tu tenant de Microsoft
Entra ID (Azure AD).

## Qué se construyó (ya listo, solo falta el registro de la app en Azure)

- Paquete `socialiteproviders/microsoft-azure` instalado y registrado
  (`AppServiceProvider::boot()` escucha `SocialiteWasCalled` para el driver
  `azure`).
- Columna `users.microsoft_id` (única, nullable) — espejo de `google_id`.
- `App\Http\Controllers\Auth\MicrosoftAuthController` — mismo patrón que
  `GoogleAuthController`: `redirect()`, `callback()`, `handleLogin()`
  (usuario ya existente, hace login) y `handleInvitation()` (acepta una
  invitación pendiente con la identidad de Microsoft).
- Rutas `GET /auth/microsoft/redirect` y `GET /auth/microsoft/callback`.
- Botón "Continuar con Microsoft" en Login, Registro y Aceptar invitación —
  se muestra deshabilitado ("Próximamente disponible") mientras
  `AZURE_CLIENT_ID`/`AZURE_CLIENT_SECRET` no estén configurados, igual que
  pasa hoy con Google.
- `authProviders.microsoft` se comparte al frontend vía Inertia
  (`HandleInertiaRequests`).

## 1. Registrar la aplicación en Azure

1. [Azure Portal](https://portal.azure.com/) → **Microsoft Entra ID** →
   **App registrations** → **New registration**.
2. Nombre: p. ej. "Tikara — Login".
3. **Supported account types**: elige según a quién le vas a dar acceso:
   - Solo tu organización → "Accounts in this organizational directory only"
     (requiere fijar `AZURE_TENANT_ID` a tu Tenant ID real).
   - Cualquier cliente con cuenta Microsoft/365 de cualquier empresa →
     "Accounts in any organizational directory" (deja `AZURE_TENANT_ID=common`).
4. **Redirect URI**: tipo **Web**, valor
   `https://tu-dominio/auth/microsoft/callback` (y también
   `http://tikara.test/auth/microsoft/callback` para desarrollo local, se
   pueden registrar varias).

## 2. Client secret

1. En la app registrada → **Certificates & secrets** → **New client secret**.
2. Copia el **Value** (no el Secret ID) inmediatamente — no se vuelve a
   mostrar. Va en `AZURE_CLIENT_SECRET`.

## 3. Permisos (API permissions)

Por default `User.Read` (Microsoft Graph, delegado) ya viene agregado — es
el único permiso que usa este login (perfil básico: nombre, correo). No
requiere consentimiento de administrador si el tenant lo permite por
default; si tu organización lo restringe, un admin debe dar "Grant admin
consent" una sola vez.

## 4. Variables de entorno

```env
# .env del backend
AZURE_CLIENT_ID=
AZURE_CLIENT_SECRET=
AZURE_TENANT_ID=common
AZURE_REDIRECT_URI=https://tu-dominio/auth/microsoft/callback
```

- `AZURE_TENANT_ID`: el **Tenant ID** (Directory ID) si restringiste el
  registro a tu organización; déjalo en `common` si aceptas cualquier cuenta
  Microsoft/365.
- `AZURE_REDIRECT_URI`: debe coincidir **exactamente** con el redirect URI
  registrado en el paso 1. Si se omite, se arma automáticamente como
  `{APP_URL}/auth/microsoft/callback`.

## 5. Probar

1. Con las variables en `.env`, reinicia `composer dev` (o `php artisan
   config:clear` en producción).
2. En `/login`, el botón "Continuar con Microsoft" debe pasar de
   deshabilitado a activo.
3. Flujo esperado: click → pantalla de login de Microsoft → de regreso a la
   app ya autenticado (si el correo de la cuenta Microsoft coincide con un
   usuario existente) o con el error "No hay cuenta registrada con este
   correo" (si no existe usuario ni invitación).
4. Para invitaciones: el link de invitación (`/register/invitations/...` o
   como se comparta) debe mostrar también el botón de Microsoft cuando
   `microsoft_enabled` esté activo.

Si falla con "No se pudo completar el inicio de sesión con Microsoft":
revisar que el redirect URI en Azure coincida byte a byte con
`AZURE_REDIRECT_URI`, y que el client secret no haya expirado (los secrets
de Azure tienen fecha de caducidad configurable, revisar "Certificates &
secrets").

## Notas de diseño

- El correo se toma de `mail` (buzón real) del perfil de Microsoft Graph,
  con fallback a `userPrincipalName` si `mail` viene vacío (pasa con algunas
  cuentas personales o de invitado).
- Igual que con Google, si un usuario existente en la BD tiene el mismo
  correo pero nunca inició sesión con Microsoft antes, el primer login por
  Microsoft vincula automáticamente `microsoft_id` a esa cuenta (no crea una
  cuenta duplicada).
- El registro público (no invitación) sigue mostrando el botón de Microsoft
  deshabilitado — igual que Google, alta de cuenta nueva vía OAuth público
  no está implementada aún, solo login e invitaciones.
