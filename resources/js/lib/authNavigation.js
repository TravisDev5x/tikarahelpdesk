import { router } from "@inertiajs/react";

/** Rutas donde no debe redirigirse a login (evita bucles). */
export const GUEST_ONLY_PATHS = [
    "/",
    "/login",
    "/register",
    "/register/accept",
    "/forgot-password",
    "/reset-password",
    "/verify-email",
];

export function isGuestOnlyPath(pathname) {
    const path =
        pathname ??
        (typeof window !== "undefined" ? window.location.pathname : "");
    return GUEST_ONLY_PATHS.some(
        (p) => path === p || path.startsWith(`${p}/`)
    );
}

let redirectInFlight = false;

/**
 * Cierre de sesión explícito en curso -- mientras esté activa, un 401/419
 * disparado por requests que ya estaban en vuelo (o que llegan justo después
 * del POST /api/logout) NO debe mandarnos a /login: es esperado, no una
 * sesión que expiró sola. redirectToLanding() ya se encarga de a dónde ir.
 * Sin esto, si el 401 de un request en vuelo gana la carrera, marca
 * redirectInFlight=true vía redirectToLogin() y el redirectToLanding() de
 * logout() queda como no-op -- el usuario termina en /login en vez de "/".
 */
let explicitLogoutInProgress = false;

export function beginExplicitLogout() {
    explicitLogoutInProgress = true;
}

export function endExplicitLogout() {
    explicitLogoutInProgress = false;
}

export function isExplicitLogoutInProgress() {
    return explicitLogoutInProgress;
}

/**
 * Navegación unificada a login tras logout o 401/419.
 * Usa Inertia (replace + sin estado previo) para evitar quedar en AuthenticatedLayout.
 */
export function redirectToLogin() {
    if (redirectInFlight || isGuestOnlyPath() || explicitLogoutInProgress) {
        return;
    }

    redirectInFlight = true;
    router.visit("/login", {
        replace: true,
        preserveState: false,
        onFinish: () => {
            redirectInFlight = false;
        },
    });
}

/**
 * Navegación tras logout explícito -- a la landing, no a /login (evita
 * mandar al usuario a una pantalla de "vuelve a iniciar sesión" cuando
 * simplemente cerró sesión por su cuenta).
 */
export function redirectToLanding() {
    if (redirectInFlight) {
        return;
    }

    redirectInFlight = true;
    router.visit("/", {
        replace: true,
        preserveState: false,
        onFinish: () => {
            redirectInFlight = false;
        },
    });
}
