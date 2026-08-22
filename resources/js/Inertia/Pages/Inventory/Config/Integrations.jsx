import { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { badgeStatus } from "@/lib/badgeStyles";
import { INTEGRATION_PROVIDERS } from "@/lib/integrationProviders";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { CheckCircle2, Loader2, Unplug, XCircle } from "lucide-react";

const STATUS_META = {
    connected: { label: "Conectado", cls: badgeStatus.success, icon: CheckCircle2 },
    error: { label: "Error", cls: badgeStatus.danger, icon: XCircle },
    not_configured: { label: "No configurado", cls: badgeStatus.neutral, icon: null },
};

function relativeTime(iso) {
    if (!iso) return null;
    const diffMs = Date.now() - new Date(iso).getTime();
    const minutes = Math.round(diffMs / 60000);
    if (minutes < 1) return "hace un momento";
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `hace ${hours} h`;
    return new Date(iso).toLocaleString("es-ES");
}

/**
 * Auditoría de bugs críticos (2026-08-22): antes el form siempre arrancaba
 * en blanco (el backend nunca devolvía los valores no-secretos), así que
 * cualquier re-guardado (ej. solo rotar el secreto) mandaba use_ssl/port/
 * base_dn vacíos y los borraba en silencio. Ahora se prellena con lo que
 * present() sí trae -- los campos secret:true siguen SIEMPRE en blanco,
 * nunca se prellenan con el valor real.
 */
function buildForm(provider, initial) {
    return Object.fromEntries(
        provider.fields.map((f) => [
            f.key,
            f.secret ? "" : (initial[f.key] ?? (f.type === "checkbox" ? false : "")),
        ])
    );
}

function IntegrationCard({ provider, initial, onChange }) {
    const Icon = provider.icon;
    const [form, setForm] = useState(() => buildForm(provider, initial));
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [disconnectOpen, setDisconnectOpen] = useState(false);
    const [errors, setErrors] = useState({});

    const meta = STATUS_META[initial.status] ?? STATUS_META.not_configured;
    const StatusIcon = meta.icon;
    const isConfigured = initial.status !== "not_configured";

    // Resincroniza el form cuando initial cambia de verdad (tras guardar/
    // probar/desconectar) -- no en cada render, initial solo cambia de
    // referencia cuando onChange() lo actualiza de verdad.
    useEffect(() => {
        setForm(buildForm(provider, initial));
    }, [initial]);

    const setField = (key, value) => setForm((f) => ({ ...f, [key]: value }));

    async function handleSave(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const { data } = await axios.put(`/api/inv-integrations/${provider.key}`, form);
            onChange(data);
            if (data.status === "connected") {
                notify.success(data.status_message || "Conexión guardada");
            } else {
                notify.error(data.status_message || "Se guardó, pero la prueba de conexión falló");
            }
            setForm(buildForm(provider, data));
        } catch (err) {
            if (!handleAuthError(err)) {
                setErrors(err?.response?.data?.errors ?? {});
                notify.error(getApiErrorMessage(err, "No se pudo guardar la integración"));
            }
        } finally {
            setSaving(false);
        }
    }

    async function handleTest() {
        setTesting(true);
        try {
            const { data } = await axios.post(`/api/inv-integrations/${provider.key}/test`);
            onChange(data);
            if (data.status === "connected") {
                notify.success(data.status_message || "Conexión exitosa");
            } else {
                notify.error(data.status_message || "La prueba de conexión falló");
            }
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo probar la conexión"));
            }
        } finally {
            setTesting(false);
        }
    }

    async function handleDisconnect() {
        try {
            const { data } = await axios.delete(`/api/inv-integrations/${provider.key}`);
            onChange(data);
            notify.success("Integración desconectada");
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo desconectar"));
            }
        } finally {
            setDisconnectOpen(false);
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
                <div className="flex items-start gap-3">
                    <Icon className="mt-0.5 h-5 w-5 text-muted-foreground" />
                    <div>
                        <CardTitle className="text-base">{provider.label}</CardTitle>
                        <p className="text-sm text-muted-foreground">{provider.description}</p>
                    </div>
                </div>
                <div className="flex flex-col items-end gap-1">
                    <Badge className={meta.cls}>
                        {StatusIcon ? <StatusIcon className="mr-1 h-3.5 w-3.5" /> : null}
                        {meta.label}
                    </Badge>
                    {initial.last_tested_at ? (
                        <span className="text-xs text-muted-foreground">Probado {relativeTime(initial.last_tested_at)}</span>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {initial.status === "error" && initial.status_message ? (
                    <p className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                        {initial.status_message}
                    </p>
                ) : null}

                <form onSubmit={handleSave} className="grid gap-3 sm:grid-cols-2">
                    {provider.fields.map((field) => {
                        const hasExisting = isConfigured && field.secret && initial[`has_${field.key}`];
                        if (field.type === "checkbox") {
                            return (
                                <label key={field.key} className="flex items-center gap-2 text-sm sm:col-span-2">
                                    <Checkbox
                                        checked={!!form[field.key]}
                                        onCheckedChange={(v) => setField(field.key, !!v)}
                                    />
                                    {field.label}
                                </label>
                            );
                        }
                        return (
                            <div key={field.key} className="space-y-1">
                                <Label htmlFor={`${provider.key}-${field.key}`}>{field.label}</Label>
                                <Input
                                    id={`${provider.key}-${field.key}`}
                                    type={field.type}
                                    value={form[field.key]}
                                    onChange={(e) => setField(field.key, e.target.value)}
                                    placeholder={
                                        hasExisting ? "•••••••• (sin cambios si se deja vacío)" : field.placeholder
                                    }
                                />
                                {errors[field.key] ? (
                                    <p className="text-xs text-destructive">{errors[field.key][0]}</p>
                                ) : null}
                            </div>
                        );
                    })}

                    <div className="flex flex-wrap items-center gap-2 sm:col-span-2">
                        <Button type="submit" disabled={saving}>
                            {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                            {isConfigured ? "Guardar y probar de nuevo" : "Guardar y probar conexión"}
                        </Button>
                        {isConfigured ? (
                            <>
                                <Button type="button" variant="outline" onClick={handleTest} disabled={testing}>
                                    {testing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                                    Probar conexión
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="text-destructive hover:text-destructive"
                                    onClick={() => setDisconnectOpen(true)}
                                >
                                    <Unplug className="mr-2 h-4 w-4" />
                                    Desconectar
                                </Button>
                            </>
                        ) : null}
                    </div>
                </form>
            </CardContent>

            <AlertDialog open={disconnectOpen} onOpenChange={setDisconnectOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>¿Desconectar {provider.label}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Se borran las credenciales guardadas. El módulo de Inventario sigue funcionando de forma
                            manual -- puedes volver a conectarla cuando quieras.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction onClick={handleDisconnect}>Desconectar</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}

export default function Integrations() {
    const { integrations: initialIntegrations } = usePage().props;
    const [byProvider, setByProvider] = useState(() =>
        Object.fromEntries((initialIntegrations ?? []).map((row) => [row.provider, row]))
    );

    return (
        <div className="mx-auto max-w-4xl space-y-6 p-6">
            <div>
                <h1 className="text-xl font-semibold">Integraciones</h1>
                <p className="text-sm text-muted-foreground">
                    Conecta Entra ID, Intune o Active Directory para automatizar Inventario más adelante. Son
                    completamente opcionales -- el módulo sigue funcionando de forma manual si no configuras nada.
                </p>
            </div>

            <div className="space-y-4">
                {INTEGRATION_PROVIDERS.map((provider) => (
                    <IntegrationCard
                        key={provider.key}
                        provider={provider}
                        initial={byProvider[provider.key] ?? { provider: provider.key, status: "not_configured" }}
                        onChange={(row) => setByProvider((prev) => ({ ...prev, [provider.key]: row }))}
                    />
                ))}
            </div>
        </div>
    );
}

Integrations.layout = (page) => <AuthenticatedLayout title="Integraciones">{page}</AuthenticatedLayout>;
