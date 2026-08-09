import { useCallback, useEffect, useMemo, useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { format, parse, startOfWeek, getDay } from "date-fns";
import { de, enUS, es as dateFnsEs, fr, ja, zhCN } from "date-fns/locale";
import { Calendar as BigCalendar, dateFnsLocalizer, Views, Navigate } from "react-big-calendar";
import "react-big-calendar/lib/css/react-big-calendar.css";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import InertiaPageShell from "@/Inertia/components/InertiaPageShell";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { cn } from "@/lib/utils";
import { kpiCardSurface, hintWarning } from "@/lib/badgeStyles";
import {
    AlertCircle,
    CalendarClock,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ShieldOff,
} from "lucide-react";

const CALENDAR_PER_PAGE = 500;
const EVENT_DURATION_MIN = 30;

function userCan(user, permission) {
    return (user?.permissions ?? []).includes(permission);
}

const LOCALE_MAP = {
    es: dateFnsEs,
    en: enUS,
    ja,
    de,
    zh: zhCN,
    fr,
};

const localizer = dateFnsLocalizer({
    format,
    parse,
    startOfWeek: (date) => startOfWeek(date, { weekStartsOn: 1 }),
    getDay,
    locales: LOCALE_MAP,
});

const VIEW_LABEL = { month: "Mes", week: "Semana", day: "Día", agenda: "Agenda" };

/** Mismo umbral de severidad que priorityClassByLevel (lib/badgeStyles.js), en variante para bloque de evento (barra + tinte) en vez de badge. */
function priorityAccent(level) {
    const l = Number(level);
    if (l === 1) return { bar: "border-l-destructive", bg: "bg-destructive/10 dark:bg-destructive/15", text: "text-destructive" };
    if (l === 2) return { bar: "border-l-amber-500", bg: "bg-amber-500/10", text: "text-amber-700 dark:text-amber-400" };
    if (l === 3) return { bar: "border-l-blue-500", bg: "bg-blue-500/10", text: "text-blue-700 dark:text-blue-400" };
    return { bar: "border-l-muted-foreground/40", bg: "bg-muted/50", text: "text-muted-foreground" };
}

function CalendarEvent({ event }) {
    const ticket = event.resource?.ticket;
    if (!ticket) return null;
    const accent = priorityAccent(ticket?.priority?.level);
    const unassigned = !(ticket.assigned_user || ticket.assignedUser);
    return (
        <div
            className={cn(
                "h-full w-full overflow-hidden rounded-sm border-l-2 px-1.5 py-0.5 text-left leading-tight",
                accent.bar,
                accent.bg
            )}
        >
            <p className={cn("truncate text-[11px] font-semibold", accent.text)}>
                #{String(ticket.id).padStart(5, "0")}
            </p>
            <p className="truncate text-[11px] text-foreground/90">{ticket.subject}</p>
            {unassigned && (
                <span className={cn("mt-0.5 inline-flex items-center gap-0.5 text-[10px]", hintWarning)}>
                    <AlertCircle className="h-2.5 w-2.5" /> Sin asignar
                </span>
            )}
        </div>
    );
}

function CustomToolbar({ label, onNavigate, onView, view, views }) {
    return (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-1.5">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="h-8 w-8"
                    onClick={() => onNavigate(Navigate.PREVIOUS)}
                >
                    <ChevronLeft className="h-4 w-4" />
                </Button>
                <Button type="button" variant="outline" size="sm" className="h-8" onClick={() => onNavigate(Navigate.TODAY)}>
                    Hoy
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="h-8 w-8"
                    onClick={() => onNavigate(Navigate.NEXT)}
                >
                    <ChevronRight className="h-4 w-4" />
                </Button>
                <span className="ml-2 text-sm font-semibold capitalize">{label}</span>
            </div>
            <div className="flex items-center gap-1 rounded-lg bg-muted/50 p-1">
                {views.map((v) => (
                    <Button
                        key={v}
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onView(v)}
                        className={cn("h-7 px-3 text-xs", view === v && "bg-background shadow-sm text-primary")}
                    >
                        {VIEW_LABEL[v] ?? v}
                    </Button>
                ))}
            </div>
        </div>
    );
}

export default function Calendario() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const calendarCulture = auth?.user?.locale ?? "es";

    const [tickets, setTickets] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [stateFilter, setStateFilter] = useState("all");
    const [scope, setScope] = useState("assigned");
    // Ver por fecha de alta (creación) o de cierre (resolución) -- gap documentado en docs/PENDING.md.
    const [dateMode, setDateMode] = useState("created");
    const [view, setView] = useState(Views.WEEK);
    const [date, setDate] = useState(new Date());

    const isOperativo = useMemo(
        () => userCan(user, "tickets.view_area") || userCan(user, "tickets.manage_all"),
        [user]
    );
    const isSolicitante = useMemo(
        () => !isOperativo && userCan(user, "tickets.view_own"),
        [isOperativo, user]
    );

    const buildParams = useCallback(() => {
        const params = { per_page: CALENDAR_PER_PAGE };
        if (isSolicitante) return params;
        if (isOperativo) {
            if (scope === "assigned") params.assigned_to = "me";
            else if (scope === "created") params.created_by = "me";
        }
        return params;
    }, [isSolicitante, isOperativo, scope]);

    const loadTickets = useCallback(() => {
        if (
            !userCan(user, "tickets.view_own") &&
            !userCan(user, "tickets.view_area") &&
            !userCan(user, "tickets.manage_all")
        ) {
            setTickets([]);
            setLoading(false);
            setError("no_permission");
            return;
        }
        setLoading(true);
        setError(null);
        axios
            .get("/api/tickets", { params: buildParams() })
            .then((res) => setTickets(res.data?.data ?? []))
            .catch((err) => {
                if (err?.response?.status === 403) {
                    setError("no_permission");
                    setTickets([]);
                } else {
                    setError("load_failed");
                    setTickets([]);
                }
            })
            .finally(() => setLoading(false));
    }, [user, buildParams]);

    useEffect(() => {
        loadTickets();
    }, [loadTickets]);

    const ticketsFilteredByState = useMemo(() => {
        if (stateFilter === "all") return tickets;
        return tickets.filter((t) => {
            const code = (t.state?.code ?? "").toLowerCase();
            if (stateFilter === "open") return code === "abierto";
            if (stateFilter === "progress")
                return ["en_progreso", "en progreso", "en_espera"].includes(code);
            if (stateFilter === "resolved") return code === "cerrado" || code === "resuelto";
            return true;
        });
    }, [tickets, stateFilter]);

    const events = useMemo(() => {
        return ticketsFilteredByState
            .map((t) => {
                const at = dateMode === "resolved" ? t.resolved_at : t.created_at;
                if (!at) return null;
                const start = new Date(at);
                if (isNaN(start.getTime())) return null;
                const end = new Date(start.getTime() + EVENT_DURATION_MIN * 60000);
                return { id: t.id, title: t.subject, start, end, resource: { ticket: t } };
            })
            .filter(Boolean);
    }, [ticketsFilteredByState, dateMode]);

    const scopeLabel = useMemo(() => {
        if (isSolicitante) return "Mis tickets (creados por mí)";
        if (scope === "assigned") return "Asignados a mí";
        if (scope === "created") return "Creados por mí";
        return "Todos los que puedo ver";
    }, [isSolicitante, scope]);

    if (!user) return null;

    if (error === "no_permission") {
        return (
            <div className="mx-auto max-w-2xl p-6">
                <Card className={cn("border", kpiCardSurface.warning.card)}>
                    <CardContent className="flex flex-col items-center gap-4 py-8 text-center">
                        <ShieldOff className={cn("h-10 w-10", hintWarning)} />
                        <p className="text-sm text-muted-foreground">
                            No tienes permiso para ver el calendario de tickets.
                        </p>
                    </CardContent>
                </Card>
            </div>
        );
    }

    return (
        <InertiaPageShell className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {scopeLabel} · viendo por {dateMode === "created" ? "fecha de alta" : "fecha de cierre"}
                </p>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-1 rounded-lg bg-muted/50 p-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setDateMode("created")}
                            className={cn(
                                "h-7 gap-1.5 px-3 text-xs",
                                dateMode === "created" && "bg-background shadow-sm text-primary"
                            )}
                        >
                            <CalendarClock className="h-3.5 w-3.5" /> Alta
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setDateMode("resolved")}
                            className={cn(
                                "h-7 gap-1.5 px-3 text-xs",
                                dateMode === "resolved" && "bg-background shadow-sm text-primary"
                            )}
                        >
                            <CheckCircle2 className="h-3.5 w-3.5" /> Cierre
                        </Button>
                    </div>
                    <Select value={stateFilter} onValueChange={setStateFilter}>
                        <SelectTrigger className="h-8 w-[150px] text-xs">
                            <SelectValue placeholder="Estado" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos</SelectItem>
                            <SelectItem value="open">Abiertos</SelectItem>
                            <SelectItem value="progress">En progreso</SelectItem>
                            <SelectItem value="resolved">Resueltos</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {isOperativo && (
                <div className="flex flex-wrap gap-2">
                    {[
                        { value: "assigned", label: "Asignados a mí" },
                        { value: "created", label: "Creados por mí" },
                        { value: "all", label: "Todos los que puedo ver" },
                    ].map((opt) => (
                        <Button
                            key={opt.value}
                            type="button"
                            variant={scope === opt.value ? "secondary" : "ghost"}
                            size="sm"
                            onClick={() => setScope(opt.value)}
                        >
                            {opt.label}
                        </Button>
                    ))}
                </div>
            )}

            <Card>
                <CardContent className="p-4">
                    {loading ? (
                        <Skeleton className="h-[640px] w-full rounded-lg" />
                    ) : error === "load_failed" ? (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            No se pudieron cargar los tickets.
                        </p>
                    ) : (
                        <div className="tikara-calendar" style={{ height: 680 }}>
                            <BigCalendar
                                localizer={localizer}
                                culture={calendarCulture}
                                events={events}
                                startAccessor="start"
                                endAccessor="end"
                                view={view}
                                onView={setView}
                                date={date}
                                onNavigate={setDate}
                                views={[Views.MONTH, Views.WEEK, Views.DAY, Views.AGENDA]}
                                step={30}
                                timeslots={2}
                                popup
                                components={{ toolbar: CustomToolbar, event: CalendarEvent }}
                                onSelectEvent={(event) => router.visit(`/resolbeb/tickets/${event.id}`)}
                                messages={{
                                    noEventsInRange:
                                        dateMode === "resolved"
                                            ? "No hay tickets cerrados en este rango."
                                            : "No hay tickets en este rango.",
                                    showMore: (total) => `+${total} más`,
                                }}
                                formats={{ agendaDateFormat: "EEE d MMM" }}
                            />
                        </div>
                    )}
                </CardContent>
            </Card>
        </InertiaPageShell>
    );
}

Calendario.layout = (page) => <AuthenticatedLayout title="Calendario">{page}</AuthenticatedLayout>;
