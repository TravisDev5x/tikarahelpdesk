import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import NavLink from "@/components/NavLink";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import { loadCatalogs, clearCatalogCache } from "@/lib/catalogCache";
import { notify } from "@/lib/notify";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { DatePickerField } from "@/components/date-picker-field";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { UserAvatar } from "@/components/user-avatar";
import { DashboardStackedBar } from "@/components/dashboard/DashboardStackedBar";
import { ChartErrorBoundary } from "@/components/dashboard/ChartErrorBoundary";
import { TinyVerticalBarChart } from "@/components/dashboard/TinyVerticalBarChart";
import { DashboardOperativo } from "@/components/dashboard/DashboardOperativo";
import { TicketCreateDialog } from "@/components/tickets/TicketCreateDialog";
import { getDashboardProfile } from "@/lib/dashboardProfile";
import { chartColor } from "@/lib/chartColors";
import {
    metricCardVariant,
    noticeWarningRow,
    hintWarning,
} from "@/lib/badgeStyles";
import { cn } from "@/lib/utils";
import {
    Activity,
    AlertTriangle,
    Download,
    Filter,
    Flame,
    Network,
    RefreshCw,
    Ticket,
    Users,
    BarChart3,
    CheckCircle2,
    X,
    CheckCircle,
    XCircle,
    TrendingUp,
    UserCheck,
    Plus,
    ListChecks,
    AlertCircle,
    Clock,
    Bell,
    BellOff,
    Maximize2,
    Info,
} from "lucide-react";

// --- CONSTANTES ---
const DEFAULT_FILTERS = {
    date_from: "",
    date_to: "",
    area: "all",
    sede: "all",
    type: "all",
    priority: "all",
    state: "all",
};

// --- COMPONENTES UI MEJORADOS ---

const SummaryMetric = ({ label, value, icon: Icon, helper, variant = "default", valueClassName }) => {
    const surface = metricCardVariant[variant] || metricCardVariant.default;

    return (
        <Card className={cn("shadow-sm transition-all duration-200", surface.card)}>
            <CardContent className="p-5 flex items-start justify-between">
                <div className="space-y-1 min-w-0">
                    <p className="text-[10px] uppercase tracking-wider font-bold text-muted-foreground">{label}</p>
                    <div className={cn("text-2xl font-bold tracking-tight text-foreground", valueClassName)}>{value}</div>
                    {helper && <p className="text-[11px] text-muted-foreground/80">{helper}</p>}
                </div>
                {Icon && (
                    <div className={cn("h-9 w-9 rounded-lg flex items-center justify-center shrink-0", surface.icon)}>
                        <Icon className="h-4.5 w-4.5" />
                    </div>
                )}
            </CardContent>
        </Card>
    );
};

const MetricList = ({
    id,
    title,
    icon: Icon,
    items,
    total,
    className,
    isExpanded = false,
    onExpand,
    onCollapse,
}) => {
    const safeItems = items || [];
    const totalNum = Number(total) || 0;

    return (
        <>
            <Card className={cn("flex flex-col h-full shadow-sm border-border/60", className)}>
                <CardHeader className="pb-3 border-b bg-muted/10 px-4 py-3 min-w-0">
                    <div className="flex items-center justify-between gap-2 min-w-0">
                        <CardTitle className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2 min-w-0 overflow-hidden">
                            {Icon && <Icon className="h-3.5 w-3.5 shrink-0" />}
                            <span className="truncate min-w-0">{title}</span>
                            {id && onExpand && (
                                <span
                                    className="inline-flex items-center gap-1 text-[10px] font-normal normal-case tracking-normal text-muted-foreground/90 shrink-0"
                                    title="Clic en el gráfico o en Ver detalle para ampliar"
                                >
                                    <Info className="h-3 w-3" aria-hidden />
                                    <span className="hidden sm:inline">Clic para ver detalle</span>
                                </span>
                            )}
                        </CardTitle>
                        {id && onExpand && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 text-xs shrink-0 whitespace-nowrap text-primary hover:text-primary"
                                onClick={onExpand}
                                aria-label="Ver detalle y tabla completa"
                            >
                                <Maximize2 className="h-3.5 w-3.5 mr-1 shrink-0" />
                                Ver detalle
                            </Button>
                        )}
                    </div>
                </CardHeader>
                <CardContent className="p-0 overflow-hidden">
                    <div className="p-4 pt-2">
                        {safeItems.length ? (
                            <div
                                role={id && onExpand ? "button" : undefined}
                                tabIndex={id && onExpand ? 0 : undefined}
                                onClick={id && onExpand ? (e) => { e.preventDefault(); onExpand(); } : undefined}
                                onKeyDown={id && onExpand ? (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onExpand(); } } : undefined}
                                className={cn(
                                    "max-h-[300px] overflow-hidden rounded-lg transition-all",
                                    id && onExpand && "cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:ring-offset-2 hover:ring-2 hover:ring-primary/20 hover:ring-offset-2"
                                )}
                                aria-label={id && onExpand ? "Clic para ver detalle y tabla completa" : undefined}
                            >
                                <ChartErrorBoundary>
                                    <TinyVerticalBarChart
                                        data={safeItems}
                                        dataKey="value"
                                        cardTitle={title}
                                        onBarClick={onExpand}
                                    />
                                </ChartErrorBoundary>
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-8 text-muted-foreground opacity-50 space-y-2">
                                <BarChart3 className="w-8 h-8" />
                                <p className="text-xs">Sin datos registrados</p>
                            </div>
                        )}
                        {id && onExpand && safeItems.length > 0 && (
                            <p className="text-[10px] text-muted-foreground mt-2 text-center">
                                Clic en el gráfico o en «Ver detalle» para ver la tabla completa.
                            </p>
                        )}
                    </div>
                </CardContent>
            </Card>

            {/* Modal expandido: datos completos; Esc o botón Cerrar */}
            <Dialog open={!!id && isExpanded} onOpenChange={(open) => !open && onCollapse?.()}>
                <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto !duration-100">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            {Icon && <Icon className="h-4 w-4 text-primary" />}
                            {title}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 pt-2">
                        <ChartErrorBoundary>
                            <TinyVerticalBarChart
                                data={safeItems}
                                dataKey="value"
                                cardTitle={title}
                                height={280}
                                animationDuration={0}
                            />
                        </ChartErrorBoundary>
                        <div className="border rounded-md overflow-hidden">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="text-left font-medium px-3 py-2 text-muted-foreground">Concepto</th>
                                        <th className="text-right font-medium px-3 py-2 text-muted-foreground">Cantidad</th>
                                        {totalNum > 0 && (
                                            <th className="text-right font-medium px-3 py-2 text-muted-foreground">% del total</th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {safeItems.map((row, idx) => {
                                        const val = Number(row?.value ?? 0) || 0;
                                        const pct = totalNum > 0 ? ((val / totalNum) * 100).toFixed(1) : "—";
                                        return (
                                            <tr key={idx} className="border-b border-border/50 last:border-0 hover:bg-muted/30">
                                                <td className="px-3 py-2 font-medium text-foreground max-w-[240px]" title={row?.label}>
                                                    <span className="block truncate">{row?.label ?? "—"}</span>
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono text-muted-foreground tabular-nums">{val}</td>
                                                {totalNum > 0 && (
                                                    <td className="px-3 py-2 text-right font-mono text-muted-foreground tabular-nums">{pct}%</td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <DialogFooter className="flex flex-row justify-end gap-2 pt-4 border-t border-border/50 sm:justify-end">
                        <Button type="button" variant="outline" onClick={onCollapse}>
                            Cerrar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
};

const StateDistribution = ({ states, total }) => {
    const safeStates = states || [];
    return (
        <Card className="xl:col-span-3 shadow-sm border-border/60">
            <CardHeader className="pb-4">
                <div className="flex items-center justify-between">
                    <div className="space-y-1">
                        <CardTitle className="text-sm font-bold flex items-center gap-2">
                            <Activity className="h-4 w-4 text-primary" />
                            Distribución de Estados
                        </CardTitle>
                        <CardDescription className="text-xs">Panorama general del flujo de trabajo</CardDescription>
                    </div>
                    <Badge variant="secondary" className="font-mono">
                        {total} Tickets
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                <ChartErrorBoundary>
                    <DashboardStackedBar states={safeStates} total={total} height={24} />
                </ChartErrorBoundary>

                {/* Leyenda Grid */}
                <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
                    {safeStates.map((state, idx) => {
                        const value = Number(state.value || 0);
                        const pct = total ? Math.round((value / total) * 100) : 0;
                        return (
                            <div key={`legend-${idx}`} className="flex items-center gap-2 bg-muted/20 p-2 rounded border border-border/30">
                                <div
                                    className="h-2.5 w-2.5 rounded-full shrink-0"
                                    style={{ backgroundColor: chartColor(idx % 8) }}
                                />
                                <div className="flex flex-col min-w-0">
                                    <span className="text-[10px] font-medium truncate leading-tight" title={state.label}>{state.label}</span>
                                    <span className="text-[10px] text-muted-foreground">{value} ({pct}%)</span>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </CardContent>
        </Card>
    );
};

const DashboardSkeleton = () => (
    <div className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-28 w-full rounded-xl" />)}
        </div>
        <Skeleton className="h-48 w-full rounded-xl" />
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-64 w-full rounded-xl" />)}
        </div>
    </div>
);

/**
 * Bloque de bienvenida común a todos los dashboards: avatar + Hola + saludo por hora + reloj + día actual.
 * Opcional: children (subtítulo/acciones bajo el reloj), actions (nodo a la derecha, ej. botones).
 */
function DashboardWelcome({ user, children, actions }) {
    const [currentTime, setCurrentTime] = useState(() => new Date());
    useEffect(() => {
        const interval = setInterval(() => setCurrentTime(new Date()), 1000);
        return () => clearInterval(interval);
    }, []);
    const greeting = useMemo(() => {
        const h = currentTime.getHours();
        if (h >= 5 && h < 12) return "Buenos días";
        if (h >= 12 && h < 19) return "Buenas tardes";
        return "Buenas noches";
    }, [currentTime]);
    const clock = currentTime.toLocaleTimeString("es-ES", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    const dayLabel = currentTime.toLocaleDateString("es-ES", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

    return (
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div className="flex flex-wrap items-center gap-4">
                <UserAvatar
                    name={user?.name}
                    avatarUrl={user?.avatar_url}
                    avatarPath={user?.avatar_path}
                    size={48}
                    className="shrink-0"
                />
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold text-foreground">
                        Hola, {user?.name ?? "Usuario"}. {greeting}.
                    </h1>
                    <p className="text-sm text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0">
                        <span className="font-mono tabular-nums" aria-label="Hora actual">{clock}</span>
                        <span className="text-foreground/80">·</span>
                        <span>{dayLabel}</span>
                    </p>
                    {children}
                </div>
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2 shrink-0">{actions}</div>}
        </div>
    );
}

// --- COMPONENTE PRINCIPAL ---

/** Formatea una fecha a YYYY-MM-DD para comparar días. */
function toDateKey(date) {
    const d = typeof date === "string" ? new Date(date) : date;
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

/** Texto relativo para "hace X min/h/días" */
function relativeTime(dateStr) {
    if (!dateStr) return null;
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now - date;
    const diffMin = Math.floor(diffMs / 60000);
    const diffH = Math.floor(diffMs / 3600000);
    const diffD = Math.floor(diffMs / 86400000);
    if (diffMin < 1) return "hace un momento";
    if (diffMin < 60) return `hace ${diffMin} min`;
    if (diffH < 24) return `hace ${diffH} h`;
    if (diffD === 1) return "ayer";
    if (diffD < 7) return `hace ${diffD} días`;
    return date.toLocaleDateString("es-ES", { day: "numeric", month: "short" });
}

const CREATE_FORM_INITIAL = {
    subject: "",
    description: "",
    site_id: "",
    area_origin_id: "",
    area_current_id: "",
    ticket_type_id: "",
    priority_id: "",
    ticket_state_id: "",
};

/** Dashboard para usuario solicitante (o visitante en solo lectura). Visitante tiene view_own pero no create. */
function DashboardSolicitante() {
    const { user, can } = useAuth();
    const canCreateTicket = can("tickets.create");
    const [tickets, setTickets] = useState([]);
    const [loading, setLoading] = useState(true);
    const [createModalOpen, setCreateModalOpen] = useState(false);
    const [createForm, setCreateForm] = useState(CREATE_FORM_INITIAL);
    const [createSaving, setCreateSaving] = useState(false);
    const [createCatalogs, setCreateCatalogs] = useState({ areas: [], sites: [], priorities: [], ticket_states: [], ticket_types: [] });
    const [createCatalogsLoading, setCreateCatalogsLoading] = useState(false);
    const [sortOrder, setSortOrder] = useState("recent");
    const [stateFilter, setStateFilter] = useState("all");
    const [createSuccessTicketId, setCreateSuccessTicketId] = useState(null);
    const [activityNotifications, setActivityNotifications] = useState([]);
    const [activityLoading, setActivityLoading] = useState(false);
    const refMisTickets = useRef(null);

    const loadTickets = useCallback(() => {
        setLoading(true);
        axios.get("/api/tickets", { params: { per_page: 50 } })
            .then((res) => setTickets(res.data?.data ?? []))
            .catch(() => setTickets([]))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        loadTickets();
    }, [loadTickets]);

    const loadActivity = useCallback(() => {
        setActivityLoading(true);
        axios.get("/api/notifications", { params: { limit: 5 } })
            .then((res) => {
                const list = res.data?.notifications ?? (Array.isArray(res.data) ? res.data : []);
                setActivityNotifications(Array.isArray(list) ? list : []);
            })
            .catch(() => setActivityNotifications([]))
            .finally(() => setActivityLoading(false));
    }, []);

    useEffect(() => {
        loadActivity();
    }, [loadActivity]);

    const activityNotificationTitle = (n) => {
        const d = n?.data || {};
        if (d.message) return d.message;
        if (d.ticket_id) return `Solicitud #${d.ticket_id}`;
        return "Notificación";
    };

    const activityNotificationTime = (n) => {
        if (!n?.created_at) return "";
        return relativeTime(n.created_at);
    };

    const openCreateModal = useCallback(() => {
        setCreateModalOpen(true);
        const applyDefaults = (data) => {
            const openState = (data.ticket_states || []).find((s) => (s.code || "").toLowerCase() === "abierto") || (data.ticket_states || [])[0];
            setCreateForm({
                ...CREATE_FORM_INITIAL,
                site_id: String(user?.site_id || user?.site?.id || ""),
                area_origin_id: String(user?.area_id || ""),
                ticket_type_id: String((data.ticket_types || [])[0]?.id || ""),
                priority_id: String((data.priorities || [])[0]?.id || ""),
                ticket_state_id: String(openState?.id || ""),
            });
        };
        if (createCatalogs.ticket_states?.length > 0) {
            applyDefaults(createCatalogs);
            return;
        }
        setCreateCatalogsLoading(true);
        loadCatalogs()
            .then((data) => {
                setCreateCatalogs(data);
                applyDefaults(data);
            })
            .catch(() => notify.error("No se pudieron cargar los catálogos"))
            .finally(() => setCreateCatalogsLoading(false));
    }, [user?.site_id, user?.site?.id, user?.area_id, createCatalogs]);

    const handleCreateSubmit = async (e, pendingFiles = []) => {
        e.preventDefault();
        if (!createForm.subject?.trim()) {
            notify.error("El asunto es obligatorio");
            return;
        }
        if (!createForm.site_id || !createForm.area_origin_id || !createForm.area_current_id || !createForm.ticket_type_id || !createForm.priority_id || !createForm.ticket_state_id) {
            notify.error("Completa todos los campos obligatorios (sede, área responsable, área origen, tipo, prioridad).");
            return;
        }
        setCreateSaving(true);
        try {
            const payload = {
                subject: createForm.subject.trim(),
                description: createForm.description?.trim() || null,
                site_id: Number(createForm.site_id),
                area_origin_id: Number(createForm.area_origin_id),
                area_current_id: Number(createForm.area_current_id),
                priority_id: Number(createForm.priority_id),
                ticket_type_id: Number(createForm.ticket_type_id),
                ticket_state_id: Number(createForm.ticket_state_id),
                created_at: new Date().toISOString(),
            };
            const { data } = await axios.post("/api/tickets", payload);
            const ticketId = data?.id ?? null;
            if (ticketId && pendingFiles.length > 0) {
                const attachmentsForm = new FormData();
                pendingFiles.forEach((file) => attachmentsForm.append("attachments[]", file));
                try {
                    await axios.post(`/api/tickets/${ticketId}/attachments`, attachmentsForm);
                } catch {
                    notify.error("El ticket se creó, pero los adjuntos no se pudieron subir.");
                }
            }
            clearCatalogCache();
            loadTickets();
            loadActivity();
            setCreateSuccessTicketId(ticketId);
        } catch (err) {
            notify.error(err?.response?.data?.message || "Error al crear el ticket");
        } finally {
            setCreateSaving(false);
        }
    };

    const ticketsToShow = tickets;
    const ticketsFilteredByState = useMemo(() => {
        if (stateFilter === "all") return ticketsToShow;
        return ticketsToShow.filter((t) => {
            const code = (t.state?.code ?? "").toLowerCase();
            if (stateFilter === "open") return code === "abierto";
            if (stateFilter === "progress") return ["en_progreso", "en progreso", "en_espera"].includes(code);
            if (stateFilter === "resolved") return code === "cerrado" || code === "resuelto";
            return true;
        });
    }, [ticketsToShow, stateFilter]);
    const sortedTicketsToShow = useMemo(() => {
        const list = [...ticketsFilteredByState];
        list.sort((a, b) => {
            const da = new Date(a.created_at || 0).getTime();
            const db = new Date(b.created_at || 0).getTime();
            return sortOrder === "recent" ? db - da : da - db;
        });
        return list;
    }, [ticketsFilteredByState, sortOrder]);

    const isResolved = (t) => {
        const code = t.state?.code?.toLowerCase?.() ?? "";
        return code === "cerrado" || code === "resuelto";
    };
    const isCancelled = (t) => (t.state?.code?.toLowerCase?.() ?? "") === "cancelado";

    const stats = useMemo(() => {
        const total = tickets.length;
        const resolved = tickets.filter(isResolved).length;
        const cancelled = tickets.filter(isCancelled).length;
        return { total, resolved, cancelled };
    }, [tickets]);

    const openCount = useMemo(() => tickets.filter((t) => !isResolved(t) && !isCancelled(t)).length, [tickets]);

    const scrollToMisTickets = useCallback(() => {
        refMisTickets.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    }, []);

    const top5Types = useMemo(() => {
        const byType = {};
        tickets.forEach((t) => {
            const type = t.ticket_type || t.ticketType;
            const name = type?.name ?? "Sin tipo";
            const id = type?.id ?? name;
            byType[id] = (byType[id] || { label: name, value: 0 });
            byType[id].value += 1;
        });
        return Object.values(byType)
            .sort((a, b) => (b.value || 0) - (a.value || 0))
            .slice(0, 5);
    }, [tickets]);

    const topResolver = useMemo(() => {
        const resolvedTickets = tickets.filter(isResolved);
        const byUser = {};
        resolvedTickets.forEach((t) => {
            const u = t.assigned_user || t.assignedUser;
            const id = u?.id ?? "sin-asignar";
            const name = u?.name ?? "Sin asignar";
            if (!byUser[id]) byUser[id] = { label: name, value: 0 };
            byUser[id].value += 1;
        });
        const arr = Object.values(byUser).sort((a, b) => (b.value || 0) - (a.value || 0));
        return arr[0] || null;
    }, [tickets]);

    return (
        <div className="w-full space-y-6">
            <DashboardWelcome user={user}>
                <p className="text-sm text-foreground/90 mt-2">
                    Tienes <strong>{openCount}</strong> {openCount === 1 ? "solicitud abierta" : "solicitudes abiertas"}
                </p>
                <Button type="button" variant="outline" size="sm" onClick={scrollToMisTickets} className="mt-2 inline-flex items-center gap-2">
                    <ListChecks className="h-4 w-4" /> Ir a mis tickets
                </Button>
            </DashboardWelcome>

            {/* Acción principal: Crear ticket (oculto para visitante: solo lectura) */}
            {canCreateTicket && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="button" onClick={openCreateModal} className="inline-flex items-center gap-2">
                        <Plus className="h-4 w-4" /> Crear ticket
                    </Button>
                    <p className="text-sm text-muted-foreground">Crea una nueva solicitud o revisa las que ya enviaste.</p>
                </div>
            )}
            {!canCreateTicket && (
                <p className="text-sm text-muted-foreground">Modo solo lectura. Un administrador te asignará un rol para crear y gestionar solicitudes.</p>
            )}

            {/* Respuestas y novedades sobre tus tickets */}
            <Card className="border-border/60">
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-bold flex items-center gap-2">
                        <Bell className="h-4 w-4 text-primary" /> Respuestas y novedades
                    </CardTitle>
                    <CardDescription className="text-xs">
                        Comentarios, cambios de estado y avisos de soporte sobre tus solicitudes.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {activityLoading ? (
                        <div className="space-y-2 py-2">
                            {[1, 2, 3].map((i) => <Skeleton key={i} className="h-12 w-full rounded-md" />)}
                        </div>
                    ) : activityNotifications.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-8 text-center">
                            <BellOff className="h-10 w-10 text-muted-foreground/30 mb-2" />
                            <p className="text-sm text-muted-foreground">No hay actividad reciente.</p>
                        </div>
                    ) : (
                        <ul className="space-y-1">
                            {activityNotifications.map((n) => {
                                const ticketId = n?.data?.ticket_id;
                                const title = activityNotificationTitle(n);
                                const time = activityNotificationTime(n);
                                const content = (
                                    <span className="block text-sm text-foreground/90 py-2 px-3 rounded-md hover:bg-muted/40 transition-colors">
                                        <span className="text-[11px] text-muted-foreground mr-2">{time}</span>
                                        {ticketId && <span className="font-mono text-[11px] text-muted-foreground">#{ticketId}</span>}
                                        <span className="ml-1">{title}</span>
                                    </span>
                                );
                                return ticketId ? (
                                    <li key={n.id}>
                                        <NavLink href={`/resolbeb/tickets/${ticketId}`} className="block">
                                            {content}
                                        </NavLink>
                                    </li>
                                ) : (
                                    <li key={n.id}>{content}</li>
                                );
                            })}
                        </ul>
                    )}
                </CardContent>
            </Card>

            {/* Cards de métricas del solicitante */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <SummaryMetric label="Tickets realizados" value={stats.total} icon={Ticket} helper="Total que has creado" />
                <SummaryMetric label="Resueltos" value={stats.resolved} icon={CheckCircle} variant="success" helper="Cerrados o resueltos" />
                <SummaryMetric label="Cancelados" value={stats.cancelled} icon={XCircle} variant="warning" helper="Tuyos o que te cancelaron" />
                <SummaryMetric
                    label="Quien te resuelve más"
                    value={topResolver ? topResolver.label : "—"}
                    icon={UserCheck}
                    variant="info"
                    valueClassName="text-lg truncate"
                    helper={topResolver ? `${topResolver.value} ticket${topResolver.value !== 1 ? "s" : ""} resueltos` : "Sin datos"}
                />
            </div>

            {/* Top 5 tipos de ticket que más manda */}
            {top5Types.length > 0 && (
                <Card className="shadow-sm border-border/60">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-bold flex items-center gap-2">
                            <TrendingUp className="h-4 w-4 text-primary" /> Tipos de ticket que más envías
                        </CardTitle>
                        <CardDescription className="text-xs">Tus 5 categorías más usadas.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="space-y-2">
                            {top5Types.map((item, idx) => (
                                <li key={idx} className="flex items-center justify-between text-sm py-1.5 px-2 rounded-md bg-muted/30">
                                    <span className="font-medium truncate pr-2">{item.label}</span>
                                    <Badge variant="secondary" className="text-xs font-mono">{item.value}</Badge>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}

            {/* Mis tickets: listado completo en Inicio. Calendario en /calendario. */}
            <Card ref={refMisTickets}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <div>
                        <CardTitle className="text-base flex items-center gap-2">
                            <Ticket className="h-4 w-4" /> Mis tickets
                        </CardTitle>
                        <CardDescription>
                            Listado de incidencias que has reportado. Por fechas:{" "}
                            <NavLink href="/calendar" className="text-primary underline underline-offset-2 hover:no-underline">
                                Calendario
                            </NavLink>.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {ticketsToShow.length > 0 && (
                            <Select value={sortOrder} onValueChange={setSortOrder}>
                                <SelectTrigger className="w-[180px] h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="recent">Más recientes primero</SelectItem>
                                    <SelectItem value="oldest">Más antiguos primero</SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                </CardHeader>
                {ticketsToShow.length > 0 && (
                    <div className="px-6 pb-2 flex flex-wrap gap-1">
                        {[
                            { value: "all", label: "Todos" },
                            { value: "open", label: "Abiertos" },
                            { value: "progress", label: "En progreso" },
                            { value: "resolved", label: "Resueltos" },
                        ].map((tab) => (
                            <Button
                                key={tab.value}
                                type="button"
                                variant={stateFilter === tab.value ? "secondary" : "ghost"}
                                size="sm"
                                className="h-7 text-xs"
                                onClick={() => setStateFilter(tab.value)}
                            >
                                {tab.label}
                            </Button>
                        ))}
                    </div>
                )}
                <CardContent>
                    {loading ? (
                        <div className="space-y-2">
                            {[1, 2, 3].map((i) => <Skeleton key={i} className="h-12 w-full" />)}
                        </div>
                    ) : tickets.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-8 text-center px-4">
                            <p className="text-sm text-muted-foreground max-w-sm">
                                {canCreateTicket
                                    ? "Aún no has creado ninguna solicitud. Cuando lo hagas, aparecerán aquí y en el calendario."
                                    : "Aún no tienes solicitudes. Cuando un administrador te asigne un rol, podrás crear solicitudes."}
                            </p>
                            {canCreateTicket && (
                                <Button type="button" variant="default" size="sm" className="mt-3" onClick={openCreateModal}>
                                    Crear mi primer ticket
                                </Button>
                            )}
                        </div>
                    ) : sortedTicketsToShow.length === 0 ? (
                        <p className="text-sm text-muted-foreground py-6 text-center">
                            Ningún ticket coincide con el filtro.
                            {stateFilter !== "all" && (
                                <Button variant="link" className="ml-1 h-auto p-0" onClick={() => setStateFilter("all")}>
                                    Quitar filtro
                                </Button>
                            )}
                        </p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {sortedTicketsToShow.map((t) => {
                                const assigned = t.assigned_user || t.assignedUser;
                                const unassigned = !assigned;
                                const overdue = Boolean(t.is_overdue);
                                const lastAt = t.updated_at || t.created_at;
                                return (
                                    <li key={t.id} className={cn("py-3 first:pt-0", (unassigned || overdue) && "border-l-2 border-l-transparent", unassigned && "border-l-amber-500/50", overdue && "border-l-destructive/50")}>
                                        <NavLink href={`/resolbeb/tickets/${t.id}`} className="flex flex-wrap items-center justify-between gap-2 hover:bg-muted/30 -mx-2 px-2 py-1.5 rounded transition-colors block">
                                            <span className="font-medium text-foreground">#{String(t.id).padStart(5, "0")} — {t.subject}</span>
                                            <div className="flex items-center gap-2 flex-wrap">
                                                {unassigned && (
                                                    <span className={cn("inline-flex items-center gap-1 text-[11px]", hintWarning)} title="Sin asignar">
                                                        <AlertCircle className="h-3.5 w-3.5" /> Sin asignar
                                                    </span>
                                                )}
                                                {overdue && (
                                                    <span className="inline-flex items-center gap-1 text-[11px] text-destructive" title="Vencido (SLA)">
                                                        <Clock className="h-3.5 w-3.5" /> Vencido
                                                    </span>
                                                )}
                                                <Badge variant="secondary" className="text-xs">{t.state?.name ?? "—"}</Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    {assigned ? `Atendido por: ${assigned.name}` : ""}
                                                </span>
                                            </div>
                                        </NavLink>
                                        <p className="text-xs text-muted-foreground mt-1 pl-0">
                                            Creado {new Date(t.created_at).toLocaleDateString()}
                                            {lastAt && (
                                                <span className="ml-2">
                                                    · Última actualización: {relativeTime(lastAt)}
                                                </span>
                                            )}
                                        </p>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </CardContent>
            </Card>

            {/* Modal crear ticket: sin salir del dashboard. Mismo componente que
                usan agentes/supervisores desde "Todos los tickets" -- un solo
                formulario enriquecido (markdown, adjuntos) para mantener, no dos. */}
            <TicketCreateDialog
                open={createModalOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreateSuccessTicketId(null);
                        setCreateForm(CREATE_FORM_INITIAL);
                    }
                    setCreateModalOpen(open);
                }}
                form={createForm}
                setForm={setCreateForm}
                catalogs={createCatalogs}
                saving={createSaving || createCatalogsLoading}
                onSubmit={handleCreateSubmit}
                siteContext={{ siteName: user?.site?.name || user?.site, clientName: user?.client_name }}
                successTicketId={createSuccessTicketId}
                onSuccessClose={() => {
                    setCreateModalOpen(false);
                    setCreateSuccessTicketId(null);
                    setCreateForm(CREATE_FORM_INITIAL);
                }}
            />
        </div>
    );
}

function DashboardAdmin() {
    const { user, can } = useAuth();
    const canManageAll = can("tickets.manage_all");
    const canViewArea = can("tickets.view_area") || canManageAll;
    const canFilterSite = can("tickets.filter_by_site") || canManageAll;

    const [catalogs, setCatalogs] = useState({
        areas: [], sites: [], priorities: [], ticket_states: [], ticket_types: [],
    });

    const [filters, setFilters] = useState(() => {
        if (typeof window === "undefined") return DEFAULT_FILTERS;
        try {
            const saved = localStorage.getItem("dashboard.filters");
            return saved ? { ...DEFAULT_FILTERS, ...JSON.parse(saved) } : DEFAULT_FILTERS;
        } catch (_) {
            return DEFAULT_FILTERS;
        }
    });
    const [appliedFilters, setAppliedFilters] = useState(filters);

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [lastUpdated, setLastUpdated] = useState(null);
    const [expandedCardId, setExpandedCardId] = useState(null);
    const [expandHintDismissed, setExpandHintDismissed] = useState(() => {
        try {
            return !!window.localStorage?.getItem("dashboard.expandHintSeen");
        } catch {
            return false;
        }
    });

    const isAreaLocked = !canManageAll && canViewArea && user?.area_id;

    const dismissExpandHint = useCallback(() => {
        try {
            window.localStorage?.setItem("dashboard.expandHintSeen", "1");
        } catch (_) {}
        setExpandHintDismissed(true);
    }, []);

    useEffect(() => {
        const handleEsc = (e) => {
            if (e?.key === "Escape") setExpandedCardId(null);
        };
        if (expandedCardId) {
            window.addEventListener("keydown", handleEsc);
            return () => window.removeEventListener("keydown", handleEsc);
        }
    }, [expandedCardId]);

    // --- EFECTOS & LOGICA ---

    useEffect(() => {
        if (typeof window !== "undefined") localStorage.setItem("dashboard.filters", JSON.stringify(filters));
    }, [filters]);

    useEffect(() => {
        let mounted = true;
        loadCatalogs()
            .then((res) => mounted && setCatalogs(res))
            .catch(() => notify.error({ title: "Error", description: "Falló la carga de catálogos." }));
        return () => { mounted = false; };
    }, []);

    useEffect(() => {
        if (!isAreaLocked) return;
        const areaValue = String(user?.area_id);
        setFilters((prev) => (prev.area === areaValue ? prev : { ...prev, area: areaValue }));
        setAppliedFilters((prev) => (prev.area === areaValue ? prev : { ...prev, area: areaValue }));
    }, [isAreaLocked, user?.area_id]);

    const buildParams = useCallback((filtersToApply) => {
        const params = {};
        if (filtersToApply.date_from) params.date_from = filtersToApply.date_from;
        if (filtersToApply.date_to) params.date_to = filtersToApply.date_to;
        if (filtersToApply.state !== "all") params.ticket_state_id = filtersToApply.state;
        if (filtersToApply.priority !== "all") params.priority_id = filtersToApply.priority;
        if (filtersToApply.type !== "all") params.ticket_type_id = filtersToApply.type;
        if (filtersToApply.area !== "all") params.area_current_id = filtersToApply.area;
        if (filtersToApply.site !== "all") params.site_id = filtersToApply.site;

        if (!canManageAll && canViewArea && user?.area_id) params.area_current_id = user.area_id;
        if (!canFilterSite) delete params.site_id;
        return params;
    }, [canFilterSite, canManageAll, canViewArea, user?.area_id]);

    const loadAnalytics = useCallback(async (nextFilters = appliedFilters) => {
        setLoading(true);
        setError("");
        try {
            const params = buildParams(nextFilters);
            const response = await axios.get("/api/tickets/analytics", { params });
            setData(response.data);
            setLastUpdated(new Date());
        } catch (err) {
            setError(err?.response?.data?.message || "Error cargando métricas");
            if (!data) setData(null);
        } finally {
            setLoading(false);
        }
    }, [appliedFilters, buildParams, data]);

    useEffect(() => { loadAnalytics(appliedFilters); }, [appliedFilters, loadAnalytics]);

    // --- UTILS ---
    const activeFilterCount = useMemo(() => {
        return Object.keys(appliedFilters).reduce((acc, key) => {
            const val = appliedFilters[key];
            return (val && val !== "all" && val !== "") ? acc + 1 : acc;
        }, 0);
    }, [appliedFilters]);

    const hasPendingChanges = useMemo(() => JSON.stringify(filters) !== JSON.stringify(appliedFilters), [filters, appliedFilters]);

    const applyFilters = () => {
        if (filters.date_from && filters.date_to && filters.date_to < filters.date_from) {
            notify.error({ title: "Error", description: "Fecha final inválida." });
            return;
        }
        setAppliedFilters(filters);
    };

    const clearFilters = () => {
        const next = { ...DEFAULT_FILTERS };
        if (isAreaLocked && user?.area_id) next.area = String(user.area_id);
        setFilters(next);
        setAppliedFilters(next);
    };

    const statesSorted = useMemo(() => data?.states ? [...data.states].sort((a, b) => Number(b.value || 0) - Number(a.value || 0)) : [], [data]);
    const totalTickets = useMemo(() => statesSorted.reduce((acc, item) => acc + Number(item.value || 0), 0), [statesSorted]);
    const topResolver = data?.top_resolvers?.[0];
    const topArea = data?.areas_receive?.[0];

    const exportUrl = useMemo(() => {
        const params = buildParams(appliedFilters);
        const query = new URLSearchParams(params).toString();
        return query ? `/api/tickets/export?${query}` : "/api/tickets/export";
    }, [appliedFilters, buildParams]);

    const isInitialLoading = loading && !data;

    return (
        <div className="w-full space-y-8 animate-in fade-in duration-500">

            <DashboardWelcome
                user={user}
                actions={
                    <>
                        {lastUpdated && (
                            <span className="text-[10px] text-muted-foreground hidden lg:inline-block mr-2 bg-muted/30 px-2 py-1 rounded">
                                Actualizado: {lastUpdated.toLocaleTimeString()}
                            </span>
                        )}
                        <Button variant="outline" size="sm" onClick={() => loadAnalytics(appliedFilters)} disabled={loading} className="h-9">
                            <RefreshCw className={`h-3.5 w-3.5 mr-2 ${loading ? "animate-spin" : ""}`} />
                            Refrescar
                        </Button>
                        <Separator orientation="vertical" className="h-6 mx-1 hidden sm:block" />
                        <Button asChild variant="secondary" size="sm" className="h-9 shadow-sm">
                            <a href={exportUrl}>
                                <Download className="h-3.5 w-3.5 mr-2" />
                                Exportar CSV
                            </a>
                        </Button>
                    </>
                }
            />

            {/* BARRA DE FILTROS */}
            <Card className="border border-border/60 shadow-sm bg-card/40 backdrop-blur-sm">
                <CardHeader className="pb-3 pt-4 px-5">
                    <div className="flex items-center justify-between">
                        <CardTitle className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                            <Filter className="h-3.5 w-3.5" />
                            Filtros Globales
                        </CardTitle>
                        <div className="flex gap-2">
                            {activeFilterCount > 0 && (
                                <Badge variant="secondary" className="h-6 px-2 text-[10px]">
                                    {activeFilterCount} activos
                                </Badge>
                            )}
                        </div>
                    </div>
                </CardHeader>
                <Separator className="mx-5 opacity-50" />
                <CardContent className="p-5">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                        <div className="space-y-1.5">
                            <Label className="text-[10px] uppercase font-bold text-muted-foreground ml-1">Fecha Inicio</Label>
                            <DatePickerField
                                value={filters.date_from}
                                onChange={(v) => setFilters(p => ({ ...p, date_from: v }))}
                                placeholder="Seleccionar..."
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-[10px] uppercase font-bold text-muted-foreground ml-1">Fecha Fin</Label>
                            <DatePickerField
                                value={filters.date_to}
                                onChange={(v) => setFilters(p => ({ ...p, date_to: v }))}
                                placeholder="Seleccionar..."
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-[10px] uppercase font-bold text-muted-foreground ml-1">Estado</Label>
                            <Select value={filters.state} onValueChange={(v) => setFilters(p => ({ ...p, state: v }))}>
                                <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Estado" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Cualquier estado</SelectItem>
                                    {catalogs.ticket_states.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-[10px] uppercase font-bold text-muted-foreground ml-1">Prioridad</Label>
                            <Select value={filters.priority} onValueChange={(v) => setFilters(p => ({ ...p, priority: v }))}>
                                <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Prioridad" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todas</SelectItem>
                                    {catalogs.priorities.map(p => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5 xl:block hidden">
                            <Label className="text-[10px] uppercase font-bold text-muted-foreground ml-1">Tipo</Label>
                            <Select value={filters.type} onValueChange={(v) => setFilters(p => ({ ...p, type: v }))}>
                                <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Tipo" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    {catalogs.ticket_types.map(t => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Filtros colapsables en móviles o extra filtros */}
                        {(canViewArea || canFilterSite) && (
                            <div className="contents xl:contents">
                                {canViewArea && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[10px] uppercase font-bold text-muted-foreground ml-1 flex justify-between">
                                            Area {isAreaLocked && <span className={cn("text-[9px]", hintWarning)}>(Fijo)</span>}
                                        </Label>
                                        <Select value={filters.area} onValueChange={(v) => setFilters(p => ({ ...p, area: v }))} disabled={isAreaLocked}>
                                            <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Área" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Todas las áreas</SelectItem>
                                                {catalogs.areas.map(a => <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                                {canFilterSite && (
                                    <div className="space-y-1.5">
                                        <Label className="text-[10px] uppercase font-bold text-muted-foreground ml-1">Sede</Label>
                                        <Select value={filters.site} onValueChange={(v) => setFilters(p => ({ ...p, site: v }))}>
                                            <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Sede" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Todas</SelectItem>
                                                {catalogs.sites.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="flex justify-end gap-3 mt-5">
                        <Button variant="ghost" size="sm" onClick={clearFilters} disabled={loading} className="text-xs h-8">
                            <X className="mr-2 h-3 w-3" /> Limpiar
                        </Button>
                        <Button size="sm" onClick={applyFilters} disabled={!hasPendingChanges || loading} className="text-xs h-8 px-5">
                            <CheckCircle2 className="mr-2 h-3 w-3" /> Aplicar Filtros
                        </Button>
                    </div>
                </CardContent>
            </Card>

            {/* ERROR STATE */}
            {error && (
                <div className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive flex items-center gap-2 animate-in slide-in-from-top-2">
                    <AlertTriangle className="h-4 w-4" />
                    <span className="flex-1 font-medium">{error}</span>
                    <Button variant="outline" size="sm" className="h-7 bg-background" onClick={() => loadAnalytics(appliedFilters)}>
                        Reintentar
                    </Button>
                </div>
            )}

            {/* Aviso cuando todo está en 0 y hay filtros activos (p. ej. rango de fechas sin datos) */}
            {data && totalTickets === 0 && activeFilterCount > 0 && (
                <div className={cn(noticeWarningRow, "text-sm")}>
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    <span className="flex-1 font-medium">Los filtros actuales no devuelven ningún ticket.</span>
                    <Button variant="outline" size="sm" className="h-7" onClick={clearFilters}>
                        Limpiar filtros
                    </Button>
                </div>
            )}

            {/* DASHBOARD CONTENT */}
            {isInitialLoading ? (
                <DashboardSkeleton />
            ) : !data ? (
                <div className="flex flex-col items-center justify-center py-20 border border-dashed rounded-xl bg-muted/10">
                    <BarChart3 className="w-10 h-10 text-muted-foreground opacity-30 mb-4" />
                    <h3 className="text-lg font-semibold">Sin datos para mostrar</h3>
                    <p className="text-sm text-muted-foreground">Intenta ajustar los filtros o el rango de fechas.</p>
                </div>
            ) : (
                <div className="space-y-6">
                    {/* 1. KPIs */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <SummaryMetric
                            label="Total Tickets"
                            value={totalTickets}
                            icon={Ticket}
                            helper={`${data.states?.length || 0} estados activos`}
                        />
                        <SummaryMetric
                            label="Atención Crítica"
                            value={Number(data?.burned ?? 0)}
                            icon={Flame}
                            variant="destructive"
                            helper=">72h sin resolución"
                        />
                        <SummaryMetric
                            label="Top Resolutor"
                            value={topResolver?.label || "N/A"}
                            icon={CheckCircle2}
                            variant="success"
                            helper={topResolver ? `${topResolver.value} tickets cerrados` : "Sin actividad"}
                        />
                        <SummaryMetric
                            label="Área Más Demandada"
                            value={topArea?.label || "N/A"}
                            icon={Network}
                            helper={topArea ? `${topArea.value} tickets recibidos` : "Sin actividad"}
                        />
                    </div>

                    {/* 2. MAIN CHART */}
                    <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
                        <StateDistribution states={statesSorted} total={totalTickets} />
                    </div>

                    {/* 3. DETAILS GRIDS */}
                    {!expandHintDismissed && (
                        <div className="flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/5 px-4 py-2.5 text-sm text-foreground">
                            <span className="flex items-center gap-2">
                                <Info className="h-4 w-4 text-primary shrink-0" aria-hidden />
                                <span>Tip: haz clic en cualquier gráfico o en «Ver detalle» para ver la tabla completa.</span>
                            </span>
                            <Button type="button" variant="ghost" size="sm" className="h-7 text-xs shrink-0" onClick={dismissExpandHint}>
                                Entendido
                            </Button>
                        </div>
                    )}
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <MetricList
                            id="top_resolvers"
                            title="Top Usuarios (Cierres)"
                            icon={Users}
                            items={data.top_resolvers}
                            total={totalTickets}
                            isExpanded={expandedCardId === "top_resolvers"}
                            onExpand={() => setExpandedCardId("top_resolvers")}
                            onCollapse={() => setExpandedCardId(null)}
                        />
                        <MetricList
                            id="areas_receive"
                            title="Áreas con más carga"
                            icon={Network}
                            items={data.areas_receive}
                            total={totalTickets}
                            isExpanded={expandedCardId === "areas_receive"}
                            onExpand={() => setExpandedCardId("areas_receive")}
                            onCollapse={() => setExpandedCardId(null)}
                        />
                        <MetricList
                            id="areas_resolve"
                            title="Áreas más eficientes"
                            icon={CheckCircle2}
                            items={data.areas_resolve}
                            total={totalTickets}
                            isExpanded={expandedCardId === "areas_resolve"}
                            onExpand={() => setExpandedCardId("areas_resolve")}
                            onCollapse={() => setExpandedCardId(null)}
                        />
                        <MetricList
                            id="types_frequent"
                            title="Tipos Recurrentes"
                            icon={Activity}
                            items={data.types_frequent}
                            total={totalTickets}
                            isExpanded={expandedCardId === "types_frequent"}
                            onExpand={() => setExpandedCardId("types_frequent")}
                            onCollapse={() => setExpandedCardId(null)}
                        />
                        <MetricList
                            id="types_resolved"
                            title="Tipos Resueltos"
                            icon={CheckCircle2}
                            items={data.types_resolved}
                            total={totalTickets}
                            isExpanded={expandedCardId === "types_resolved"}
                            onExpand={() => setExpandedCardId("types_resolved")}
                            onCollapse={() => setExpandedCardId(null)}
                        />
                    </div>
                </div>
            )}
        </div>
    );
}

export default function HomeDashboard() {
    const { user, can } = useAuth();
    const profile = useMemo(() => getDashboardProfile(user, can), [user, can]);

    switch (profile) {
        case "solicitante":
            return <DashboardSolicitante />;
        case "soporte":
            return <DashboardOperativo variant="soporte" />;
        case "supervisor":
            return <DashboardOperativo variant="supervisor" />;
        case "admin":
        default:
            return <DashboardAdmin />;
    }
}
