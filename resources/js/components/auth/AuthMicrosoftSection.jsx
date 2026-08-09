import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { btnBrandOutline } from "@/lib/marketingTheme";

export function MicrosoftIcon() {
    return (
        <svg viewBox="0 0 21 21" width="18" height="18" aria-hidden className="shrink-0">
            <rect x="1" y="1" width="9" height="9" fill="#F25022" />
            <rect x="11" y="1" width="9" height="9" fill="#7FBA00" />
            <rect x="1" y="11" width="9" height="9" fill="#00A4EF" />
            <rect x="11" y="11" width="9" height="9" fill="#FFB900" />
        </svg>
    );
}

/**
 * Botón Microsoft (Outlook / Microsoft 365) + separador opcional (login/registro).
 * `mode=register` muestra placeholder hasta que exista alta vía Microsoft.
 */
export function AuthMicrosoftSection({
    enabled = false,
    href,
    mode = "login",
    disabled = false,
    showSeparator = true,
}) {
    const showActive = enabled && href && mode === "login";

    return (
        <>
            <div>
                {showActive ? (
                    <Button
                        type="button"
                        variant="outline"
                        className={`h-11 w-full ${btnBrandOutline}`}
                        disabled={disabled}
                        asChild
                    >
                        <a href={href}>
                            <MicrosoftIcon />
                            <span className="ml-2">Continuar con Microsoft</span>
                        </a>
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            className={`h-11 w-full ${btnBrandOutline}`}
                            disabled
                        >
                            <MicrosoftIcon />
                            <span className="ml-2">Continuar con Microsoft</span>
                        </Button>
                        <p className="mt-1 text-center text-xs text-muted-foreground">
                            {mode === "register"
                                ? "Registro con Microsoft próximamente"
                                : "Próximamente disponible"}
                        </p>
                    </>
                )}
            </div>

            {showSeparator ? (
                <div className="relative my-6">
                    <Separator className="bg-border" />
                    <span className="absolute left-1/2 top-1/2 -translate-x-1/2 whitespace-nowrap bg-card px-3 text-xs text-muted-foreground">
                        O CON CORREO Y CONTRASEÑA
                    </span>
                </div>
            ) : null}
        </>
    );
}
