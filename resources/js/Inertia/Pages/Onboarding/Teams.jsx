import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { useFlash } from "@/hooks/useFlash";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Loader2, MapPin, User as UserIcon } from "lucide-react";
import { OnboardingShell } from "@/components/onboarding/OnboardingShell";

export default function Teams({ staff = [], sites = [] }) {
    useFlash();
    const [selections, setSelections] = useState(() =>
        Object.fromEntries(staff.filter((s) => s.accepted).map((s) => [s.user_id, s.site_ids]))
    );
    const [savingUserId, setSavingUserId] = useState(null);
    const [finishing, setFinishing] = useState(false);

    const toggleSite = (userId, siteId) => {
        setSelections((prev) => {
            const current = prev[userId] ?? [];
            const next = current.includes(siteId)
                ? current.filter((id) => id !== siteId)
                : [...current, siteId];
            return { ...prev, [userId]: next };
        });
    };

    const saveAssignment = (userId) => {
        setSavingUserId(userId);
        router.post(
            "/onboarding/teams",
            { user_id: userId, site_ids: selections[userId] ?? [] },
            { preserveScroll: true, onFinish: () => setSavingUserId(null) }
        );
    };

    const onFinish = () => {
        setFinishing(true);
        router.post("/onboarding/teams/finish", {}, { onFinish: () => setFinishing(false) });
    };

    return (
        <OnboardingShell>
            <Head title="Asigna sedes a tu equipo — Tikara" />

            <div className="text-center space-y-1.5">
                <h1 className="text-2xl font-bold">Asigna sedes a tu equipo</h1>
                <p className="text-muted-foreground text-sm">
                    Opcional -- puedes asignarlas ahora o hacerlo después.
                    {sites.length === 0 && " Todavía no tienes sedes registradas."}
                </p>
            </div>

            {staff.length === 0 ? (
                <Card>
                    <CardContent className="pt-6 text-center text-sm text-muted-foreground">
                        No invitaste a nadie en el paso anterior -- no hay a quién asignar sedes
                        todavía.
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-3">
                    {staff.map((s) => (
                        <Card key={s.email}>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-1.5 text-base">
                                    <UserIcon className="h-4 w-4 text-muted-foreground" />
                                    {s.email}
                                    {!s.accepted && (
                                        <Badge variant="outline" className="text-[10px]">
                                            Invitación pendiente
                                        </Badge>
                                    )}
                                </CardTitle>
                                {!s.accepted && (
                                    <CardDescription>
                                        Todavía no acepta su invitación -- podrás asignarle sedes en
                                        cuanto lo haga.
                                    </CardDescription>
                                )}
                            </CardHeader>
                            {s.accepted && sites.length > 0 && (
                                <CardContent className="space-y-3">
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {sites.map((site) => (
                                            <label
                                                key={site.id}
                                                className="flex items-center gap-2 rounded-md border p-2 text-sm cursor-pointer"
                                            >
                                                <Checkbox
                                                    checked={(selections[s.user_id] ?? []).includes(site.id)}
                                                    onCheckedChange={() => toggleSite(s.user_id, site.id)}
                                                />
                                                <MapPin className="h-3.5 w-3.5 text-muted-foreground" />
                                                {site.name}
                                            </label>
                                        ))}
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => saveAssignment(s.user_id)}
                                        disabled={savingUserId === s.user_id}
                                    >
                                        {savingUserId === s.user_id && (
                                            <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />
                                        )}
                                        Guardar
                                    </Button>
                                </CardContent>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            <Button className="w-full" onClick={onFinish} disabled={finishing}>
                {finishing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Terminar →
            </Button>
        </OnboardingShell>
    );
}
