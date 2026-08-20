/** Rutas con Inertia::render en routes/web.php (+ destinos tras normalizeLegacyAppPath). */
import { normalizeLegacyAppPath } from "@/lib/legacyRoutes";

const INERTIA_PATH_PREFIXES = [
    "/home",
    "/manual",
    "/areas",
    "/priorities",
    "/impact-levels",
    "/urgency-levels",
    "/campaigns",
    "/positions",
    "/roles",
    "/sessions",
    "/sites",
    "/locations",
    "/ticket-macros",
    "/priority-matrix",
    "/permissions",
    "/audit-command",
    "/calendar",
    "/profile",
    "/users",
    "/settings",
    "/clients",
    "/company",
    "/incidents",
    "/incident-types",
    "/incident-severities",
    "/incident-statuses",
    "/inventory",
    "/resolbeb",
    "/tickets/wallboard",
    "/onboarding",
    "/force-change-password",
    "/login",
    "/register",
    "/register/accept",
    "/forgot-password",
    "/reset-password",
    "/verify-email",
];

export function isExternalUrl(url) {
    if (!url || typeof url !== "string") return false;
    return (
        url.startsWith("http://") ||
        url.startsWith("https://") ||
        url.startsWith("mailto:")
    );
}

export function shouldUseInertiaLink(url) {
    if (!url || isExternalUrl(url)) return false;
    const normalized = normalizeLegacyAppPath(url.split("?")[0]);
    return INERTIA_PATH_PREFIXES.some(
        (prefix) => normalized === prefix || normalized.startsWith(`${prefix}/`)
    );
}

/** className puede ser string o función isActive => string. */
export function resolveNavClassName(className, isActive) {
    if (typeof className === "function") return className(isActive);
    return className ?? "";
}

export { normalizeLegacyAppPath };
