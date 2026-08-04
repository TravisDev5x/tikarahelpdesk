import { useEffect, useState, useCallback, useMemo, memo } from "react";
import { Head, Link as InertiaLink, router, usePage } from "@inertiajs/react";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle, CardDescription, CardFooter } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { TicketCreateDialog } from "@/components/tickets/TicketCreateDialog";
import { TicketReviewDialog } from "@/components/tickets/TicketReviewDialog";
import { Skeleton } from "@/components/ui/skeleton";
import { notify } from "@/lib/notify";
import { clearCatalogCache } from "@/lib/catalogCache";
import { cn } from "@/lib/utils";
import { TicketPriorityBadge, TicketStateBadge } from "@/components/badges/EntityBadges";
import { KpiCard } from "@/components/dashboard/KpiCard";
import {
    kpiCardSurface,
    noticeWarningRow,
    noticeWarningBtnOutline,
    hintWarning,
    unassignedRowMutedPl,
} from "@/lib/badgeStyles";
import { chartProgressStyle } from "@/lib/chartColors";

import {
    Loader2, Plus, Filter, Tag, MapPin,
    AlertCircle, Ticket, Flame, XCircle, Clock,
    ChevronLeft, ChevronRight, Search, X, User, Building2, BarChart3,
    ArrowRightCircle
} from "lucide-react";

const RESOLVE_BASE = "/resolbeb";

const TicketRow = memo(function TicketRow({ ticket, onReview }) {
    const needsAttention = !ticket.assigned_user && !ticket.assignedUser;
    const isOverdue = Boolean(ticket.is_overdue);

    return (
        <TableRow className={cn(
            "group hover:bg-muted/40 transition-colors border-b border-border/50",
            isOverdue && "bg-destructive/5 hover:bg-destructive/10 border-l-2 border-l-destructive"
        )}>
            <TableCell className="w-[80px]">
                <div className="font-mono text-xs font-bold text-primary/80 bg-primary/5 py-1 px-2 rounded text-center">
                    #{String(ticket.id).padStart(5, '0')}
                </div>
            </TableCell>
            <TableCell className="max-w-[300px]">
                <div className="flex flex-col gap-1.5">
                    <span className="font-semibold text-sm text-foreground truncate pr-4" title={ticket.subject}>
                        {ticket.subject}
                    </span>
                    <div className="flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground">
                        <Badge variant="outline" className="text-[10px] h-4 px-1.5 font-normal rounded-sm border-muted-foreground/30">
                            {ticket.ticket_type?.name}
                        </Badge>
                        <span className="flex items-center gap-1">
                            <Clock className="w-3 h-3" /> {new Date(ticket.created_at).toLocaleDateString()}
                        </span>
                    </div>
                </div>
            </TableCell>
            <TableCell>
                <TicketStateBadge state={ticket.state} />
            </TableCell>
            <TableCell className="text-center">
                <TicketPriorityBadge priority={ticket.priority} />
            </TableCell>
            <TableCell>
                <div className="flex flex-col text-xs gap-0.5">
                    {ticket.assigned_user || ticket.assignedUser ? (
                        <div className="flex items-center gap-1.5">
                            <div className="w-5 h-5 rounded-full bg-primary/10 flex items-center justify-center text-primary text-[10px] font-bold">
                                {(ticket.assigned_user?.name || ticket.assignedUser?.name || "?").charAt(0)}
                            </div>
                            <span className="font-medium text-foreground/90 truncate max-w-[120px]">
                                {ticket.assigned_user?.name || ticket.assignedUser?.name}
                            </span>
                        </div>
                    ) : (
                        <span className={unassignedRowMutedPl}>
                            <AlertCircle className={cn("w-3 h-3", hintWarning)} /> Sin asignar
                        </span>
                    )}
                </div>
            </TableCell>
            <TableCell className="text-xs">
                {ticket.sla_status_text ? (
                    <span className={ticket.is_overdue ? "text-destructive font-medium" : "text-muted-foreground"}>
                        {ticket.sla_status_text}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell>
                <div className="flex flex-col text-xs gap-1">
                    <span className="font-medium flex items-center gap-1.5 text-foreground/80">
                        <Building2 className="w-3 h-3 text-muted-foreground" /> {ticket.site?.name}
                    </span>
                    <span className="text-muted-foreground pl-5 truncate max-w-[140px]" title={ticket.area_current?.name}>
                        {ticket.area_current?.name}
                    </span>
                </div>
            </TableCell>
            <TableCell className="text-right pr-4">
                <div className="flex items-center justify-end gap-1.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 text-xs"
                        onClick={() => onReview?.(ticket)}
                    >
                        Revisar
                    </Button>
                    <Button
                        asChild
                        variant={needsAttention ? "default" : "secondary"}
                        size="sm"
                        className={`
                            h-8 text-xs font-semibold shadow-sm transition-all
                            ${needsAttention
                            ? "bg-primary hover:bg-primary/90 text-primary-foreground"
                            : "bg-secondary/50 hover:bg-primary hover:text-primary-foreground border border-border/50"}
                        `}
                    >
                        <InertiaLink href={`${RESOLVE_BASE}/tickets/${ticket.id}`} className="flex items-center gap-2">
                            <span>Gestionar</span>
                            <ArrowRightCircle className="w-3.5 h-3.5 opacity-70" />
                        </InertiaLink>
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
});

const SummarySkeleton = () => (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, idx) => (
            <Skeleton key={idx} className="h-24 w-full rounded-xl" />
        ))}
    </div>
);

export default function ResolbebIndex({ mode = "tickets", catalogs: catalogsProp = {} }) {
    const { user, can } = useAuth();
    const { url } = usePage();
    const catalogs = useMemo(() => ({
        areas: [],
        sites: [],
        locations: [],
        priorities: [],
        ticket_states: [],
        ticket_types: [],
        area_users: [],
        clients: [],
        ...catalogsProp,
    }), [catalogsProp]);
    const [tickets, setTickets] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [open, setOpen] = useState(false);
    const [reviewTicket, setReviewTicket] = useState(null);
    const rejectedStateId = useMemo(
        () => catalogs.ticket_states?.find((s) => (s.code || "").toLowerCase() === "rechazado")?.id,
        [catalogs.ticket_states]
    );
    const [summary, setSummary] = useState(null);
    const [summaryLoading, setSummaryLoading] = useState(true);
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [perPage, setPerPage] = useState(() => Number(localStorage.getItem("resolvev1.tickets.perPage")) || 10);
    const [currentPage, setCurrentPage] = useState(1);
    const [lastUpdated, setLastUpdated] = useState(null);
    const defaultFilters = { client: "all", area: "all", site: "all", type: "all", priority: "all", state: "all", search: "", assignment: "all", assignee: "all", sla: "all" };
    const [filters, setFilters] = useState(() => {
        const saved = localStorage.getItem("resolvev1.tickets.filters");
        return saved ? { ...defaultFilters, ...JSON.parse(saved) } : defaultFilters;
    });
    useEffect(() => {
        const qs = url.includes("?") ? url.split("?")[1] : "";
        const assignmentFromUrl = new URLSearchParams(qs).get("assignment");
        if (assignmentFromUrl === "me" || assignmentFromUrl === "unassigned" || assignmentFromUrl === "user") {
            setFilters((prev) => (prev.assignment === assignmentFromUrl ? prev : { ...prev, assignment: assignmentFromUrl }));
        }
    }, [url]);

    const isMyTicketsPage = mode === "mis-tickets";

    const canManageAll = can("tickets.manage_all");
    const canCreate = can("tickets.create") || canManageAll;
    const canViewArea = can("tickets.view_area") || canManageAll;
    const needsAreaWarning = canViewArea && !canManageAll && !user?.area_id;
    const canAssign = can("tickets.assign") || canManageAll;
    const isSolicitanteOnly = !canManageAll && !canViewArea && (can("tickets.create") || can("tickets.view_own"));
    const areaUsers = catalogs.area_users || [];
    const canUseAssignmentFilters = canViewArea || canAssign;
    const userClientId = user?.client_id ? String(user.client_id) : null;
    const canFilterByClient = canManageAll;
    const clients = catalogs.clients || [];

    const filteredSites = useMemo(() => {
        const sites = catalogs.sites || [];
        if (filters.client === "all") return sites;
        return sites.filter((s) => String(s.client_id) === String(filters.client));
    }, [catalogs.sites, filters.client]);

    useEffect(() => {
        if (!canManageAll && userClientId && filters.client !== userClientId) {
            setFilters((prev) => ({ ...prev, client: userClientId, site: "all" }));
        }
    }, [canManageAll, userClientId, filters.client]);

    const [form, setForm] = useState({
        subject: "", description: "", area_origin_id: "", area_current_id: "",
        site_id: "", location_id: "none", ticket_type_id: "", priority_id: "", ticket_state_id: ""
    });

    const loadData = useCallback(async () => {
        setLoading(true);
        setSummaryLoading(true);
        try {
            const params = { page: currentPage, per_page: perPage, search: filters.search };
            if (filters.client !== "all") params.client_id = filters.client;
            else if (!canManageAll && userClientId) params.client_id = userClientId;
            if (canManageAll) {
                if (filters.area !== "all") params.area_current_id = filters.area;
                if (filters.site !== "all") params.site_id = filters.site;
                if (filters.type !== "all") params.ticket_type_id = filters.type;
                if (filters.priority !== "all") params.priority_id = filters.priority;
                if (filters.state !== "all") params.ticket_state_id = filters.state;
                if (filters.sla !== "all" && filters.sla) params.sla = filters.sla;
                if (filters.assignment === "me") params.assigned_to = "me";
                if (filters.assignment === "unassigned") params.assigned_status = "unassigned";
                if (filters.assignment === "user" && filters.assignee !== "all") params.assigned_user_id = filters.assignee;
            } else {
                if (filters.site !== "all") params.site_id = filters.site;
                if (filters.type !== "all") params.ticket_type_id = filters.type;
            }
            if (canViewArea && !canManageAll && user?.area_id) params.area_current_id = user.area_id;
            if (!canViewArea && !canManageAll) params.created_by = "me";
            if (isMyTicketsPage) params.created_by = "me";

            const summaryParams = { ...params };
            delete summaryParams.page;
            delete summaryParams.per_page;

            const [ticketResult, summaryResult] = await Promise.allSettled([
                axios.get("/api/tickets", { params }),
                axios.get("/api/tickets/summary", { params: summaryParams }),
            ]);

            if (ticketResult.status === "fulfilled") {
                setTickets(ticketResult.value.data.data);
                setPagination({
                    current_page: ticketResult.value.data.current_page,
                    last_page: ticketResult.value.data.last_page,
                    total: ticketResult.value.data.total
                });
            } else {
                notify.error({ title: "Error", description: "No se pudieron cargar los tickets." });
            }
            if (summaryResult.status === "fulfilled") {
                setSummary(summaryResult.value.data);
            } else {
                setSummary(null);
            }
        } catch (err) {
            console.error(err);
            notify.error({ title: "Error crítico", description: "Fallo de conexión." });
        } finally {
            setLoading(false);
            setSummaryLoading(false);
            setLastUpdated(new Date());
        }
    }, [currentPage, perPage, filters, user, canManageAll, canViewArea, isMyTicketsPage]);

    useEffect(() => { loadData(); }, [loadData]);
    useEffect(() => {
        localStorage.setItem("resolvev1.tickets.filters", JSON.stringify(filters));
        localStorage.setItem("resolvev1.tickets.perPage", String(perPage));
    }, [filters, perPage]);

    const handleCreateOpen = () => {
        const openState = catalogs.ticket_states?.find((s) => (s.code || "").toLowerCase() === "abierto") || catalogs.ticket_states?.[0];
        setForm({
            ...form,
            subject: "", description: "",
            site_id: String(user?.site_id || user?.site?.id || ""),
            area_origin_id: String(user?.area_id || ""),
            area_current_id: String(user?.area_id || ""),
            ticket_type_id: String(catalogs.ticket_types?.[0]?.id || ""),
            priority_id: String(catalogs.priorities?.[0]?.id || ""),
            ticket_state_id: String(openState?.id ?? ""),
            location_id: form.location_id || "none",
        });
        setOpen(true);
    };

    const ticketSiteContext = useMemo(() => {
        const siteId = user?.site_id || user?.site?.id;
        if (!siteId) return null;
        const site = (catalogs.sites || []).find((s) => String(s.id) === String(siteId));
        const client = (catalogs.clients || []).find((c) => String(c.id) === String(user?.client_id || site?.client_id));
        return {
            siteName: user?.site?.name || site?.name || "Tu sede",
            clientName: user?.client_name || client?.name || null,
        };
    }, [user, catalogs.sites, catalogs.clients]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!ticketSiteContext) {
            notify.error("Tu usuario no tiene sede asignada. No puedes crear tickets hasta que un administrador te la asigne.");
            return;
        }
        if (!form.area_current_id || !form.ticket_type_id || !form.priority_id || !form.ticket_state_id) {
            notify.error("Completa todos los campos obligatorios (área responsable, área origen, tipo, prioridad).");
            return;
        }
        setSaving(true);
        const payload = {
            subject: (form.subject || "").trim(),
            description: form.description?.trim() || null,
            area_origin_id: Number(form.area_origin_id),
            area_current_id: Number(form.area_current_id),
            priority_id: Number(form.priority_id),
            ticket_type_id: Number(form.ticket_type_id),
            ticket_state_id: Number(form.ticket_state_id),
            location_id: form.location_id === "none" || !form.location_id ? null : Number(form.location_id),
            created_at: new Date().toISOString(),
        };
        const promise = axios.post("/api/tickets", payload);
        notify.promise(promise, {
            loading: "Creando ticket…",
            success: "Ticket creado correctamente",
            error: "Error al crear el ticket",
        });
        try {
            await promise;
            clearCatalogCache();
            setOpen(false);
            setCurrentPage(1);
            loadData();
        } catch (_) {}
        finally { setSaving(false); }
    };

    const handleClearFilters = () => setFilters({
        ...defaultFilters,
        client: !canManageAll && userClientId ? userClientId : "all",
    });
    const hasActiveFilters = canManageAll
        ? (filters.search !== "" || filters.client !== "all" || filters.area !== "all" || filters.site !== "all" || filters.type !== "all" || filters.state !== "all" || filters.priority !== "all" || filters.assignment !== "all" || (filters.assignment === "user" && filters.assignee !== "all") || filters.sla !== "all")
        : (filters.search !== "" || filters.site !== "all" || filters.type !== "all");
    const activeFilterCount = canManageAll
        ? [filters.search, filters.client !== "all", filters.area !== "all", filters.site !== "all", filters.type !== "all", filters.priority !== "all", filters.state !== "all", filters.assignment !== "all", filters.assignment === "user" && filters.assignee !== "all", filters.sla !== "all"].filter(Boolean).length
        : [filters.search, filters.site !== "all", filters.type !== "all"].filter(Boolean).length;

    const [exporting, setExporting] = useState(false);
    const handleExport = useCallback(async () => {
        const params = {};
        if (filters.search) params.search = filters.search;
        if (filters.client !== "all") params.client_id = filters.client;
        else if (!canManageAll && userClientId) params.client_id = userClientId;
        if (filters.area !== "all") params.area_current_id = filters.area;
        if (filters.site !== "all") params.site_id = filters.site;
        if (filters.type !== "all") params.ticket_type_id = filters.type;
        if (filters.priority !== "all") params.priority_id = filters.priority;
        if (filters.state !== "all") params.ticket_state_id = filters.state;
        if (filters.sla !== "all") params.sla = filters.sla;
        if (filters.assignment === "me") params.assigned_to = "me";
        if (filters.assignment === "unassigned") params.assigned_status = "unassigned";
        if (filters.assignment === "user" && filters.assignee !== "all") params.assigned_user_id = filters.assignee;
        setExporting(true);
        try {
            const { data } = await axios.get("/api/tickets/export", { params, responseType: "blob" });
            const url = URL.createObjectURL(data);
            const a = document.createElement("a");
            a.href = url;
            a.download = "tickets.csv";
            a.click();
            URL.revokeObjectURL(url);
        } catch (err) {
            notify.error("No se pudo exportar");
        } finally { setExporting(false); }
    }, [filters]);

    const summaryStates = (summary?.by_state || []).slice().sort((a, b) => Number(b.value || 0) - Number(a.value || 0));
    const summaryMax = summaryStates.length ? Math.max(...summaryStates.map((s) => Number(s.value || 0))) : 0;

    useEffect(() => {
        if (isSolicitanteOnly) {
            router.visit(RESOLVE_BASE, { replace: true });
        }
    }, [isSolicitanteOnly]);

    if (isSolicitanteOnly) return null;

    const pageTitle = isMyTicketsPage ? "Mis tickets" : "Tickets";

    return (
        <AuthenticatedLayout title={pageTitle}>
            <Head title={pageTitle} />
        <div className="w-full space-y-6 animate-in fade-in duration-500">
            {needsAreaWarning && (
                <div className={cn(noticeWarningRow, "justify-between")}>
                    <span className="text-sm font-medium">Tienes permiso por área pero no tienes un área asignada. Asigna tu área para ver y gestionar tickets.</span>
                    <a href="/profile">
                        <Button variant="outline" size="sm" className={noticeWarningBtnOutline}>Ir a mi perfil</Button>
                    </a>
                </div>
            )}

            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2">
                <p className="text-sm text-muted-foreground">
                    {isMyTicketsPage ? "Tus tickets como solicitante. Da seguimiento y agrega comentarios." : "Sistema centralizado de incidencias."}
                </p>
                <div className="flex items-center gap-2">
                    {canManageAll && (
                        <Button variant="outline" size="sm" onClick={handleExport} disabled={loading || exporting} title="Exportar CSV con filtros actuales">
                            {exporting ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                            Exportar CSV
                        </Button>
                    )}
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="icon" className="h-9 w-9 shrink-0" onClick={loadData} disabled={loading} title="Actualizar">
                            <Loader2 className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                        </Button>
                        {lastUpdated && !loading && (
                            <span className="text-xs text-muted-foreground whitespace-nowrap" title={lastUpdated.toLocaleString()}>
                                Actualizado {lastUpdated.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
                            </span>
                        )}
                    </div>
                    {canCreate && (
                        <Button onClick={handleCreateOpen} className="shadow-sm">
                            <Plus className="mr-2 h-4 w-4" /> Nuevo Ticket
                        </Button>
                    )}
                </div>
            </div>

            <div className="w-full">
                {summaryLoading ? (
                    <SummarySkeleton />
                ) : summary ? (
                    <div className={cn("grid grid-cols-1 gap-4", canManageAll ? "sm:grid-cols-2 lg:grid-cols-4" : "sm:grid-cols-3")}>
                        <KpiCard title="Total Tickets" value={summary.total ?? 0} icon={Ticket} variant="blue" />
                        <KpiCard title="Críticos/Quemados" value={summary.burned ?? 0} icon={Flame} variant="red" hint="Sin atención > 72h" />
                        <KpiCard title="Cancelados" value={summary.canceled ?? 0} icon={XCircle} variant="slate" />
                        {canManageAll && (
                            <Card className={cn("border shadow-sm sm:col-span-2 lg:col-span-1 flex flex-col justify-center", kpiCardSurface.accent.card)}>
                                <CardContent className="p-4 py-3">
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-[10px] uppercase font-bold text-foreground flex items-center gap-1">
                                            <BarChart3 className="w-3 h-3" /> Top Estados
                                        </span>
                                    </div>
                                    <div className="space-y-2">
                                        {summaryStates.slice(0, 3).map((state, stateIdx) => {
                                            const value = Number(state.value || 0);
                                            const pct = summaryMax ? Math.round((value / summaryMax) * 100) : 0;
                                            return (
                                                <div key={state.id} className="space-y-1">
                                                    <div className="flex justify-between text-[10px]">
                                                        <span className="truncate max-w-[100px] text-foreground/80 font-medium">{state.label}</span>
                                                        <span className="font-mono text-muted-foreground">{value}</span>
                                                    </div>
                                                    <div className="h-1.5 bg-muted rounded-full overflow-hidden">
                                                        <div className="h-full" style={chartProgressStyle(pct, stateIdx % 8)} />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                        {summaryStates.length === 0 && <span className="text-[10px] text-muted-foreground">Sin datos</span>}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                ) : (
                    <div className="p-4 border border-dashed rounded-lg text-center text-sm text-muted-foreground">No hay métricas disponibles</div>
                )}
            </div>

            <div className="space-y-4">
                <Card className="border border-border/50 shadow-sm bg-card/60 backdrop-blur-sm">
                    <CardContent className="p-4">
                        <div className="flex flex-col gap-4">
                            <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                                <div className="relative flex-1">
                                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Buscar por ID, asunto, descripción..."
                                        className="pl-9 bg-background"
                                        value={filters.search}
                                        onChange={(e) => setFilters(f => ({ ...f, search: e.target.value }))}
                                    />
                                </div>
                                <div className="flex items-center gap-2 self-end sm:self-auto">
                                    {hasActiveFilters && (
                                        <Button variant="ghost" size="sm" onClick={handleClearFilters} className="h-9 text-xs text-destructive hover:bg-destructive/10 px-2">
                                            <X className="h-3.5 w-3.5 mr-1" /> Limpiar
                                        </Button>
                                    )}
                                    <Badge variant={activeFilterCount > 0 ? "secondary" : "outline"} className="h-9 px-3">
                                        <Filter className="w-3 h-3 mr-1" />
                                        {activeFilterCount > 0 ? `${activeFilterCount} filtros` : "Filtros"}
                                    </Badge>
                                </div>
                            </div>
                            <div role="separator" className="shrink-0 h-px w-full bg-border/40" />
                            <div className={cn("grid gap-3", canManageAll ? "grid-cols-2 md:grid-cols-3 lg:grid-cols-6" : "grid-cols-2 md:grid-cols-3")}>
                                {canFilterByClient && (
                                    <Select
                                        value={filters.client}
                                        onValueChange={(v) => setFilters((f) => ({ ...f, client: v, site: "all" }))}
                                    >
                                        <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Cliente" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Todos los clientes</SelectItem>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {!canFilterByClient && user?.client_name ? (
                                    <div className="flex h-8 items-center rounded-md border border-border/60 bg-muted/30 px-3 text-xs text-muted-foreground col-span-2 md:col-span-1">
                                        Cliente: <span className="ml-1 font-medium text-foreground">{user.client_name}</span>
                                    </div>
                                ) : null}
                                {canManageAll && (
                                    <>
                                        <Select value={filters.state} onValueChange={(v) => setFilters(f => ({ ...f, state: v }))}>
                                            <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Estado" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Todos los estados</SelectItem>
                                                {catalogs.ticket_states.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                        <Select value={filters.priority} onValueChange={(v) => setFilters(f => ({ ...f, priority: v }))}>
                                            <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Prioridad" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Todas las prioridades</SelectItem>
                                                {catalogs.priorities.map(p => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                    </>
                                )}
                                <Select value={filters.type} onValueChange={(v) => setFilters(f => ({ ...f, type: v }))}>
                                    <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Tipo" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos los tipos</SelectItem>
                                        {catalogs.ticket_types.map(t => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={filters.site} onValueChange={(v) => setFilters(f => ({ ...f, site: v }))}>
                                    <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Sede" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todas las sedes</SelectItem>
                                        {filteredSites.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                {canManageAll && (
                                    <>
                                        {canViewArea && (
                                            <Select value={filters.area} onValueChange={(v) => setFilters(f => ({ ...f, area: v }))}>
                                                <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Área" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">Todas las áreas</SelectItem>
                                                    {catalogs.areas.map(a => <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                        )}
                                        {canUseAssignmentFilters && (
                                            <Select value={filters.assignment} onValueChange={(v) => setFilters(f => ({ ...f, assignment: v, assignee: v === "user" ? f.assignee : "all" }))}>
                                                <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Asignación" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">Todos</SelectItem>
                                                    <SelectItem value="me">Mis tickets</SelectItem>
                                                    <SelectItem value="unassigned">Sin asignar</SelectItem>
                                                    <SelectItem value="user">Por usuario...</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        )}
                                        <Select value={filters.sla} onValueChange={(v) => setFilters(f => ({ ...f, sla: v }))}>
                                            <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="SLA" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Cualquier SLA</SelectItem>
                                                <SelectItem value="within">Dentro de plazo</SelectItem>
                                                <SelectItem value="overdue">Vencidos</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card className="border border-border/50 shadow-sm overflow-hidden bg-card">
                    <div className="p-0">
                        <Table>
                            <TableHeader className="bg-muted/40">
                                <TableRow className="border-b border-border/50 hover:bg-transparent">
                                    <TableHead className="w-[80px] font-bold text-[11px] uppercase tracking-wider text-muted-foreground text-center">Folio</TableHead>
                                    <TableHead className="min-w-[280px] font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Detalle del Ticket</TableHead>
                                    <TableHead className="w-[140px] font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Estado</TableHead>
                                    <TableHead className="w-[100px] font-bold text-[11px] uppercase tracking-wider text-muted-foreground text-center">Prioridad</TableHead>
                                    <TableHead className="min-w-[150px] font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Asignado a</TableHead>
                                    <TableHead className="min-w-[120px] font-bold text-[11px] uppercase tracking-wider text-muted-foreground">SLA</TableHead>
                                    <TableHead className="min-w-[180px] font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Ubicación</TableHead>
                                    <TableHead className="w-[120px] text-right font-bold text-[11px] uppercase tracking-wider text-muted-foreground pr-6">Acciones</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading ? (
                                    Array.from({ length: 5 }).map((_, i) => (
                                        <TableRow key={i}>
                                            <TableCell colSpan={8} className="h-16 px-4">
                                                <div className="flex items-center gap-4">
                                                    <Skeleton className="h-8 w-12" />
                                                    <div className="flex-1 space-y-2">
                                                        <Skeleton className="h-4 w-3/4" />
                                                        <Skeleton className="h-3 w-1/4" />
                                                    </div>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : tickets.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="h-72 py-12 text-center">
                                            <div className="flex flex-col items-center justify-center gap-4 text-muted-foreground">
                                                <div className="p-5 bg-muted/30 rounded-2xl">
                                                    <Ticket className="w-12 h-12 opacity-50" />
                                                </div>
                                                <div>
                                                    <p className="font-semibold text-foreground/90">No hay tickets</p>
                                                    <p className="text-sm mt-1 max-w-sm text-center">
                                                        {hasActiveFilters ? "Ningún ticket coincide con los filtros. Prueba a limpiar filtros o ampliar la búsqueda." : "Aún no hay tickets registrados. Crea el primero para comenzar."}
                                                    </p>
                                                </div>
                                                {canCreate && !hasActiveFilters && (
                                                    <Button onClick={handleCreateOpen} size="sm" className="mt-2">
                                                        <Plus className="w-4 h-4 mr-2" /> Crear ticket
                                                    </Button>
                                                )}
                                                {hasActiveFilters && (
                                                    <Button variant="outline" size="sm" onClick={handleClearFilters} className="mt-2">
                                                        <X className="w-4 h-4 mr-2" /> Limpiar filtros
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    tickets.map((t) => <TicketRow key={t.id} ticket={t} onReview={setReviewTicket} />)
                                )}
                            </TableBody>
                        </Table>
                    </div>
                    <div className="border-t border-border/50 px-4 py-3 bg-muted/20 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <p className="text-xs text-muted-foreground">
                            {pagination.total === 0 ? "Sin resultados" : (
                                <>Mostrando <span className="font-medium text-foreground">{(pagination.current_page - 1) * perPage + 1}–{Math.min(pagination.current_page * perPage, pagination.total)}</span> de <span className="font-medium text-foreground">{pagination.total}</span> tickets</>
                            )}
                        </p>
                        <div className="flex items-center gap-2">
                            <Select value={String(perPage)} onValueChange={(v) => { setPerPage(Number(v)); setCurrentPage(1); }}>
                                <SelectTrigger className="w-16 h-8 text-xs bg-background"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {[10, 25, 50, 100].map(n => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            <div className="flex items-center border rounded-md bg-background shadow-sm">
                                <Button variant="ghost" size="icon" className="h-8 w-8 rounded-r-none border-r" onClick={() => setCurrentPage(p => Math.max(1, p - 1))} disabled={currentPage === 1 || loading}>
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <span className="text-xs font-medium w-24 text-center">{currentPage} / {pagination.last_page}</span>
                                <Button variant="ghost" size="icon" className="h-8 w-8 rounded-l-none border-l" onClick={() => setCurrentPage(p => Math.min(pagination.last_page, p + 1))} disabled={currentPage === pagination.last_page || loading}>
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <TicketCreateDialog
                open={open}
                onOpenChange={setOpen}
                form={form}
                setForm={setForm}
                catalogs={catalogs}
                saving={saving}
                onSubmit={handleSubmit}
                siteContext={ticketSiteContext}
            />

            <TicketReviewDialog
                open={Boolean(reviewTicket)}
                onClose={() => setReviewTicket(null)}
                ticket={reviewTicket}
                rejectedStateId={rejectedStateId}
                onReviewed={loadData}
            />
        </div>
        </AuthenticatedLayout>
    );
}
