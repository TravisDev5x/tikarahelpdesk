import { useCallback, useEffect, useMemo, useState } from "react";
import { formatDistanceToNow } from "date-fns";
import { es } from "date-fns/locale";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import InertiaPageShell from "@/Inertia/components/InertiaPageShell";
import AuditTimeline from "@/components/audit/AuditTimeline";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { notify } from "@/lib/notify";
import { getApiErrorMessage } from "@/lib/apiErrors";
import { Download, Loader2, Search, ShieldAlert, Ticket } from "lucide-react";
import { cn } from "@/lib/utils";

const PER_PAGE = 20;

const BOUNDARY_EVENT_LABELS = {
    login_rejected: { label: "Login rechazado", variant: "destructive" },
    access_blocked: { label: "Acceso bloqueado", variant: "outline" },
};

// ------------------------------------------------------------------
// VISTA MÓVIL: CARD POR EVENTO (solo visible en viewport < md)
// ------------------------------------------------------------------
function BoundaryEventCard({ event }) {
    const meta = BOUNDARY_EVENT_LABELS[event.action] ?? { label: event.action, variant: "secondary" };

    return (
        <Card className="overflow-hidden">
            <CardContent className="p-4 flex flex-col gap-2">
                <div className="flex items-start justify-between gap-2">
                    <Badge variant={meta.variant}>{meta.label}</Badge>
                    <span className="text-xs text-muted-foreground shrink-0">
                        {event.created_at
                            ? formatDistanceToNow(new Date(event.created_at), { addSuffix: true, locale: es })
                            : "—"}
                    </span>
                </div>
                <p className="text-sm truncate">
                    {event.user ? (event.user.name || event.user.email) : "—"}
                    {event.client?.name ? ` → ${event.client.name}` : ""}
                </p>
                <p className="text-xs text-muted-foreground">IP: {event.ip_address ?? "—"}</p>
                <p className="text-xs text-muted-foreground truncate">
                    {event.new_values?.host ?? "—"}
                    {event.new_values?.path ? ` /${event.new_values.path}` : ""}
                </p>
            </CardContent>
        </Card>
    );
}

function TenantBoundaryEventsTab() {
    const [events, setEvents] = useState([]);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get("/api/security/tenant-boundary-events");
            setEvents(Array.isArray(data.data) ? data.data : []);
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudieron cargar los eventos de seguridad"));
            setEvents([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-base">Accesos cross-tenant rechazados</CardTitle>
                <CardDescription>
                    Intentos de login o acceso a un portal de cliente que no corresponde al usuario
                    ({events.length} evento{events.length !== 1 ? "s" : ""})
                </CardDescription>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <div className="space-y-2">
                        {[1, 2, 3, 4].map((i) => (
                            <Skeleton key={i} className="h-10 w-full" />
                        ))}
                    </div>
                ) : events.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 py-16 text-center text-sm text-muted-foreground">
                        <ShieldAlert className="h-8 w-8 opacity-40" />
                        Sin eventos registrados
                    </div>
                ) : (
                    <>
                        {/* VISTA MÓVIL: CARDS */}
                        <div className="block md:hidden space-y-3">
                            {events.map((ev) => (
                                <BoundaryEventCard key={ev.id} event={ev} />
                            ))}
                        </div>

                        {/* TABLA DESKTOP */}
                        <div className="hidden md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Fecha</TableHead>
                                        <TableHead>Evento</TableHead>
                                        <TableHead>Usuario</TableHead>
                                        <TableHead>Cliente objetivo</TableHead>
                                        <TableHead>IP</TableHead>
                                        <TableHead>Detalle</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {events.map((ev) => {
                                        const meta = BOUNDARY_EVENT_LABELS[ev.action] ?? { label: ev.action, variant: "secondary" };
                                        return (
                                            <TableRow key={ev.id}>
                                                <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                                                    {ev.created_at
                                                        ? formatDistanceToNow(new Date(ev.created_at), { addSuffix: true, locale: es })
                                                        : "—"}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={meta.variant}>{meta.label}</Badge>
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {ev.user ? (ev.user.name || ev.user.email) : "—"}
                                                </TableCell>
                                                <TableCell className="text-sm">{ev.client?.name ?? "—"}</TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    {ev.ip_address ?? "—"}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    {ev.new_values?.host ?? "—"}
                                                    {ev.new_values?.path ? ` /${ev.new_values.path}` : ""}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function subjectFromLogs(logs) {
    for (const log of logs) {
        const sub = log.new_values?.subject ?? log.old_values?.subject;
        if (sub) return String(sub);
    }
    return "Sin asunto";
}

function lastActionLabel(log) {
    const map = { created: "Creado", updated: "Actualizado", deleted: "Eliminado", restored: "Restaurado" };
    return map[log.action] ?? log.action ?? "Actividad";
}

export default function AuditCommandCenter() {
    const [auditLogs, setAuditLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [selectedTicket, setSelectedTicket] = useState(null);
    const [exportLoading, setExportLoading] = useState(false);

    const loadLogs = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get("/api/tickets/audit-logs");
            setAuditLogs(Array.isArray(data.data) ? data.data : []);
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudieron cargar los logs"));
            setAuditLogs([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadLogs();
    }, [loadLogs]);

    const ticketsIndex = useMemo(() => {
        const map = new Map();
        auditLogs.forEach((log) => {
            const tid = log.ticket_id ?? log.auditable_id;
            if (!tid) return;
            if (!map.has(tid)) {
                map.set(tid, { id: tid, logs: [], subject: "", lastAt: null, lastAction: "" });
            }
            const entry = map.get(tid);
            entry.logs.push(log);
            const sub = log.new_values?.subject ?? log.old_values?.subject;
            if (sub && !entry.subject) entry.subject = String(sub);
            const at = log.created_at ? new Date(log.created_at) : null;
            if (at && (!entry.lastAt || at > entry.lastAt)) {
                entry.lastAt = at;
                entry.lastAction = lastActionLabel(log);
            }
        });
        return [...map.values()].sort((a, b) => (b.lastAt ?? 0) - (a.lastAt ?? 0));
    }, [auditLogs]);

    const filteredTickets = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return ticketsIndex;
        return ticketsIndex.filter((t) => {
            const idMatch = String(t.id).includes(q);
            const subMatch = (t.subject || "").toLowerCase().includes(q);
            return idMatch || subMatch;
        });
    }, [ticketsIndex, search]);

    useEffect(() => {
        setPage(1);
    }, [search]);

    const totalPages = Math.max(1, Math.ceil(filteredTickets.length / PER_PAGE));
    const currentPage = Math.min(page, totalPages);
    const paginatedTickets = filteredTickets.slice(
        (currentPage - 1) * PER_PAGE,
        currentPage * PER_PAGE
    );

    const logsForSelected = useMemo(() => {
        if (!selectedTicket) return [];
        return auditLogs.filter(
            (l) => (l.ticket_id ?? l.auditable_id) === selectedTicket.id
        );
    }, [auditLogs, selectedTicket]);

    const handleExport = async () => {
        if (!selectedTicket) return;
        setExportLoading(true);
        try {
            const params = new URLSearchParams();
            params.set("ticket_ids", String(selectedTicket.id));
            const response = await axios.get(
                `/api/tickets/audit-export?${params.toString()}`,
                { responseType: "blob" }
            );
            const disposition = response.headers["content-disposition"];
            const match = disposition && disposition.match(/filename="?([^";]+)"?/);
            const filename =
                match?.[1] ?? `auditoria_ticket_${selectedTicket.id}_${new Date().toISOString().slice(0, 10)}.xlsx`;
            const blob = new Blob([response.data], {
                type:
                    response.headers["content-type"] ||
                    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            });
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.setAttribute("download", filename);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            notify.success("Reporte descargado");
        } catch {
            notify.error("No se pudo descargar el Excel");
        } finally {
            setExportLoading(false);
        }
    };

    return (
        <InertiaPageShell className="space-y-6">
            <Tabs defaultValue="tickets" className="space-y-4">
                <TabsList>
                    <TabsTrigger value="tickets">Tickets</TabsTrigger>
                    <TabsTrigger value="security">Seguridad</TabsTrigger>
                </TabsList>

                <TabsContent value="tickets" className="space-y-4">
            <p className="text-sm text-muted-foreground">
                Selecciona un ticket para ver su línea de tiempo de cambios
            </p>

            <div className="grid min-h-[480px] grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="flex flex-col lg:col-span-1">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Tickets con actividad</CardTitle>
                        <CardDescription>
                            {filteredTickets.length} ticket
                            {filteredTickets.length !== 1 ? "s" : ""}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-1 flex-col gap-3">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Buscar por ID o asunto…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-9"
                            />
                        </div>

                        <div className="flex-1 overflow-y-auto min-h-[280px]">
                            {loading ? (
                                <div className="space-y-2">
                                    {[1, 2, 3, 4, 5].map((i) => (
                                        <Skeleton key={i} className="h-14 w-full" />
                                    ))}
                                </div>
                            ) : paginatedTickets.length === 0 ? (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    No hay tickets con actividad registrada
                                </p>
                            ) : (
                                <ul className="space-y-1">
                                    {paginatedTickets.map((t) => {
                                        const subject =
                                            t.subject || subjectFromLogs(t.logs);
                                        const active = selectedTicket?.id === t.id;
                                        return (
                                            <li key={t.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedTicket(t)}
                                                    className={cn(
                                                        "w-full rounded-md px-3 py-2.5 text-left text-sm transition-colors",
                                                        active
                                                            ? "bg-primary text-primary-foreground"
                                                            : "hover:bg-muted"
                                                    )}
                                                >
                                                    <div className="flex items-start gap-2">
                                                        <Ticket className="mt-0.5 h-4 w-4 shrink-0" />
                                                        <div className="min-w-0 flex-1">
                                                            <div className="font-medium truncate">
                                                                #{String(t.id).padStart(5, "0")} —{" "}
                                                                {subject}
                                                            </div>
                                                            <div
                                                                className={cn(
                                                                    "text-xs truncate",
                                                                    active
                                                                        ? "text-primary-foreground/80"
                                                                        : "text-muted-foreground"
                                                                )}
                                                            >
                                                                {t.lastAction}
                                                                {t.lastAt &&
                                                                    ` · ${formatDistanceToNow(t.lastAt, { addSuffix: true, locale: es })}`}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>

                        {!loading && filteredTickets.length > PER_PAGE && (
                            <div className="flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={currentPage <= 1}
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                >
                                    Anterior
                                </Button>
                                <span>
                                    {currentPage} / {totalPages}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={currentPage >= totalPages}
                                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                                >
                                    Siguiente
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="flex flex-col lg:col-span-2 min-h-[300px]">
                    {!selectedTicket ? (
                        <Card className="flex flex-1 items-center justify-center">
                            <CardContent className="py-16 text-center text-sm text-muted-foreground">
                                Selecciona un ticket para ver su línea de tiempo
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="flex flex-col gap-4 h-full">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">
                                        Ticket #{String(selectedTicket.id).padStart(5, "0")} —{" "}
                                        {selectedTicket.subject ||
                                            subjectFromLogs(selectedTicket.logs)}
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        {logsForSelected.length} evento
                                        {logsForSelected.length !== 1 ? "s" : ""}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={handleExport}
                                    disabled={exportLoading}
                                >
                                    {exportLoading ? (
                                        <Loader2 className="h-4 w-4 animate-spin mr-2" />
                                    ) : (
                                        <Download className="h-4 w-4 mr-2" />
                                    )}
                                    Exportar Excel
                                </Button>
                            </div>
                            <AuditTimeline logs={logsForSelected} />
                        </div>
                    )}
                </div>
            </div>
                </TabsContent>

                <TabsContent value="security">
                    <TenantBoundaryEventsTab />
                </TabsContent>
            </Tabs>
        </InertiaPageShell>
    );
}

AuditCommandCenter.layout = (page) => (
    <AuthenticatedLayout title="Centro de auditoría">{page}</AuthenticatedLayout>
);
