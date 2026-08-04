import { useEffect } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import axios from "@/lib/axios";
import { AcceptInvitationBrandingPanel } from "@/components/auth/AuthBrandingPresets";
import { AuthBackToLoginLink } from "@/components/auth/AuthFormSection";
import { AuthFormAlert } from "@/components/auth/AuthFormAlert";
import { AuthFormField } from "@/components/auth/AuthFormField";
import { AuthFormSection } from "@/components/auth/AuthFormSection";
import { AuthPageHeader } from "@/components/auth/AuthPageHeader";
import { AuthSplitLayout } from "@/components/auth/AuthSplitLayout";
import { PasswordField, PasswordMatchHint } from "@/components/auth/PasswordField";
import { PasswordRequirements } from "@/components/auth/PasswordRequirements";
import { btnBrand, btnBrandOutline, linkBrand } from "@/lib/marketingTheme";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Loader2 } from "lucide-react";

function formatExpiry(iso) {
    if (!iso) return "";
    try {
        return new Date(iso).toLocaleString("es-MX", {
            dateStyle: "medium",
            timeStyle: "short",
        });
    } catch {
        return iso;
    }
}

function GoogleIcon() {
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

function MicrosoftIcon() {
    return (
        <svg viewBox="0 0 21 21" width="18" height="18" aria-hidden className="shrink-0">
            <rect x="1" y="1" width="9" height="9" fill="#F25022" />
            <rect x="11" y="1" width="9" height="9" fill="#7FBA00" />
            <rect x="1" y="11" width="9" height="9" fill="#00A4EF" />
            <rect x="11" y="11" width="9" height="9" fill="#FFB900" />
        </svg>
    );
}

export default function AcceptInvitation({
    token,
    email,
    role_name,
    client_name,
    expires_at,
    assigns_role_on_accept,
    google_enabled,
    google_url,
    microsoft_enabled,
    microsoft_url,
    error: pageError,
}) {
    const isInvalid = Boolean(pageError) || !token;

    const { data, setData, post, processing, errors, reset } = useForm({
        token: token || "",
        first_name: "",
        paternal_last_name: "",
        maternal_last_name: "",
        password: "",
        password_confirmation: "",
    });

    useEffect(() => {
        axios.get("/sanctum/csrf-cookie", { withCredentials: true }).catch(() => {});
    }, []);

    const handleSubmit = (e) => {
        e.preventDefault();
        post("/register/accept", {
            onSuccess: () => reset("password", "password_confirmation"),
        });
    };

    const formError = errors.token || errors.root;

    if (isInvalid) {
        return (
            <>
                <Head title="Invitación no válida" />
                <AuthSplitLayout
                    topLink={{
                        prompt: "¿Ya tienes cuenta?",
                        href: "/login",
                        label: "Inicia sesión",
                    }}
                    brandingPanel={<AcceptInvitationBrandingPanel />}
                >
                    <AuthPageHeader
                        title="Invitación no válida"
                        description={pageError || "Esta invitación no es válida o ha expirado."}
                    />

                    <div className="space-y-6">
                        <div className="space-y-4 rounded-xl border border-border/60 bg-muted/30 p-5">
                            <p className="text-sm text-muted-foreground">
                                Solicita una nueva invitación al administrador de tu organización.
                            </p>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Button asChild variant="outline" className={`h-11 flex-1 rounded-lg ${btnBrandOutline}`}>
                                    <a href="mailto:soporte@helpdesk.local">Contactar soporte</a>
                                </Button>
                                <Button asChild className={`h-11 flex-1 rounded-lg ${btnBrand}`}>
                                    <Link href="/login">Ir al inicio de sesión</Link>
                                </Button>
                            </div>
                            <Link href="/" className={`${linkBrand} inline-block text-sm`}>
                                Volver a la landing
                            </Link>
                        </div>
                        <AuthBackToLoginLink />
                    </div>
                </AuthSplitLayout>
            </>
        );
    }

    return (
        <>
            <Head title="Configura tu cuenta" />
            <AuthSplitLayout
                formClassName="max-w-lg"
                topLink={{
                    prompt: "¿Ya tienes cuenta?",
                    href: "/login",
                    label: "Inicia sesión",
                }}
                brandingPanel={<AcceptInvitationBrandingPanel />}
            >
                <AuthPageHeader
                    title="Configura tu cuenta"
                    description="Completa tus datos para entrar. Un administrador te asignará el rol y permisos según tu puesto."
                />

                <div className="space-y-6">
                    <div className="rounded-lg border border-border/60 bg-muted/40 p-3 text-sm space-y-1">
                        <p>
                            Fuiste invitado
                            {client_name ? (
                                <>
                                    {" "}
                                    a <strong>{client_name}</strong>
                                </>
                            ) : null}
                            {role_name && assigns_role_on_accept ? (
                                <>
                                    {" "}
                                    con rol sugerido <strong>{role_name}</strong>
                                </>
                            ) : null}
                            .
                        </p>
                        {!assigns_role_on_accept && (
                            <p className="text-muted-foreground text-xs">
                                Tras activar tu cuenta verás un aviso hasta que un administrador confirme
                                tu rol.
                            </p>
                        )}
                        {expires_at ? (
                            <Badge variant="outline" className="text-xs font-normal">
                                Invitación válida hasta {formatExpiry(expires_at)}
                            </Badge>
                        ) : null}
                    </div>

                    {(google_enabled && google_url) || (microsoft_enabled && microsoft_url) ? (
                        <>
                            <div className="space-y-2">
                                {google_enabled && google_url ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className={`h-11 w-full rounded-lg ${btnBrandOutline}`}
                                        asChild
                                    >
                                        <a href={google_url}>
                                            <GoogleIcon />
                                            <span className="ml-2">Continuar con Google</span>
                                        </a>
                                    </Button>
                                ) : null}
                                {microsoft_enabled && microsoft_url ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className={`h-11 w-full rounded-lg ${btnBrandOutline}`}
                                        asChild
                                    >
                                        <a href={microsoft_url}>
                                            <MicrosoftIcon />
                                            <span className="ml-2">Continuar con Microsoft</span>
                                        </a>
                                    </Button>
                                ) : null}
                            </div>
                            <p className="text-center text-xs text-muted-foreground">
                                O completa el formulario con contraseña
                            </p>
                        </>
                    ) : null}

                    <form onSubmit={handleSubmit} className="space-y-6" noValidate>
                        <input type="hidden" name="token" value={data.token} readOnly />

                        <AuthFormSection title="Identidad">
                            <AuthFormField
                                id="inv-email"
                                label="Correo"
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="inv-email"
                                    type="email"
                                    value={email}
                                    disabled
                                    readOnly
                                    className="h-11 bg-muted"
                                />
                            </AuthFormField>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <AuthFormField
                                    id="inv-first-name"
                                    label="Nombre(s)"
                                    error={errors.first_name}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="inv-first-name"
                                        value={data.first_name}
                                        onChange={(e) => setData("first_name", e.target.value)}
                                        required
                                        autoComplete="given-name"
                                        disabled={processing}
                                        className="h-11"
                                        aria-invalid={Boolean(errors.first_name)}
                                    />
                                </AuthFormField>

                                <AuthFormField
                                    id="inv-paternal"
                                    label="Apellido paterno"
                                    error={errors.paternal_last_name}
                                >
                                    <Input
                                        id="inv-paternal"
                                        value={data.paternal_last_name}
                                        onChange={(e) => setData("paternal_last_name", e.target.value)}
                                        required
                                        autoComplete="family-name"
                                        disabled={processing}
                                        className="h-11"
                                        aria-invalid={Boolean(errors.paternal_last_name)}
                                    />
                                </AuthFormField>

                                <AuthFormField
                                    id="inv-maternal"
                                    label="Apellido materno (opcional)"
                                    error={errors.maternal_last_name}
                                >
                                    <Input
                                        id="inv-maternal"
                                        value={data.maternal_last_name}
                                        onChange={(e) => setData("maternal_last_name", e.target.value)}
                                        autoComplete="additional-name"
                                        disabled={processing}
                                        className="h-11"
                                    />
                                </AuthFormField>
                            </div>
                        </AuthFormSection>

                        <AuthFormSection title="Acceso">
                            <PasswordField
                                id="inv-password"
                                label="Contraseña"
                                value={data.password}
                                onChange={(e) => setData("password", e.target.value)}
                                required
                                autoComplete="new-password"
                                disabled={processing}
                                error={errors.password}
                            />
                            <PasswordRequirements password={data.password} />

                            <div className="space-y-2">
                                <PasswordField
                                    id="inv-password-confirmation"
                                    label="Confirmar contraseña"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData("password_confirmation", e.target.value)}
                                    required
                                    autoComplete="new-password"
                                    disabled={processing}
                                />
                                <PasswordMatchHint
                                    password={data.password}
                                    confirmation={data.password_confirmation}
                                />
                            </div>
                        </AuthFormSection>

                        {formError ? (
                            <AuthFormAlert error={formError} />
                        ) : null}

                        <Button
                            type="submit"
                            className={`h-11 w-full gap-2 rounded-lg ${btnBrand}`}
                            disabled={processing}
                        >
                            {processing ? (
                                <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                            ) : null}
                            <span>{processing ? "Creando cuenta..." : "Crear mi cuenta"}</span>
                        </Button>

                        <AuthBackToLoginLink />
                    </form>
                </div>
            </AuthSplitLayout>
        </>
    );
}
