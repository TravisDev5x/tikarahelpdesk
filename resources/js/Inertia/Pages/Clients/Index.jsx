import { useEffect, useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { differenceInDays, parseISO } from "date-fns";
import { useFlash } from "@/hooks/useFlash";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import InertiaPageShell from "@/Inertia/components/InertiaPageShell";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/components/ui/tooltip";
import { clientActiveBadge, clientInactiveBadge } from "@/lib/badgeStyles";
import { cn } from "@/lib/utils";
import {
    Ban,
    Building2,
    CreditCard,
    Eye,
    ExternalLink,
    MapPin,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Ticket,
    Trash2,
    Users,
} from "lucide-react";

// ─── helpers ────────────────────────────────────────────────────────────────

function initials(name) {
    const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return "??";
    return (parts[0][0] + (parts[1]?.[0] || "")).toUpperCase();
}

function logoUrl(path) {
    if (!path) return null;
    return `/storage/${path.replace(/^\//, "")}`;
}

function matchesSearch(client, query) {
    const q = query.trim().toLowerCase();
    if (!q) return true;
    return [
        client.business_name,
        client.name,
        client.contact_name,
        client.contact_email,
        client.industry,
        client.portal_slug,
        client.operator_user?.name,
        client.plan?.name,
    ].some((f) => f && String(f).toLowerCase().includes(q));
}

/**
 * Returns { label, color } for the subscription expiry date.
 * null date → "Sin vencimiento"
 */
function expiryInfo(dateStr) {
    if (!dateStr) return { label: "Sin vencimiento", color: "text-muted-foreground" };
    const days = differenceInDays(parseISO(dateStr), new Date());
    if (days < 0) return { label: "Vencido", color: "text-destructive font-medium" };
    if (days === 0) return { label: "Vence hoy", color: "text-destructive font-medium" };
    if (days <= 14) return { label: `${days}d`, color: "text-orange-500 font-medium" };
    if (days <= 30) return { label: `${days}d`, color: "text-yellow-600 font-medium" };
    return { label: `${days}d`, color: "text-green-600" };
}

const PLAN_TYPE_STYLES = {
    free:       "border-slate-300 text-slate-600",
    trial:      "border-blue-300 text-blue-700",
    starter:    "border-sky-300 text-sky-700",
    basic:      "border-cyan-300 text-cyan-700",
    professional:"border-purple-300 text-purple-700",
    pro:        "border-purple-300 text-purple-700",
    enterprise: "border-amber-400 text-amber-700",
};

// ─── sub-components ──────────────────────────────────────────────────────────

function SummaryCard({ icon: Icon, label, value, color = "text-foreground" }) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <div className="rounded-lg bg-muted p-2.5">
                    <Icon className={cn("h-5 w-5", color)} />
                </div>
                <div>
                    <p className="text-2xl font-bold leading-none">{value}</p>
                    <p className="text-xs text-muted-foreground mt-1">{label}</p>
                </div>
            </CardContent>
        </Card>
    );
}

function StatBadge({ value, icon: Icon }) {
    if (value === 0 || value == null)
        return <span className="text-muted-foreground text-sm">—</span>;
    return (
        <span className="inline-flex items-center gap-1 text-sm font-medium">
            {Icon && <Icon className="h-3.5 w-3.5 text-muted-foreground" />}
            {value}
        </span>
    );
}

function PlanCell({ client }) {
    const plan = client.plan;
    if (!plan) {
        return <span className="text-muted-foreground text-sm">Sin plan</span>;
    }
    const typeKey = (plan.type ?? "").toLowerCase();
    const style = PLAN_TYPE_STYLES[typeKey] ?? "border-slate-200 text-slate-600";
    const { label: exLabel, color: exColor } = expiryInfo(client.subscription_expires_at);

    return (
        <div className="flex flex-col gap-0.5 min-w-[90px]">
            <Badge variant="outline" className={cn("text-xs w-fit", style)}>
                {plan.name}
            </Badge>
            <span className={cn("text-xs", exColor)}>{exLabel}</span>
        </div>
    );
}

// ─── main component ───────────────────────────────────────────────────────────

export default function Index({
    clients,
    summary,
    showOperatorColumn = false,
    isPlatformAdmin = false,
    canManageClients = false,
    portalBaseDomain,
    portalScheme = "http",
}) {
    useFlash();
    const rows = clients?.data ?? [];
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [cancelTarget, setCancelTarget] = useState(null);
    const [reactivateTarget, setReactivateTarget] = useState(null);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");

    useEffect(() => {
        const unsubStart = router.on("start", () => setLoading(true));
        const unsubFinish = router.on("finish", () => setLoading(false));
        return () => { unsubStart(); unsubFinish(); };
    }, []);

    const filteredRows = useMemo(
        () => rows.filter((c) => matchesSearch(c, search)),
        [rows, search]
    );

    const portalUrl = (slug) =>
        slug && portalBaseDomain
            ? `${portalScheme}://${slug}.${portalBaseDomain}`
            : null;

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/clients/${deleteTarget.id}`, {
            onFinish: () => setDeleteTarget(null),
        });
    };

    const handleCancel = () => {
        if (!cancelTarget) return;
        router.patch(`/clients/${cancelTarget.id}/cancel`, {}, {
            onFinish: () => setCancelTarget(null),
        });
    };

    const handleReactivate = () => {
        if (!reactivateTarget) return;
        router.patch(`/clients/${reactivateTarget.id}/reactivate`, {}, {
            onFinish: () => setReactivateTarget(null),
        });
    };

    // inactive = total - active (includes cancelled)
    const inactiveCount = (summary?.total ?? 0) - (summary?.active ?? 0);

    // column count for skeleton colSpan
    const colCount =
        7 +
        (showOperatorColumn ? 1 : 0) +
        (isPlatformAdmin ? 1 : 0);

    const tableHeaders = (
        <TableRow>
            <TableHead>Cliente</TableHead>
            {showOperatorColumn && <TableHead>Operador</TableHead>}
            <TableHead>Portal</TableHead>
            <TableHead className="text-center">Secciones</TableHead>
            <TableHead className="text-center">Usuarios</TableHead>
            <TableHead className="text-center">Tickets</TableHead>
            {isPlatformAdmin && <TableHead>Plan / Vencimiento</TableHead>}
            <TableHead>Estatus</TableHead>
            <TableHead className="text-right">Acciones</TableHead>
        </TableRow>
    );

    return (
        <AuthenticatedLayout title="Clientes">
            <Head title="Clientes" />

            <InertiaPageShell className="space-y-6">

                {/* KPI summary */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <SummaryCard
                        icon={Building2}
                        label="Clientes totales"
                        value={summary?.total ?? 0}
                    />
                    <SummaryCard
                        icon={Building2}
                        label={isPlatformAdmin ? `Activos / ${inactiveCount} inactivos` : "Activos"}
                        value={summary?.active ?? 0}
                        color="text-green-600"
                    />
                    <SummaryCard
                        icon={Users}
                        label="Usuarios totales"
                        value={summary?.total_users ?? 0}
                        color="text-blue-600"
                    />
                    <SummaryCard
                        icon={Ticket}
                        label="Tickets totales"
                        value={summary?.total_tickets ?? 0}
                        color="text-orange-500"
                    />
                </div>

                {/* Header + search */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="relative max-w-sm w-full">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            className="pl-9"
                            placeholder="Buscar por nombre, portal, contacto…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    {canManageClients && (
                        <Button asChild>
                            <Link href="/clients/create">
                                <Plus className="h-4 w-4 mr-2" />
                                Nuevo cliente
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Table / empty */}
                {rows.length === 0 && !loading ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                            <Building2 className="h-12 w-12 text-muted-foreground mb-4" />
                            <h2 className="text-lg font-semibold">Sin clientes registrados</h2>
                            <p className="text-sm text-muted-foreground mt-2 mb-6 max-w-sm">
                                Agrega el primer cliente al que prestas servicios de soporte.
                            </p>
                            {canManageClients && (
                                <Button asChild>
                                    <Link href="/clients/create">Nuevo cliente</Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <Card>
                            {loading ? (
                                <Table>
                                    <TableHeader>{tableHeaders}</TableHeader>
                                    <TableBody>
                                        {[...Array(5)].map((_, i) => (
                                            <TableRow key={i}>
                                                <TableCell colSpan={colCount}>
                                                    <Skeleton className="h-10 w-full" />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            ) : filteredRows.length === 0 ? (
                                <div className="py-12 text-center text-sm text-muted-foreground">
                                    No hay resultados para &quot;{search}&quot;
                                </div>
                            ) : (
                                <Table>
                                    <TableHeader>{tableHeaders}</TableHeader>
                                    <TableBody>
                                        {filteredRows.map((client) => {
                                            const isCancelled = !!client.cancelled_at;
                                            return (
                                                <TableRow
                                                    key={client.id}
                                                    className={cn(isCancelled && "opacity-60")}
                                                >
                                                    {/* Cliente */}
                                                    <TableCell>
                                                        <div className="flex items-center gap-3">
                                                            {client.logo_path ? (
                                                                <img
                                                                    src={logoUrl(client.logo_path)}
                                                                    alt=""
                                                                    className="h-9 w-9 rounded-md object-cover border shrink-0"
                                                                />
                                                            ) : (
                                                                <div className="h-9 w-9 rounded-md bg-muted flex items-center justify-center text-xs font-semibold shrink-0">
                                                                    {initials(client.business_name || client.name)}
                                                                </div>
                                                            )}
                                                            <div>
                                                                <p className="font-medium leading-none">
                                                                    {client.business_name || client.name}
                                                                </p>
                                                                {client.industry && (
                                                                    <p className="text-xs text-muted-foreground mt-0.5">
                                                                        {client.industry}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </TableCell>

                                                    {/* Operador (MSP admins only) */}
                                                    {showOperatorColumn && (
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {client.operator_user?.name ?? "—"}
                                                        </TableCell>
                                                    )}

                                                    {/* Portal */}
                                                    <TableCell>
                                                        {client.portal_slug ? (
                                                            portalUrl(client.portal_slug) ? (
                                                                <a
                                                                    href={portalUrl(client.portal_slug)}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                                                                >
                                                                    {client.portal_slug}
                                                                    <ExternalLink className="h-3 w-3" />
                                                                </a>
                                                            ) : (
                                                                <Badge variant="secondary" className="font-mono text-xs">
                                                                    {client.portal_slug}
                                                                </Badge>
                                                            )
                                                        ) : (
                                                            <span className="text-muted-foreground text-sm">—</span>
                                                        )}
                                                    </TableCell>

                                                    {/* Secciones */}
                                                    <TableCell className="text-center">
                                                        <StatBadge value={client.sites_count} icon={MapPin} />
                                                    </TableCell>

                                                    {/* Usuarios */}
                                                    <TableCell className="text-center">
                                                        <StatBadge value={client.users_count} icon={Users} />
                                                    </TableCell>

                                                    {/* Tickets */}
                                                    <TableCell className="text-center">
                                                        <StatBadge value={client.tickets_count} icon={Ticket} />
                                                    </TableCell>

                                                    {/* Plan / Vencimiento (solo DIOS) */}
                                                    {isPlatformAdmin && (
                                                        <TableCell>
                                                            <PlanCell client={client} />
                                                        </TableCell>
                                                    )}

                                                    {/* Estatus */}
                                                    <TableCell>
                                                        {isCancelled ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-xs border-destructive/50 text-destructive"
                                                            >
                                                                Cancelado
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    "text-xs",
                                                                    client.is_active
                                                                        ? clientActiveBadge
                                                                        : clientInactiveBadge
                                                                )}
                                                            >
                                                                {client.is_active ? "Activo" : "Inactivo"}
                                                            </Badge>
                                                        )}
                                                    </TableCell>

                                                    {/* Acciones */}
                                                    <TableCell className="text-right">
                                                        <TooltipProvider>
                                                            <div className="flex justify-end gap-1">
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button variant="ghost" size="icon" asChild>
                                                                            <Link href={`/clients/${client.id}`}>
                                                                                <Eye className="h-4 w-4" />
                                                                            </Link>
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>Ver cliente</TooltipContent>
                                                                </Tooltip>

                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button variant="ghost" size="icon" asChild>
                                                                            <Link href={`/clients/${client.id}/edit`}>
                                                                                <Pencil className="h-4 w-4" />
                                                                            </Link>
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>Editar</TooltipContent>
                                                                </Tooltip>

                                                                {/* Cancel / Reactivate — solo DIOS */}
                                                                {isPlatformAdmin && (
                                                                    isCancelled ? (
                                                                        <Tooltip>
                                                                            <TooltipTrigger asChild>
                                                                                <Button
                                                                                    variant="ghost"
                                                                                    size="icon"
                                                                                    className="text-green-600 hover:text-green-700"
                                                                                    onClick={() => setReactivateTarget(client)}
                                                                                >
                                                                                    <RefreshCw className="h-4 w-4" />
                                                                                </Button>
                                                                            </TooltipTrigger>
                                                                            <TooltipContent>Reactivar cuenta</TooltipContent>
                                                                        </Tooltip>
                                                                    ) : (
                                                                        <Tooltip>
                                                                            <TooltipTrigger asChild>
                                                                                <Button
                                                                                    variant="ghost"
                                                                                    size="icon"
                                                                                    className="text-orange-500 hover:text-orange-600"
                                                                                    onClick={() => setCancelTarget(client)}
                                                                                >
                                                                                    <Ban className="h-4 w-4" />
                                                                                </Button>
                                                                            </TooltipTrigger>
                                                                            <TooltipContent>Cancelar cuenta</TooltipContent>
                                                                        </Tooltip>
                                                                    )
                                                                )}

                                                                {!isPlatformAdmin && canManageClients && (
                                                                    <Tooltip>
                                                                        <TooltipTrigger asChild>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="text-destructive hover:text-destructive"
                                                                                onClick={() => setDeleteTarget(client)}
                                                                            >
                                                                                <Trash2 className="h-4 w-4" />
                                                                            </Button>
                                                                        </TooltipTrigger>
                                                                        <TooltipContent>Eliminar</TooltipContent>
                                                                    </Tooltip>
                                                                )}
                                                            </div>
                                                        </TooltipProvider>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            )}
                        </Card>

                        {!loading && clients.last_page > 1 && (
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Página {clients.current_page} de {clients.last_page}
                                </span>
                                <div className="flex gap-2">
                                    {clients.prev_page_url && (
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={clients.prev_page_url}>Anterior</Link>
                                        </Button>
                                    )}
                                    {clients.next_page_url && (
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={clients.next_page_url}>Siguiente</Link>
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </>
                )}
            </InertiaPageShell>

            {/* ── Delete dialog ────────────────────────────────────────── */}
            <AlertDialog
                open={deleteTarget !== null}
                onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>¿Eliminar cliente?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Se eliminará a{" "}
                            <strong>{deleteTarget?.business_name || deleteTarget?.name}</strong>.
                            Esta acción no se puede deshacer.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={handleDelete}
                        >
                            Eliminar
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* ── Cancel account dialog ────────────────────────────────── */}
            <AlertDialog
                open={cancelTarget !== null}
                onOpenChange={(open) => { if (!open) setCancelTarget(null); }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>¿Cancelar cuenta?</AlertDialogTitle>
                        <AlertDialogDescription>
                            La cuenta de{" "}
                            <strong>{cancelTarget?.business_name || cancelTarget?.name}</strong>{" "}
                            quedará inactiva. Los datos del cliente se conservan y la cuenta puede
                            reactivarse en cualquier momento.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>No cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-orange-500 text-white hover:bg-orange-600"
                            onClick={handleCancel}
                        >
                            Sí, cancelar cuenta
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* ── Reactivate dialog ────────────────────────────────────── */}
            <AlertDialog
                open={reactivateTarget !== null}
                onOpenChange={(open) => { if (!open) setReactivateTarget(null); }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>¿Reactivar cuenta?</AlertDialogTitle>
                        <AlertDialogDescription>
                            La cuenta de{" "}
                            <strong>{reactivateTarget?.business_name || reactivateTarget?.name}</strong>{" "}
                            volverá a estar activa y el cliente podrá acceder al sistema.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-green-600 text-white hover:bg-green-700"
                            onClick={handleReactivate}
                        >
                            Reactivar
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
