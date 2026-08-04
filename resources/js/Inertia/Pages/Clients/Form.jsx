import { useMemo, useState } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import { useFlash } from "@/hooks/useFlash";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent } from "@/components/ui/card";
import { Switch } from "@/components/ui/switch";
import { Badge } from "@/components/ui/badge";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { ArrowLeft, ArrowRight, Loader2, Plus, Trash2 } from "lucide-react";
import { AddressMapField } from "@/components/maps/AddressMapField";
import { StepIndicator } from "@/components/StepIndicator";

const STEPS = ["Datos básicos", "Contacto y ubicación", "Marca", "Sedes"];

const FIELD_STEP = {
    business_name: 1,
    industry: 1,
    rfc: 1,
    phone: 1,
    contact_name: 2,
    contact_email: 2,
    website: 2,
    address: 2,
    logo: 3,
    is_active: 3,
};

function firstErrorStep(errors) {
    for (const key of Object.keys(errors)) {
        if (key.startsWith("sites.")) return 4;
        if (FIELD_STEP[key]) return FIELD_STEP[key];
    }
    return null;
}

function mapSites(sites) {
    return (sites || []).map((s) => ({
        id: s.id ?? null,
        name: s.name ?? "",
        code: s.code ?? "",
        type: s.type ?? "physical",
        address: s.address ?? "",
        city: s.city ?? "",
        latitude: s.latitude ?? null,
        longitude: s.longitude ?? null,
        contact_name: s.contact_name ?? "",
        contact_phone: s.contact_phone ?? "",
        contact_email: s.contact_email ?? "",
        is_active: s.is_active ?? true,
    }));
}

const EMPTY_SITE = {
    id: null,
    name: "",
    code: "",
    type: "physical",
    address: "",
    city: "",
    latitude: null,
    longitude: null,
    contact_name: "",
    contact_phone: "",
    contact_email: "",
    is_active: true,
};

export default function ClientsForm({ client, industries, sites: initialSites }) {
    useFlash();
    const isEditing = client !== null;
    const [step, setStep] = useState(1);

    const { data, setData, post, put, processing, errors } = useForm({
        business_name: client?.business_name || client?.name || "",
        industry: client?.industry || "",
        rfc: client?.tax_id || "",
        phone: client?.contact_phone || "",
        contact_name: client?.contact_name || "",
        contact_email: client?.contact_email || "",
        website: client?.website || "",
        address: client?.address || "",
        latitude: client?.latitude ?? null,
        longitude: client?.longitude ?? null,
        is_active: client?.is_active ?? true,
        logo: null,
        sites: mapSites(initialSites),
    });

    const [logoPreview, setLogoPreview] = useState(
        client?.logo_path ? `/storage/${client.logo_path.replace(/^\//, "")}` : null
    );

    const title = useMemo(
        () => (isEditing ? "Editar cliente" : "Nuevo cliente"),
        [isEditing]
    );

    const addSite = () => {
        setData("sites", [...data.sites, { ...EMPTY_SITE }]);
    };

    const removeSite = (index) => {
        setData(
            "sites",
            data.sites.filter((_, i) => i !== index)
        );
    };

    const updateSite = (index, field, value) => {
        updateSiteFields(index, { [field]: value });
    };

    // setData("sites", ...) lee data.sites del closure -- llamar updateSite
    // varias veces seguidas (sync, mismo handler) pisa cambios anteriores
    // porque ninguna ve el resultado de la llamada previa todavía. Esta
    // versión mergea todos los campos en una sola actualización.
    const updateSiteFields = (index, patch) => {
        setData((prev) => {
            const next = [...prev.sites];
            next[index] = { ...next[index], ...patch };
            return { ...prev, sites: next };
        });
    };

    const onLogoChange = (e) => {
        const file = e.target.files?.[0];
        setData("logo", file || null);
        if (!file) {
            setLogoPreview(client?.logo_path ? `/storage/${client.logo_path}` : null);
            return;
        }
        const reader = new FileReader();
        reader.onload = () => setLogoPreview(reader.result);
        reader.readAsDataURL(file);
    };

    const canAdvanceFromStep1 = data.business_name.trim().length > 0;

    const goNext = () => setStep((s) => Math.min(s + 1, STEPS.length));
    const goBack = () => setStep((s) => Math.max(s - 1, 1));

    const submit = (e) => {
        e.preventDefault();
        const options = {
            forceFormData: true,
            onError: (errs) => {
                const jumpTo = firstErrorStep(errs);
                if (jumpTo) setStep(jumpTo);
            },
        };
        if (isEditing) {
            put(`/clients/${client.id}`, options);
        } else {
            post("/clients", options);
        }
    };

    return (
        <AuthenticatedLayout title={title}>
            <Head title={title} />

            <div className="mx-auto max-w-2xl">
                <StepIndicator steps={STEPS} currentStep={step} />
            </div>

            <form onSubmit={submit} className="mx-auto mt-6 max-w-2xl space-y-6">
                {step === 1 && (
                    <Card>
                        <CardContent className="space-y-4 pt-6">
                            <div className="space-y-2">
                                <Label>Nombre comercial</Label>
                                <Input
                                    value={data.business_name}
                                    onChange={(e) => setData("business_name", e.target.value)}
                                    placeholder="Ej. Grupo Cargolift S.A. de C.V."
                                    required
                                    autoFocus
                                />
                                {errors.business_name && (
                                    <p className="text-xs text-destructive">{errors.business_name}</p>
                                )}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Industria</Label>
                                    <Select
                                        value={data.industry || "none"}
                                        onValueChange={(v) =>
                                            setData("industry", v === "none" ? "" : v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Seleccionar" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">—</SelectItem>
                                            {industries.map((ind) => (
                                                <SelectItem key={ind} value={ind}>
                                                    {ind}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>RFC</Label>
                                    <Input
                                        value={data.rfc}
                                        onChange={(e) => setData("rfc", e.target.value.toUpperCase())}
                                        placeholder="Ej. GCAR850101AB1 (opcional)"
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label>Teléfono</Label>
                                <Input
                                    value={data.phone}
                                    onChange={(e) => setData("phone", e.target.value)}
                                    maxLength={10}
                                    placeholder="Ej. 5512345678 (10 dígitos)"
                                />
                                {errors.phone && (
                                    <p className="text-xs text-destructive">{errors.phone}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {step === 2 && (
                    <Card>
                        <CardContent className="space-y-4 pt-6">
                            <div className="space-y-2">
                                <Label>Nombre de contacto</Label>
                                <Input
                                    value={data.contact_name}
                                    onChange={(e) => setData("contact_name", e.target.value)}
                                    placeholder="Ej. Laura Jiménez"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Correo</Label>
                                <Input
                                    type="email"
                                    value={data.contact_email}
                                    onChange={(e) => setData("contact_email", e.target.value)}
                                    placeholder="Ej. laura@cargolift.com"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Sitio web</Label>
                                <Input
                                    type="url"
                                    value={data.website}
                                    onChange={(e) => setData("website", e.target.value)}
                                    placeholder="https://cargolift.com"
                                />
                                {errors.website && (
                                    <p className="text-xs text-destructive">{errors.website}</p>
                                )}
                            </div>
                            <AddressMapField
                                address={data.address}
                                onAddressChange={(v) => setData("address", v)}
                                lat={data.latitude}
                                lng={data.longitude}
                                onLocationChange={({ lat, lng, formatted_address }) => {
                                    setData((prev) => ({
                                        ...prev,
                                        address: formatted_address,
                                        latitude: lat,
                                        longitude: lng,
                                    }));
                                }}
                                disabled={processing}
                            />
                        </CardContent>
                    </Card>
                )}

                {step === 3 && (
                    <Card>
                        <CardContent className="space-y-6 pt-6">
                            <div className="space-y-3">
                                <Label>Logotipo</Label>
                                {logoPreview && (
                                    <img
                                        src={logoPreview}
                                        alt=""
                                        className="h-16 w-16 rounded-md border object-cover"
                                    />
                                )}
                                <Input type="file" accept="image/*" onChange={onLogoChange} />
                                <p className="text-xs text-muted-foreground">Opcional -- puedes agregarlo después.</p>
                                {errors.logo && <p className="text-xs text-destructive">{errors.logo}</p>}
                            </div>
                            <div className="flex items-center gap-3">
                                <Switch
                                    checked={data.is_active}
                                    onCheckedChange={(v) => setData("is_active", v)}
                                />
                                <div>
                                    <Label>Cliente activo</Label>
                                    <p className="text-xs text-muted-foreground">
                                        Un cliente inactivo no aparece en los selectores para crear tickets nuevos.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {step === 4 && (
                    <Card>
                        <CardContent className="space-y-4 pt-6">
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Opcional -- puedes agregar sedes ahora o después desde este mismo formulario.
                                </p>
                                <Button type="button" variant="outline" size="sm" onClick={addSite} className="shrink-0">
                                    <Plus className="h-4 w-4 mr-1" />
                                    Agregar sede
                                </Button>
                            </div>

                            {data.sites.length === 0 ? (
                                <p className="text-sm text-muted-foreground">Sin sedes registradas.</p>
                            ) : (
                                data.sites.map((site, index) => (
                                    <div
                                        key={site.id ?? `new-${index}`}
                                        className="space-y-3 rounded-lg border border-border/50 p-4"
                                    >
                                        <div className="flex items-center justify-between">
                                            <Badge variant="outline" className="text-xs">
                                                Sede {index + 1}
                                            </Badge>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                onClick={() => removeSite(index)}
                                            >
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-1">
                                                <Label>Nombre</Label>
                                                <Input
                                                    value={site.name}
                                                    placeholder="Ej. Central Polanco"
                                                    onChange={(e) =>
                                                        updateSite(index, "name", e.target.value)
                                                    }
                                                />
                                                {errors[`sites.${index}.name`] && (
                                                    <p className="text-xs text-destructive">
                                                        {errors[`sites.${index}.name`]}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Código</Label>
                                                <Input
                                                    value={site.code}
                                                    placeholder="Opcional"
                                                    onChange={(e) =>
                                                        updateSite(index, "code", e.target.value)
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-1">
                                                <Label>Tipo</Label>
                                                <Select
                                                    value={site.type}
                                                    onValueChange={(v) => updateSite(index, "type", v)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="physical">Física</SelectItem>
                                                        <SelectItem value="virtual">Virtual</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Ciudad</Label>
                                                <Input
                                                    value={site.city}
                                                    placeholder="Ej. Ciudad de México"
                                                    onChange={(e) =>
                                                        updateSite(index, "city", e.target.value)
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <AddressMapField
                                            address={site.address}
                                            onAddressChange={(v) => updateSite(index, "address", v)}
                                            lat={site.latitude}
                                            lng={site.longitude}
                                            onLocationChange={({ lat, lng, formatted_address }) =>
                                                updateSiteFields(index, {
                                                    address: formatted_address,
                                                    latitude: lat,
                                                    longitude: lng,
                                                })
                                            }
                                            disabled={processing}
                                        />

                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div className="space-y-1">
                                                <Label>Contacto</Label>
                                                <Input
                                                    value={site.contact_name}
                                                    placeholder="Ej. Miguel Torres"
                                                    onChange={(e) =>
                                                        updateSite(index, "contact_name", e.target.value)
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Teléfono</Label>
                                                <Input
                                                    value={site.contact_phone}
                                                    placeholder="Opcional"
                                                    onChange={(e) =>
                                                        updateSite(index, "contact_phone", e.target.value)
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Correo</Label>
                                                <Input
                                                    type="email"
                                                    value={site.contact_email}
                                                    placeholder="Opcional"
                                                    onChange={(e) =>
                                                        updateSite(index, "contact_email", e.target.value)
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <Switch
                                                checked={site.is_active}
                                                onCheckedChange={(v) => updateSite(index, "is_active", v)}
                                            />
                                            <Label className="text-sm">Sede activa</Label>
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
                    <Button type="button" variant="ghost" asChild>
                        <Link href={isEditing ? `/clients/${client.id}` : "/clients"}>
                            Cancelar
                        </Link>
                    </Button>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row">
                        {step > 1 && (
                            <Button type="button" variant="outline" onClick={goBack} disabled={processing}>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Atrás
                            </Button>
                        )}
                        {step < STEPS.length ? (
                            <Button
                                type="button"
                                onClick={goNext}
                                disabled={step === 1 && !canAdvanceFromStep1}
                            >
                                Siguiente
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </Button>
                        ) : (
                            <Button type="submit" disabled={processing}>
                                {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                {isEditing ? "Guardar cambios" : "Crear cliente"}
                            </Button>
                        )}
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
