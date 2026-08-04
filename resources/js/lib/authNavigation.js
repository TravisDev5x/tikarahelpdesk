import { router } from "@inertiajs/react";

/** Rutas donde no debe redirigirse a login (evita bucles). */
export const GUEST_ONLY_PATHS = [
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
 * Navegación unificada a login tras logout o 401/419.
 * Usa Inertia (replace + sin estado previo) para evitar quedar en AuthenticatedLayout.
 */
export function redirectToLogin() {
    if (redirectInFlight || isGuestOnlyPath()) {
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
