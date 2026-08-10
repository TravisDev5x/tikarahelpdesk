import { Head, useForm } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Loader2, Building2, Users, Network } from "lucide-react";
import { OnboardingShell } from "@/components/onboarding/OnboardingShell";
import { cn } from "@/lib/utils";

const MODES = [
    {
        value: "internal",
        icon: Building2,
        title: "Uso interno",
        description: "Solo para tu propio equipo de TI -- sin clientes externos.",
    },
    {
        value: "msp",
        icon: Users,
        title: "Proveedor de servicios (MSP)",
        description: "Das soporte a empresas externas como tu negocio principal.",
    },
    {
        value: "hybrid",
        icon: Network,
        title: "Híbrido",
        description: "Tu propio equipo de TI, y además das soporte a clientes externos.",
    },
];

export default function Modality() {
    const { data, setData, post, processing, errors } = useForm({ mode: "" });

    const onSubmit = (e) => {
        e.preventDefault();
        if (!data.mode) return;
        post("/onboarding/modality");
    };

    return (
        <OnboardingShell>
            <Head title="Modalidad de tu cuenta — Tikara" />

            <div className="text-center space-y-1.5">
                <h1 className="text-2xl font-bold">¿Cómo vas a usar Tikara?</h1>
                <p className="text-muted-foreground text-sm">
                    Puedes cambiarlo más adelante si tu operación crece.
                </p>
            </div>

            <form onSubmit={onSubmit} className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    {MODES.map(({ value, icon: Icon, title, description }) => (
                        <Card
                            key={value}
                            role="button"
                            tabIndex={0}
                            onClick={() => setData("mode", value)}
                            onKeyDown={(e) => {
                                if (e.key === "Enter" || e.key === " ") setData("mode", value);
                            }}
                            className={cn(
                                "cursor-pointer transition-colors hover:border-primary/60",
                                data.mode === value && "border-primary ring-1 ring-primary"
                            )}
                        >
                            <CardContent className="pt-6 space-y-2 text-center">
                                <Icon className="mx-auto h-8 w-8 text-primary" />
                                <p className="font-semibold text-sm">{title}</p>
                                <p className="text-xs text-muted-foreground">{description}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {errors.mode && <p className="text-center text-xs text-destructive">{errors.mode}</p>}

                <Button type="submit" className="w-full" disabled={processing || !data.mode}>
                    {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    Continuar →
                </Button>
            </form>
        </OnboardingShell>
    );
}
