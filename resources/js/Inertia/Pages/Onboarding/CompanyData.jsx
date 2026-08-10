import { Head, useForm } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Loader2 } from "lucide-react";
import { OnboardingShell } from "@/components/onboarding/OnboardingShell";

export default function CompanyData({ client }) {
    const { data, setData, post, processing, errors } = useForm({
        legal_name: client?.legal_name ?? "",
        tax_id: client?.tax_id ?? "",
        contact_phone: client?.contact_phone ?? "",
        website: client?.website ?? "",
        address: client?.address ?? "",
        city: client?.city ?? "",
        country: client?.country ?? "MX",
    });

    const onSubmit = (e) => {
        e.preventDefault();
        post("/onboarding/company");
    };

    return (
        <OnboardingShell>
            <Head title="Datos de tu empresa — Tikara" />

            <div className="text-center space-y-1.5">
                <h1 className="text-2xl font-bold">Datos de tu empresa</h1>
                <p className="text-muted-foreground text-sm">
                    Nada de esto es obligatorio salvo dirección, ciudad y país — puedes
                    completar el resto más adelante.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Razón social y contacto</CardTitle>
                    <CardDescription>Se usa en facturas y como referencia interna.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={onSubmit} className="space-y-4" noValidate>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="legal_name">Razón social (opcional)</Label>
                                <Input
                                    id="legal_name"
                                    placeholder="Ej. Distribuidora del Valle S.A. de C.V."
                                    disabled={processing}
                                    value={data.legal_name}
                                    onChange={(e) => setData("legal_name", e.target.value)}
                                    className="h-11"
                                />
                                {errors.legal_name && <p className="text-xs text-destructive">{errors.legal_name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="tax_id">RFC (opcional)</Label>
                                <Input
                                    id="tax_id"
                                    placeholder="XAXX010101000"
                                    disabled={processing}
                                    value={data.tax_id}
                                    onChange={(e) => setData("tax_id", e.target.value.toUpperCase())}
                                    className="h-11"
                                />
                                {errors.tax_id && <p className="text-xs text-destructive">{errors.tax_id}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="contact_phone">Teléfono (opcional)</Label>
                                <Input
                                    id="contact_phone"
                                    type="tel"
                                    maxLength={10}
                                    placeholder="5512345678"
                                    disabled={processing}
                                    value={data.contact_phone}
                                    onChange={(e) => setData("contact_phone", e.target.value)}
                                    className="h-11"
                                />
                                {errors.contact_phone && (
                                    <p className="text-xs text-destructive">{errors.contact_phone}</p>
                                )}
                            </div>

                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="website">Sitio web (opcional)</Label>
                                <Input
                                    id="website"
                                    type="url"
                                    placeholder="https://tuempresa.com"
                                    disabled={processing}
                                    value={data.website}
                                    onChange={(e) => setData("website", e.target.value)}
                                    className="h-11"
                                />
                                {errors.website && <p className="text-xs text-destructive">{errors.website}</p>}
                            </div>

                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="address">Dirección</Label>
                                <Input
                                    id="address"
                                    placeholder="Calle, número, colonia"
                                    disabled={processing}
                                    value={data.address}
                                    onChange={(e) => setData("address", e.target.value)}
                                    className="h-11"
                                />
                                {errors.address && <p className="text-xs text-destructive">{errors.address}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="city">Ciudad</Label>
                                <Input
                                    id="city"
                                    placeholder="Ciudad de México"
                                    disabled={processing}
                                    value={data.city}
                                    onChange={(e) => setData("city", e.target.value)}
                                    className="h-11"
                                />
                                {errors.city && <p className="text-xs text-destructive">{errors.city}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="country">País (código de 2 letras)</Label>
                                <Input
                                    id="country"
                                    maxLength={2}
                                    placeholder="MX"
                                    disabled={processing}
                                    value={data.country}
                                    onChange={(e) => setData("country", e.target.value.toUpperCase())}
                                    className="h-11"
                                />
                                {errors.country && <p className="text-xs text-destructive">{errors.country}</p>}
                            </div>
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Continuar →
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </OnboardingShell>
    );
}
