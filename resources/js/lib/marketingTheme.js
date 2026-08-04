/**
 * Clases compartidas auth/landing (opción A — tokens shadcn + acento --brand).
 * En claro, .surface-marketing redefine --background/--card en app.css (mkt-*).
 */

/** Gradiente marca (sin utilidades blue-* sueltas) */
export const brandGradientFill =
    "bg-gradient-to-r from-[hsl(var(--brand))] to-[hsl(var(--brand-muted))]";

/** Halo decorativo en panel lateral auth */
export const brandPanelGlow =
    "absolute -top-20 -left-20 w-96 h-96 rounded-full blur-3xl bg-gradient-to-br from-[hsl(var(--brand)/0.1)] to-[hsl(var(--brand-muted)/0.1)] -z-10 pointer-events-none";

/** Hero / panel lateral auth — patrón de puntos (clase .mkt-dots en CSS) */
export const heroSectionClass = "relative min-h-screen flex items-center pt-24 pb-16 px-6 mkt-section-default mkt-dots";

/** Contenedor raíz landing */
export const surfaceRoot = "surface-marketing min-h-screen mkt-section-default text-foreground antialiased";

/** Contenedor raíz login/registro */
export const surfaceAuth = "surface-auth min-h-screen bg-background text-foreground antialiased";

export const brandLogo =
    `flex items-center justify-center rounded-xl bg-gradient-to-br from-[hsl(var(--brand))] to-[hsl(var(--brand-muted))] font-black tracking-tight text-brand-foreground shadow-[0_2px_16px_hsl(var(--brand)/0.45)] ring-1 ring-white/25 ring-inset`;

export const brandBadge =
    "inline-flex items-center gap-2 rounded-full border border-brand/30 bg-brand/10 px-4 py-1.5 text-sm text-brand-muted";

export const brandBadgeSm =
    "inline-flex items-center gap-2 rounded-full border border-brand/20 bg-brand/10 px-4 py-1 text-sm text-brand-muted";

export const brandGradientText =
    "bg-gradient-to-r from-brand-muted to-brand bg-clip-text text-transparent";

export const btnBrand =
    `${brandGradientFill} hover-lift font-semibold text-brand-foreground hover:opacity-90 border-0 shadow-md shadow-[hsl(var(--brand)/0.2)] transition-colors hover:-translate-y-0.5 hover:shadow-lg active:translate-y-0 active:scale-[0.98]`;

export const btnBrandOutline =
    "hover-lift border-border bg-card/80 text-foreground/90 hover:border-brand/50 hover:text-brand-muted hover:bg-card transition-colors hover:-translate-y-0.5 active:translate-y-0 active:scale-[0.98]";

export const authCard = "w-full max-w-md mkt-elevated rounded-2xl p-8";

export const authPanelSide =
    "hidden lg:sticky lg:top-0 lg:flex lg:h-[100dvh] lg:max-h-[100dvh] lg:w-2/5 flex-col p-12 relative overflow-hidden bg-secondary/80 mkt-dots";

export const linkBrand = "text-brand-muted hover:text-brand font-semibold transition-colors";

/** Enlace de nav con subrayado animado (requiere contenedor position:relative, ya lo es <a>) */
export const navLink =
    "relative text-sm text-muted-foreground transition-colors hover:text-foreground " +
    "after:absolute after:left-0 after:-bottom-1 after:h-px after:w-0 after:bg-brand " +
    "after:transition-[width] after:duration-300 hover:after:w-full " +
    "rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background";

export const sectionAlt = "py-24 px-6 mkt-section-alt";

export const sectionDefault = "py-24 px-6 mkt-section-default";

/**
 * Cards con elevación real (no solo border sobre blanco).
 * El "extra" de sombra al hover vive en un ::after con transición de opacidad
 * (barata, GPU) en vez de animar box-shadow directamente (fuerza repintado y
 * se siente pesado/tosco en cuadro a cuadro).
 */
export const featureCard =
    "relative mkt-elevated rounded-xl p-6 hover-lift hover:will-change-transform hover:-translate-y-1.5 hover:scale-[1.015] " +
    "after:content-[''] after:absolute after:inset-0 after:rounded-xl after:pointer-events-none " +
    "after:shadow-lg after:opacity-0 after:transition-opacity after:duration-500 " +
    "after:ease-[cubic-bezier(0.22,1,0.36,1)] hover:after:opacity-100";

export const featureIconBox =
    "mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-brand-subtle";

export const infoBadge =
    "inline-block rounded-full border border-brand/25 bg-brand/10 px-4 py-1 text-sm text-brand-muted mb-4";

export const pricingTabWrap =
    "inline-flex gap-1 rounded-xl border border-border/80 bg-card/90 p-1.5 shadow-sm";

const pricingTabFocus =
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background";

export const pricingTabActive =
    `rounded-lg bg-card px-5 py-2.5 text-sm font-semibold text-foreground shadow-sm ring-1 ring-border/80 ${pricingTabFocus}`;

export const pricingTabInactive =
    `cursor-pointer rounded-lg px-5 py-2.5 text-sm text-muted-foreground transition-all duration-200 hover:text-foreground hover:bg-muted/50 ${pricingTabFocus}`;

export const planCard =
    "relative flex flex-col mkt-elevated rounded-2xl p-8 hover-lift hover:will-change-transform hover:-translate-y-1.5 hover:scale-[1.015] " +
    "after:content-[''] after:absolute after:inset-0 after:rounded-2xl after:pointer-events-none " +
    "after:shadow-xl after:opacity-0 after:transition-opacity after:duration-500 " +
    "after:ease-[cubic-bezier(0.22,1,0.36,1)] hover:after:opacity-100";

export const planCardHighlighted =
    "border-brand/50 shadow-xl shadow-[hsl(var(--brand)/0.12)]";

export const planChip =
    "mb-1 mr-1 inline-flex rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground";

export const footerLink =
    "text-muted-foreground hover:text-foreground transition-colors rounded-sm " +
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background";

/** Filas internas del mock del hero */
export const mockTicketRow = "rounded-lg bg-secondary p-3 mb-2";

/** Avatares apilados — anillo del color de página, no de card */
export const mockAvatarRing = "border-2 border-background ring-1 ring-border/40";

/** Card sobre imagen hero (forgot/reset/register) */
export const authHeroCard =
    "w-full shadow-2xl border-border/80 bg-card/90 dark:bg-card/85 backdrop-blur-md";

/** Card centrada sin hero (verify, force-change, invitation) */
export const authSimpleCard = "w-full mkt-elevated border-border shadow-lg";

/** Mensajes de formulario auth */
export const authMessageSuccess = "text-sm text-emerald-700 dark:text-emerald-400";
export const authMessageError = "text-sm text-destructive";

/** Enlace secundario tipo “Volver” */
export const authLinkGhost =
    "inline-flex w-full items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring hover:bg-accent hover:text-accent-foreground min-h-[44px] md:min-h-0";

/** Indicador fuerza de contraseña (score 1–3) */
export function passwordStrengthClass(score) {
    if (score === 3) return "text-emerald-700 dark:text-emerald-400";
    if (score === 2) return "text-amber-700 dark:text-amber-400";
    return "text-destructive";
}
