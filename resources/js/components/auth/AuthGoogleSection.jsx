import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { btnBrandOutline } from "@/lib/marketingTheme";

export function GoogleIcon() {
    return (
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden className="shrink-0">
            <path
                fill="#4285F4"
                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
            />
            <path
                fill="#34A853"
                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
            />
            <path
                fill="#FBBC05"
                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
            />
            <path
                fill="#EA4335"
                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
            />
        </svg>
    );
}

/**
 * Botón Google + separador (login/registro).
 * `mode=register` muestra placeholder hasta OAuth de alta.
 */
export function AuthGoogleSection({
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
                            <GoogleIcon />
                            <span className="ml-2">Continuar con Google</span>
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
                            <GoogleIcon />
                            <span className="ml-2">Continuar con Google</span>
                        </Button>
                        <p className="mt-1 text-center text-xs text-muted-foreground">
                            {mode === "register"
                                ? "Registro con Google próximamente"
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
