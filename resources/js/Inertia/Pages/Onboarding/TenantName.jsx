import { Head, useForm } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Loader2 } from "lucide-react";
import { OnboardingShell } from "@/components/onboarding/OnboardingShell";

export default function TenantName({ user_name }) {
    const { data, setData, post, processing, errors } = useForm({
        business_name: "",
    });

    const onSubmit = (e) => {
        e.preventDefault();
        post("/onboarding");
    };

    return (
        <OnboardingShell>
            <Head title="Nombra tu empresa — Tikara" />

            <div className="text-center space-y-1.5">
                <h1 className="text-2xl font-bold">
                    {user_name ? `Bienvenido, ${user_name}` : "Bienvenido"}
                </h1>
                <p className="text-muted-foreground text-sm">
                    Un último paso antes de entrar: dale nombre a tu empresa.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Nombre de tu empresa</CardTitle>
                    <CardDescription>
                        Lo usaremos para tu portal y para identificar tus tickets. Puedes
                        completar el resto de los datos más adelante.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={onSubmit} className="space-y-4" noValidate>
                        <div className="space-y-2">
                            <Label htmlFor="business_name">Nombre de la empresa</Label>
                            <Input
                                id="business_name"
                                placeholder="Ej. Distribuidora del Valle"
                                autoComplete="organization"
                                autoFocus
                                disabled={processing}
                                aria-invalid={Boolean(errors.business_name)}
                                value={data.business_name}
                                onChange={(e) => setData("business_name", e.target.value)}
                                className="h-11"
                            />
                            {errors.business_name && (
                                <p className="text-xs text-destructive">{errors.business_name}</p>
                            )}
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
