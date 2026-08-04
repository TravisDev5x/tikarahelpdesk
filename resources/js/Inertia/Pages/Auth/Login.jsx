import { useEffect, useMemo, useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import axios from "@/lib/axios";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Loader2, Eye, EyeOff } from "lucide-react";
import { AuthBrandingPanel } from "@/components/auth/AuthBrandingPanel";
import { AuthGoogleSection } from "@/components/auth/AuthGoogleSection";
import { AuthMicrosoftSection } from "@/components/auth/AuthMicrosoftSection";
import { AuthSplitLayout } from "@/components/auth/AuthSplitLayout";
import {
    btnBrand,
    linkBrand,
} from "@/lib/marketingTheme";
import { getTenantBrandName, isClientPortalTenant } from "@/lib/tenantBranding";
import { statusDotInfo } from "@/lib/badgeStyles";

export default function Login() {
    const { tenant = {}, authProviders = {}, flash = {} } = usePage().props;
    const pageTitle = useMemo(() => {
        if (tenant?.mode === "client_portal" && tenant?.name) {
            return `Iniciar sesión — ${tenant.name}`;
        }
        return "Iniciar sesión — Tikara";
    }, [tenant]);

    const loginWelcome = useMemo(() => {
        const brandName = getTenantBrandName(tenant, "Tikara");
        const isPortal = isClientPortalTenant(tenant);
        return (
            tenant?.portal_welcome_message ||
            (isPortal
                ? `Accede al portal de ${brandName}.`
                : "Inicia sesión con el correo de tu cuenta para entrar al panel de tu negocio.")
        );
    }, [tenant]);

    const [form, setForm] = useState({ email: "", password: "" });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [remember, setRemember] = useState(() => {
        if (typeof localStorage === "undefined") return false;
        const saved = localStorage.getItem("login.remember");
        const savedEmail =
            localStorage.getItem("login.email") || localStorage.getItem("login.identifier") || "";
        if (saved === "1" && savedEmail) {
            setTimeout(() => setForm((f) => ({ ...f, email: savedEmail })), 0);
            return true;
        }
        return false;
    });

    useEffect(() => {
        axios.get("/sanctum/csrf-cookie", { withCredentials: true }).catch(() => {});
    }, []);

    useEffect(() => {
        if (typeof flash?.error === "string" && flash.error) {
            setError(flash.error);
        }
    }, [flash?.error]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError("");

        const email = form.email.trim();
        if (!email) {
            setError("El correo electrónico es obligatorio.");
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            setError("Ingresa un correo electrónico válido.");
            return;
        }
        if (!form.password) {
            setError("La contraseña es obligatoria.");
            return;
        }

        setLoading(true);

        try {
            const { data } = await axios.post("/api/login", {
                identifier: email,
                password: form.password,
            });
            if (remember) {
                localStorage.setItem("login.remember", "1");
                localStorage.setItem("login.email", email);
                localStorage.removeItem("login.identifier");
            } else {
                localStorage.removeItem("login.remember");
                localStorage.removeItem("login.email");
                localStorage.removeItem("login.identifier");
            }
            const userTheme = data?.user?.theme;
            if (userTheme && ["light", "dark", "system"].includes(userTheme)) {
                localStorage.setItem("tikara_theme", userTheme);
                const resolved =
                    userTheme === "system"
                        ? window.matchMedia("(prefers-color-scheme: dark)").matches
                            ? "dark"
                            : "light"
                        : userTheme;
                const root = document.documentElement;
                root.classList.remove("light", "dark");
                root.classList.add(resolved);
                root.style.colorScheme = resolved;
            }
            const defaultRedirect = tenant?.mode === "client_portal" ? "/" : "/home";
            window.location.href = data?.onboarding_redirect || defaultRedirect;
        } catch (err) {
            const status = err?.response?.status;
            const serverMessage = err?.response?.data?.errors?.root;
            if ((status === 422 || status === 403) && typeof serverMessage === "string") {
                setError(serverMessage);
            } else if (status === 429) {
                setError("Demasiados intentos. Intenta de nuevo en unos segundos.");
            } else if (status >= 500) {
                setError("Error del servidor. Intenta nuevamente.");
            } else {
                setError("No se pudo iniciar sesión.");
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Head title={pageTitle} />
            <AuthSplitLayout
                tenant={tenant}
                topLink={
                    tenant?.mode !== "client_portal"
                        ? {
                              prompt: "¿Aún no tienes cuenta?",
                              href: "/register",
                              label: "Crear cuenta gratis",
                          }
                        : null
                }
                brandingPanel={
                    <AuthBrandingPanel
                        tenant={tenant}
                        badgeLabel={
                            isClientPortalTenant(tenant)
                                ? "Portal de tu organización"
                                : "Acceso seguro"
                        }
                        title={
                            <>
                                Bienvenido
                                <br />
                                de nuevo
                            </>
                        }
                        description={loginWelcome}
                        bullets={[
                            { text: "Misma cuenta para todas las pantallas." },
                            { text: "Roles y permisos según tu equipo.", dotClassName: statusDotInfo },
                            { text: "Tus datos seguros y aislados.", dotClassName: "bg-muted-foreground" },
                        ]}
                    />
                }
            >
                <AuthGoogleSection
                    enabled={Boolean(authProviders?.google)}
                    href="/auth/google/redirect?intent=login"
                    mode="login"
                    disabled={loading}
                    showSeparator={false}
                />

                <div className="mt-3">
                    <AuthMicrosoftSection
                        enabled={Boolean(authProviders?.microsoft)}
                        href="/auth/microsoft/redirect?intent=login"
                        mode="login"
                        disabled={loading}
                    />
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label htmlFor="login-email" className="text-sm mb-1.5 block">
                                    Correo electrónico
                                </Label>
                                <Input
                                    id="login-email"
                                    type="email"
                                    inputMode="email"
                                    autoComplete="email"
                                    placeholder="tu@empresa.com"
                                    value={form.email}
                                    onChange={(e) =>
                                        setForm((prev) => ({ ...prev, email: e.target.value }))
                                    }
                                    autoFocus
                                    disabled={loading}
                                    aria-invalid={Boolean(error)}
                                    className="h-11"
                                />
                            </div>

                            <div>
                                <Label htmlFor="login-password" className="text-sm mb-1.5 block">
                                    Contraseña
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="login-password"
                                        type={showPassword ? "text" : "password"}
                                        value={form.password}
                                        onChange={(e) =>
                                            setForm((prev) => ({ ...prev, password: e.target.value }))
                                        }
                                        autoComplete="current-password"
                                        disabled={loading}
                                        aria-invalid={Boolean(error)}
                                        className="h-11 pr-12"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => setShowPassword((v) => !v)}
                                        className="absolute right-1 top-1/2 -translate-y-1/2 h-9 w-9 text-muted-foreground hover:text-foreground"
                                        disabled={loading}
                                        aria-label={
                                            showPassword ? "Ocultar contraseña" : "Mostrar contraseña"
                                        }
                                    >
                                        {showPassword ? (
                                            <EyeOff className="h-4 w-4" />
                                        ) : (
                                            <Eye className="h-4 w-4" />
                                        )}
                                    </Button>
                                </div>
                            </div>

                            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-3 mb-6">
                                <label className="flex cursor-pointer items-center" htmlFor="remember">
                                    <Checkbox
                                        id="remember"
                                        checked={remember}
                                        onCheckedChange={(v) => setRemember(Boolean(v))}
                                        disabled={loading}
                                    />
                                    <span className="text-muted-foreground text-sm ml-2 select-none">
                                        Mantener sesión en este dispositivo
                                    </span>
                                </label>
                                <Link href="/forgot-password" className={`${linkBrand} text-sm shrink-0`}>
                                    ¿Olvidaste tu contraseña?
                                </Link>
                            </div>

                            {error ? (
                                <p className="text-destructive text-xs" role="alert" aria-live="polite">
                                    {error}
                                </p>
                            ) : null}

                            <Button
                                type="submit"
                                disabled={loading}
                                className={`w-full h-11 gap-2 rounded-lg ${btnBrand}`}
                            >
                                {loading ? (
                                    <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                                ) : null}
                                <span>{loading ? "Entrando..." : "Entrar al panel"}</span>
                            </Button>
                        </form>
            </AuthSplitLayout>
        </>
    );
}
