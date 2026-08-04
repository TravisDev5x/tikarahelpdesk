import { Head, Link } from "@inertiajs/react";
import { useFlash } from "@/hooks/useFlash";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { storageUrl } from "@/lib/storage";
import { PlanTypeBadge } from "@/components/badges/EntityBadges";
import { badgeStatus } from "@/lib/badgeStyles";
import { brandLogo } from "@/lib/marketingTheme";
import {
    Building2,
    Flag,
    Globe,
    MapPin,
    Pencil,
    Phone,
} from "lucide-react";

function initials(name) {
    const parts = String(name || "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (parts.length === 0) return "??";
    return (parts[0][0] + (parts[1]?.[0] || "")).toUpperCase();
}

function formatDate(value) {
    if (!value) return "—";
    return new Intl.DateTimeFormat("es-MX", {
        dateStyle: "long",
        timeStyle: "short",
    }).format(new Date(value));
}

function formatMoney(value) {
    const n = Number(value);
    if (!n) return null;
    return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(n);
}

function isInTrial(profile) {
    if (!profile?.trial_ends_at) return false;
    return new Date(profile.trial_ends_at) > new Date();
}

export default function CompanyShow({ profile, plan, can }) {
    useFlash();

    const logoUrl = storageUrl(profile?.logo_path);

    return (
        <>
            <Head title="Mi empresa" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-end gap-4 mb-6">
                {can?.edit && (
                    <Button asChild>
                        <Link href="/company/edit">
                            <Pencil className="h-4 w-4 mr-2" />
                            Editar perfil
                        </Link>
                    </Button>
                )}
            </div>

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">Identidad</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col sm:flex-row gap-6">
                            {logoUrl ? (
                                <img
                                    src={logoUrl}
                                    alt=""
                                    className="w-24 h-24 rounded-xl object-cover border border-border shrink-0"
                                />
                            ) : (
                                <div className={`h-24 w-24 shrink-0 rounded-xl ${brandLogo}`}>
                                    <span className="text-3xl font-black text-brand-foreground">
                                        {initials(profile.business_name)}
                                    </span>
                                </div>
                            )}

                            <div className="flex-1 space-y-4">
                                <div>
                                    <h2 className="text-xl font-semibold">{profile.business_name}</h2>
                                    {profile.rfc && (
                                        <Badge variant="secondary" className="mt-2">
                                            {profile.rfc}
                                        </Badge>
                                    )}
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2 text-sm">
                                    {profile.phone && (
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            <Phone className="h-4 w-4 shrink-0" />
                                            <span>{profile.phone}</span>
                                        </div>
                                    )}
                                    {profile.website && (
                                        <div className="flex items-center gap-2">
                                            <Globe className="h-4 w-4 shrink-0 text-muted-foreground" />
                                            <a
                                                href={profile.website}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-primary hover:underline truncate"
                                            >
                                                {profile.website}
                                            </a>
                                        </div>
                                    )}
                                    {profile.address && (
                                        <div className="flex items-start gap-2 text-muted-foreground sm:col-span-2">
                                            <MapPin className="h-4 w-4 shrink-0 mt-0.5" />
                                            <span>{profile.address}</span>
                                        </div>
                                    )}
                                    {profile.city && (
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            <Building2 className="h-4 w-4 shrink-0" />
                                            <span>{profile.city}</span>
                                        </div>
                                    )}
                                    {profile.country && (
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            <Flag className="h-4 w-4 shrink-0" />
                                            <span>{profile.country}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">Plan activo</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {plan ? (
                            <>
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold text-lg">{plan.name}</span>
                                        <PlanTypeBadge type={plan.type} />
                                        {isInTrial(profile) && (
                                            <Badge className={badgeStatus.warning}>
                                                Trial · vence{" "}
                                                {new Intl.DateTimeFormat("es-MX", {
                                                    dateStyle: "medium",
                                                }).format(new Date(profile.trial_ends_at))}
                                            </Badge>
                                        )}
                                    </div>
                                    <span className="text-lg font-semibold">
                                        {Number(plan.price_monthly) > 0
                                            ? `${formatMoney(plan.price_monthly)}/mes`
                                            : "Contactar"}
                                    </span>
                                </div>

                                <Separator className="my-4" />

                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="outline">
                                        {plan.max_clients ?? "∞"} clientes
                                    </Badge>
                                    <Badge variant="outline">
                                        {plan.max_users ?? "∞"} usuarios
                                    </Badge>
                                    <Badge variant="outline">
                                        {plan.max_agents ?? "∞"} agentes
                                    </Badge>
                                </div>

                                {can?.edit && (
                                    <Button variant="outline" size="sm" className="mt-4" disabled>
                                        Cambiar plan
                                    </Button>
                                )}
                            </>
                        ) : (
                            <div className="space-y-3">
                                <p className="text-sm text-muted-foreground">Sin plan activo</p>
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/register?step=plan">Seleccionar plan</Link>
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card className="bg-muted/30 border-border/60">
                    <CardContent className="py-4 grid gap-2 sm:grid-cols-2 text-sm text-muted-foreground">
                        <p>
                            <span className="font-medium text-foreground">Cuenta creada:</span>{" "}
                            {formatDate(profile.created_at)}
                        </p>
                        <p>
                            <span className="font-medium text-foreground">Última actualización:</span>{" "}
                            {formatDate(profile.updated_at)}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CompanyShow.layout = (page) => <AuthenticatedLayout>{page}</AuthenticatedLayout>;
