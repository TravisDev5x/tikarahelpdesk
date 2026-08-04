import { Link } from "@inertiajs/react";
import { AuthBrandHomeLink } from "@/components/auth/AuthBrandHomeLink";
import { authPanelSide, brandBadgeSm, brandPanelGlow } from "@/lib/marketingTheme";
import {
    isClientPortalTenant,
    resolveTenantBrandCssVars,
} from "@/lib/tenantBranding";
import { cn } from "@/lib/utils";

/**
 * Panel lateral de auth (login, registro). Copy configurable; layout alineado con landing.
 */
export function AuthBrandingPanel({
    tenant = {},
    badgeLabel,
    title,
    description,
    bullets = [],
    className,
}) {
    const isPortal = isClientPortalTenant(tenant);

    return (
        <aside
            className={cn(authPanelSide, "auth-fade-in", className)}
            style={resolveTenantBrandCssVars(tenant)}
        >
            <div className={brandPanelGlow} aria-hidden />

            <AuthBrandHomeLink tenant={tenant} showName className="relative z-10 shrink-0" />

            <div className="relative z-10 flex flex-1 flex-col justify-center py-8 lg:py-10">
                <div className={`inline-flex items-center gap-2 ${brandBadgeSm} mb-6`}>
                    <span className="h-2 w-2 shrink-0 rounded-full bg-brand animate-pulse" />
                    {badgeLabel ??
                        (isPortal ? "Portal de tu organización" : "Acceso seguro")}
                </div>

                <div className="text-4xl font-black leading-tight text-foreground">
                    {title}
                </div>

                {description ? (
                    <p className="mt-4 max-w-sm text-base leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                ) : null}

                {bullets.length > 0 ? (
                    <ul className="mt-8 space-y-3">
                        {bullets.map((item) => (
                            <li
                                key={item.text}
                                className="flex items-center gap-3 text-sm text-muted-foreground"
                            >
                                <span
                                    className={cn(
                                        "h-2 w-2 shrink-0 rounded-full",
                                        item.dotClassName ?? "bg-brand"
                                    )}
                                />
                                {item.text}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>

            <div className="relative z-10 flex shrink-0 gap-4 pb-1 text-xs text-muted-foreground">
                <Link href="/privacidad" className="transition-colors hover:text-foreground">
                    Aviso de privacidad
                </Link>
                <Link href="/terminos" className="transition-colors hover:text-foreground">
                    Términos del servicio
                </Link>
            </div>
        </aside>
    );
}
