import { useEffect, useMemo, useState } from "react";
import { Head, usePage } from "@inertiajs/react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import axios from "@/lib/axios";
import { getApiErrorMessage } from "@/lib/apiErrors";
import { registerFormSchema } from "@/lib/passwordSchema";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { RegisterBrandingPanel } from "@/components/auth/AuthBrandingPresets";
import { AuthFormAlert } from "@/components/auth/AuthFormAlert";
import { AuthFormField } from "@/components/auth/AuthFormField";
import { AuthGoogleSection } from "@/components/auth/AuthGoogleSection";
import { AuthMicrosoftSection } from "@/components/auth/AuthMicrosoftSection";
import { AuthPageHeader } from "@/components/auth/AuthPageHeader";
import { AuthSplitLayout } from "@/components/auth/AuthSplitLayout";
import { focusFirstFormError } from "@/lib/focusFirstFormError";
import { PasswordField, PasswordMatchHint } from "@/components/auth/PasswordField";
import { PasswordRequirements } from "@/components/auth/PasswordRequirements";
import {
    RegisterPlanSummary,
    RegisterTrustLine,
} from "@/components/auth/RegisterPlanSummary";
import { AuthFormSection } from "@/components/auth/AuthFormSection";
import { btnBrand } from "@/lib/marketingTheme";
import { Loader2 } from "lucide-react";

const defaultValues = {
    first_name: "",
    paternal_last_name: "",
    maternal_last_name: "",
    email: "",
    phone: "",
    password: "",
    password_confirmation: "",
};

const REGISTER_FIELD_ORDER = [
    "first_name",
    "paternal_last_name",
    "maternal_last_name",
    "email",
    "phone",
    "password",
    "password_confirmation",
];

export default function Register() {
    const { plans = [], authProviders = {} } = usePage().props;

    const planSlug = useMemo(() => {
        if (typeof window === "undefined") return null;
        return new URLSearchParams(window.location.search).get("plan");
    }, []);

    const selectedPlan = useMemo(() => {
        if (!planSlug || !Array.isArray(plans) || plans.length === 0) return null;
        return plans.find((p) => String(p.slug).toLowerCase() === planSlug.toLowerCase()) ?? null;
    }, [planSlug, plans]);

    const [success, setSuccess] = useState("");
    const [serverError, setServerError] = useState("");

    const {
        register,
        handleSubmit,
        watch,
        reset,
        formState: { errors, isSubmitting },
    } = useForm({
        resolver: zodResolver(registerFormSchema),
        defaultValues,
        mode: "onBlur",
    });

    const password = watch("password");
    const passwordConfirmation = watch("password_confirmation");

    useEffect(() => {
        axios.get("/sanctum/csrf-cookie", { withCredentials: true }).catch(() => {});
    }, []);

    const onSubmit = async (values) => {
        setServerError("");
        setSuccess("");

        try {
            const { data } = await axios.post("/api/register", {
                first_name: values.first_name.trim(),
                paternal_last_name: values.paternal_last_name.trim(),
                maternal_last_name: values.maternal_last_name?.trim() || null,
                email: values.email.trim(),
                phone: values.phone?.trim() || null,
                password: values.password,
                password_confirmation: values.password_confirmation,
                plan: planSlug || undefined,
            });

            if (data?.redirect_url) {
                window.location.href = data.redirect_url;
                return;
            }

            setSuccess(data?.message || "Registro creado correctamente.");
            reset(defaultValues);
        } catch (err) {
            const status = err?.response?.status;
            if (status === 429) {
                setServerError("Demasiados intentos. Intenta más tarde.");
            } else {
                setServerError(
                    getApiErrorMessage(err, "No se pudo registrar. Intenta más tarde.")
                );
            }
        }
    };

    const loading = isSubmitting;
    const formError = serverError || errors.root?.message;

    return (
        <>
            <Head title="Registro de operador — Tikara" />
            <AuthSplitLayout
                tenant={{}}
                formClassName="max-w-lg"
                topLink={{
                    prompt: "¿Ya tienes cuenta?",
                    href: "/login",
                    label: "Inicia sesión",
                }}
                brandingPanel={<RegisterBrandingPanel />}
            >
                <AuthPageHeader
                    title="Registro de operador"
                    description="Completa tus datos para crear la cuenta de administrador."
                >
                    <RegisterTrustLine className="mt-3" />
                </AuthPageHeader>

                <div className="space-y-6">
                    <RegisterPlanSummary plan={selectedPlan} planSlug={planSlug} />

                    <AuthGoogleSection
                        enabled={Boolean(authProviders?.google)}
                        href="/auth/google/redirect?intent=login"
                        mode="register"
                        disabled={loading}
                        showSeparator={false}
                    />

                    <div className="mt-3">
                        <AuthMicrosoftSection
                            enabled={Boolean(authProviders?.microsoft)}
                            href="/auth/microsoft/redirect?intent=login"
                            mode="register"
                            disabled={loading}
                        />
                    </div>

                    <form
                        onSubmit={handleSubmit(onSubmit, (fieldErrors) =>
                            focusFirstFormError(fieldErrors, REGISTER_FIELD_ORDER)
                        )}
                        className="space-y-6"
                        noValidate
                    >
                    <AuthFormSection title="Identidad">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <AuthFormField
                                id="reg-first-name"
                                label="Nombre(s)"
                                error={errors.first_name?.message}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="reg-first-name"
                                    placeholder="Ej. Juan Carlos"
                                    autoComplete="given-name"
                                    disabled={loading}
                                    className="h-11"
                                    aria-invalid={Boolean(errors.first_name)}
                                    {...register("first_name")}
                                />
                            </AuthFormField>

                            <AuthFormField
                                id="reg-paternal"
                                label="Apellido paterno"
                                error={errors.paternal_last_name?.message}
                            >
                                <Input
                                    id="reg-paternal"
                                    placeholder="Ej. Pérez"
                                    autoComplete="family-name"
                                    disabled={loading}
                                    className="h-11"
                                    aria-invalid={Boolean(errors.paternal_last_name)}
                                    {...register("paternal_last_name")}
                                />
                            </AuthFormField>

                            <AuthFormField
                                id="reg-maternal"
                                label="Apellido materno (opcional)"
                                error={errors.maternal_last_name?.message}
                            >
                                <Input
                                    id="reg-maternal"
                                    placeholder="Ej. García"
                                    autoComplete="additional-name"
                                    disabled={loading}
                                    className="h-11"
                                    {...register("maternal_last_name")}
                                />
                            </AuthFormField>
                        </div>
                    </AuthFormSection>

                    <AuthFormSection title="Contacto">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <AuthFormField
                                id="reg-email"
                                label="Correo electrónico"
                                error={errors.email?.message}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="reg-email"
                                    type="email"
                                    inputMode="email"
                                    placeholder="tu@empresa.com"
                                    autoComplete="email"
                                    disabled={loading}
                                    className="h-11"
                                    aria-invalid={Boolean(errors.email)}
                                    {...register("email")}
                                />
                            </AuthFormField>

                            <AuthFormField
                                id="reg-phone"
                                label="Teléfono (opcional)"
                                error={errors.phone?.message}
                                hint="10 dígitos, sin espacios."
                            >
                                <Input
                                    id="reg-phone"
                                    type="tel"
                                    inputMode="numeric"
                                    maxLength={10}
                                    placeholder="5512345678"
                                    autoComplete="tel"
                                    disabled={loading}
                                    className="h-11"
                                    aria-invalid={Boolean(errors.phone)}
                                    {...register("phone")}
                                />
                            </AuthFormField>
                        </div>
                    </AuthFormSection>

                    <AuthFormSection title="Acceso">
                        <PasswordField
                            id="reg-password"
                            label="Contraseña"
                            disabled={loading}
                            error={errors.password?.message}
                            {...register("password")}
                        />
                        <PasswordRequirements password={password} />

                        <div className="space-y-2">
                            <PasswordField
                                id="reg-password-confirmation"
                                label="Confirmar contraseña"
                                disabled={loading}
                                error={errors.password_confirmation?.message}
                                {...register("password_confirmation")}
                            />
                            <PasswordMatchHint
                                password={password}
                                confirmation={passwordConfirmation}
                            />
                        </div>
                    </AuthFormSection>

                    <p className="text-xs text-muted-foreground">
                        Recibirás un enlace de verificación en tu correo para activar la cuenta y
                        continuar con la configuración de tu empresa.
                    </p>

                    <AuthFormAlert error={formError} success={success} />

                    <Button
                        type="submit"
                        disabled={loading}
                        className={`h-11 w-full gap-2 rounded-lg ${btnBrand}`}
                    >
                        {loading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
                        <span>{loading ? "Registrando..." : "Crear cuenta"}</span>
                    </Button>
                    </form>
                </div>
            </AuthSplitLayout>
        </>
    );
}
