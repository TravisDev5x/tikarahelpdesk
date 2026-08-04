import { useMemo, useState } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import { useFlash } from "@/hooks/useFlash";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Switch } from "@/components/ui/switch";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { AddressMapField } from "@/components/maps/AddressMapField";

function mapSites(sites) {
    return (sites || []).map((s) => ({
        id: s.id ?? null,
        name: s.name ?? "",
        address: s.address ?? "",
        city: s.city ?? "",
        latitude: s.latitude ?? null,
        longitude: s.longitude ?? null,
    }));
}

export default function ClientsForm({ client, industries, sites: initialSites }) {
    useFlash();
    const isEditing = client !== null;

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
        setData("sites", [
            ...data.sites,
            { id: null, name: "", address: "", city: "", latitude: null, longitude: null },
        ]);
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

    const submit = (e) => {
        e.preventDefault();
        const options = { forceFormData: true };
        if (isEditing) {
            put(`/clients/${client.id}`, options);
        } else {
            post("/clients", options);
        }
    };

    return (
        <AuthenticatedLayout title={title}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Información principal</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label>Nombre comercial</Label>
                            <Input
                                value={data.business_name}
                                onChange={(e) => setData("business_name", e.target.value)}
                                required
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
                                    placeholder="RFC (opcional)"
                                />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label>Teléfono</Label>
                            <Input
                                value={data.phone}
                                onChange={(e) => setData("phone", e.target.value)}
                                maxLength={10}
                                placeholder="10 dígitos"
                            />
                            {errors.phone && (
                                <p className="text-xs text-destructive">{errors.phone}</p>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Contacto</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label>Nombre de contacto</Label>
                            <Input
                                value={data.contact_name}
                                onChange={(e) => setData("contact_name", e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Correo</Label>
                            <Input
                                type="email"
                                value={data.contact_email}
                                onChange={(e) => setData("contact_email", e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Sitio web</Label>
                            <Input
                                type="url"
                                value={data.website}
                                onChange={(e) => setData("website", e.target.value)}
                                placeholder="https://..."
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

                <Card>
                    <CardHeader>
                        <CardTitle>Logotipo</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {logoPreview && (
                            <img
                                src={logoPreview}
                                alt=""
                                className="h-16 w-16 rounded-md border object-cover"
                            />
                        )}
                        <Input type="file" accept="image/*" onChange={onLogoChange} />
                        {errors.logo && <p className="text-xs text-destructive">{errors.logo}</p>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Estado</CardTitle>
                    </CardHeader>
                    <CardContent className="flex items-center gap-3">
                        <Switch
                            checked={data.is_active}
                            onCheckedChange={(v) => setData("is_active", v)}
                        />
                        <Label>Cliente activo</Label>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Sedes</CardTitle>
                        <Button type="button" variant="outline" size="sm" onClick={addSite}>
                            <Plus className="h-4 w-4 mr-1" />
                            Agregar sede
                        </Button>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {data.sites.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Sin sedes registradas. Puedes agregar sedes ahora o después.
                            </p>
                        ) : (
                            data.sites.map((site, index) => (
                                <div
                                    key={site.id ?? `new-${index}`}
                                    className="space-y-3 border-b border-border/50 pb-4"
                                >
                                    <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] items-end">
                                        <div className="space-y-1">
                                            <Label>Nombre</Label>
                                            <Input
                                                value={site.name}
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
                                            <Label>Ciudad</Label>
                                            <Input
                                                value={site.city}
                                                onChange={(e) =>
                                                    updateSite(index, "city", e.target.value)
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeSite(index)}
                                        >
                                            <Trash2 className="h-4 w-4 text-destructive" />
                                        </Button>
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
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <div className="flex flex-col-reverse sm:flex-row gap-3 sm:justify-end">
                    <Button type="button" variant="outline" asChild>
                        <Link href={isEditing ? `/clients/${client.id}` : "/clients"}>
                            Cancelar
                        </Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        {isEditing ? "Guardar cambios" : "Crear cliente"}
                    </Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
