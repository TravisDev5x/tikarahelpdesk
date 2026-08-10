import { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { useFlash } from "@/hooks/useFlash";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Loader2, Mail, UserPlus } from "lucide-react";
import { OnboardingShell } from "@/components/onboarding/OnboardingShell";

export default function Staff({ invitations = [], role_options = [], seats = { used: 0, max: null } }) {
    useFlash();
    const { data, setData, post, processing, errors, reset } = useForm({
        email: "",
        role: role_options[0] ?? "",
    });
    const [finishing, setFinishing] = useState(false);

    const atCapacity = seats.max !== null && seats.used >= seats.max;

    const onSubmit = (e) => {
        e.preventDefault();
        post("/onboarding/staff", {
            preserveScroll: true,
            onSuccess: () => reset("email"),
        });
    };

    const onFinish = () => {
        setFinishing(true);
        router.post("/onboarding/staff/finish", {}, { onFinish: () => setFinishing(false) });
    };

    return (
        <OnboardingShell>
            <Head title="Invita a tu equipo — Tikara" />

            <div className="text-center space-y-1.5">
                <h1 className="text-2xl font-bold">Invita a tu equipo</h1>
                <p className="text-muted-foreground text-sm">
                    Opcional -- puedes invitar personal ahora o hacerlo después desde la
                    plataforma.
                </p>
                {seats.max !== null && (
                    <Badge variant="outline" className="text-[10px]">
                        {seats.used} / {seats.max} usuarios de tu plan
                    </Badge>
                )}
            </div>

            {invitations.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Invitaciones pendientes</CardTitle>
                        <CardDescription>{invitations.length} enviada(s)</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {invitations.map((i) => (
                            <div
                                key={i.id}
                                className="flex items-center justify-between rounded-md border p-3 text-sm"
                            >
                                <span className="flex items-center gap-1.5">
                                    <Mail className="h-4 w-4 text-muted-foreground" />
                                    {i.email}
                                </span>
                                {i.role_name && <Badge variant="secondary">{i.role_name}</Badge>}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Invitar a alguien</CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={onSubmit} className="space-y-4" noValidate>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="email">Correo</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    disabled={processing || atCapacity}
                                    value={data.email}
                                    onChange={(e) => setData("email", e.target.value)}
                                    className="h-11"
                                />
                                {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="role">Rol</Label>
                                <Select
                                    value={data.role}
                                    onValueChange={(v) => setData("role", v)}
                                    disabled={processing || atCapacity}
                                >
                                    <SelectTrigger id="role" className="h-11">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {role_options.map((r) => (
                                            <SelectItem key={r} value={r}>
                                                {r}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.role && <p className="text-xs text-destructive">{errors.role}</p>}
                            </div>
                        </div>

                        <Button
                            type="submit"
                            variant="secondary"
                            className="w-full gap-1.5"
                            disabled={processing || atCapacity}
                        >
                            {processing ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                                <UserPlus className="h-4 w-4" />
                            )}
                            {atCapacity ? "Sin cupo en tu plan" : "Enviar invitación"}
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
