import { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { useFlash } from "@/hooks/useFlash";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Loader2, Plus, Trash2, Building2, MapPin } from "lucide-react";
import { OnboardingShell } from "@/components/onboarding/OnboardingShell";

const EMPTY_SITE = { name: "", address: "" };

export default function Customers({ customers = [] }) {
    useFlash();
    const [sites, setSites] = useState([{ ...EMPTY_SITE }]);
    const { data, setData, post, processing, errors, reset } = useForm({
        customer_name: "",
        customer_address: "",
    });
    const [finishing, setFinishing] = useState(false);

    const addSiteRow = () => setSites((s) => [...s, { ...EMPTY_SITE }]);
    const removeSiteRow = (i) => setSites((s) => s.filter((_, idx) => idx !== i));
    const updateSite = (i, field, value) =>
        setSites((s) => s.map((site, idx) => (idx === i ? { ...site, [field]: value } : site)));

    const onSubmit = (e) => {
        e.preventDefault();
        router.post(
            "/onboarding/customers",
            { customer_name: data.customer_name, customer_address: data.customer_address, sites },
            {
                preserveScroll: true,
                onSuccess: () => {
                    reset();
                    setSites([{ ...EMPTY_SITE }]);
                },
            }
        );
    };

    const onFinish = () => {
        setFinishing(true);
        router.post("/onboarding/customers/finish", {}, { onFinish: () => setFinishing(false) });
    };

    return (
        <OnboardingShell>
            <Head title="Tus clientes — Tikara" />

            <div className="text-center space-y-1.5">
                <h1 className="text-2xl font-bold">Da de alta a tus clientes</h1>
                <p className="text-muted-foreground text-sm">
                    Agrega las empresas a las que les das soporte, con al menos una sede
                    cada una. Puedes agregar más después desde la plataforma.
                </p>
            </div>

            {customers.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Ya agregados</CardTitle>
                        <CardDescription>{customers.length} cliente(s)</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {customers.map((c) => (
                            <div key={c.id} className="rounded-md border p-3 space-y-1.5">
                                <p className="flex items-center gap-1.5 text-sm font-medium">
                                    <Building2 className="h-4 w-4 text-muted-foreground" />
                                    {c.name}
                                </p>
                                {(c.sites ?? []).map((s) => (
                                    <p
                                        key={s.id}
                                        className="flex items-center gap-1.5 pl-5 text-xs text-muted-foreground"
                                    >
                                        <MapPin className="h-3 w-3" />
                                        {s.name} — {s.address}
                                    </p>
                                ))}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Agregar cliente</CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={onSubmit} className="space-y-4" noValidate>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="customer_name">Nombre de la empresa</Label>
                                <Input
                                    id="customer_name"
                                    disabled={processing}
                                    value={data.customer_name}
                                    onChange={(e) => setData("customer_name", e.target.value)}
                                    className="h-11"
                                />
                                {errors.customer_name && (
                                    <p className="text-xs text-destructive">{errors.customer_name}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="customer_address">Dirección principal</Label>
                                <Input
                                    id="customer_address"
                                    disabled={processing}
                                    value={data.customer_address}
                                    onChange={(e) => setData("customer_address", e.target.value)}
                                    className="h-11"
                                />
                                {errors.customer_address && (
                                    <p className="text-xs text-destructive">{errors.customer_address}</p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Sedes</Label>
                                <Badge variant="outline" className="text-[10px]">
                                    Al menos una
                                </Badge>
                            </div>
                            {sites.map((site, i) => (
                                <div key={i} className="flex gap-2">
                                    <Input
                                        placeholder="Nombre de la sede"
                                        disabled={processing}
                                        value={site.name}
                                        onChange={(e) => updateSite(i, "name", e.target.value)}
                                        className="h-10"
                                    />
                                    <Input
                                        placeholder="Dirección"
                                        disabled={processing}
                                        value={site.address}
                                        onChange={(e) => updateSite(i, "address", e.target.value)}
                                        className="h-10"
                                    />
                                    {sites.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="h-10 w-10 shrink-0 text-destructive"
                                            onClick={() => removeSiteRow(i)}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                            ))}
                            {errors.sites && <p className="text-xs text-destructive">{errors.sites}</p>}
                            <Button type="button" variant="outline" size="sm" onClick={addSiteRow} className="gap-1.5">
                                <Plus className="h-3.5 w-3.5" /> Agregar otra sede
                            </Button>
                        </div>

                        <Button type="submit" variant="secondary" className="w-full" disabled={processing}>
                            {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Agregar este cliente
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <Button className="w-full" onClick={onFinish} disabled={finishing}>
                {finishing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Continuar →
            </Button>
        </OnboardingShell>
    );
}
