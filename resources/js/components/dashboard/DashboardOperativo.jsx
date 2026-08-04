import { useCallback, useEffect, useMemo, useState } from "react";
import NavLink from "@/components/NavLink";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { kpiCardSurface, hintWarning } from "@/lib/badgeStyles";
import {
    AlertCircle,
    CalendarDays,
    RefreshCw,
    Ticket,
    User,
    Users,
} from "lucide-react";

const LIST_BASE = "/resolbeb/tickets";
const PER_PAGE = 40;

const COPY = {
    supervisor: {
        title: "Panel de supervisión",
        subtitle: "Todos los tickets del sistema y quién los está atendiendo.",
        listTitle: "Tickets en general",
        listHint: "Vista global para gerentes y supervisores.",
    },
    soporte: {
        title: "Panel de soporte",
        subtitle: "Solicitudes de usuarios en tu ámbito (área / sede asignada).",
        listTitle: "Cola de solicitudes",
        listHint: "Tickets de solicitantes que puedes atender o escalar.",
    },
};

/**
 * Dashboard operativo: gerentes, supervisores y soporte (L1–L3).
 */
export function DashboardOperativo({ variant = "soporte" }) {
    const { user } = useAuth();
    const copy = COPY[variant] ?? COPY.soporte;
    const [tickets, setTickets] = useState([]);
    const [loading, setLoading] = useState(true);

    const loadTickets = useCallback(() => {
        setLoading(true);
        const params = { per_page: PER_PAGE };
        if (variant === "supervisor") {
            // manage_all: sin filtro → todos los tickets visibles por policy
        } else {
            // soporte: API aplica alcance por área (view_area)
        }
        axios
            .get("/api/tickets", { params })
            .then((res) => setTickets(res.data?.data ?? []))
            .catch(() => setTickets([]))
            .finally(() => setLoading(false));
    }, [variant]);

    useEffect(() => {
        loadTickets();
    }, [loadTickets]);

    const stats = useMemo(() => {
        const open = tickets.filter((t) => {
            const code = (t.state?.code ?? "").toLowerCase();
            return !["cerrado", "resuelto", "cancelado"].includes(code);
        });
        const unassigned = open.filter((t) => !(t.assigned_user || t.assignedUser));
        return { total: tickets.length, open: open.length, unassigned: unassigned.length };
    }, [tickets]);

    return (
        <div className="w-full max-w-6xl mx-auto space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-foreground">{copy.title}</h1>
                    <p className="text-sm text-muted-foreground mt-1">{copy.subtitle}</p>
                    {user?.name && (
                        <p className="text-xs text-muted-foreground mt-2">
                            Hola, <span className="font-medium text-foreground">{user.name}</span>
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" onClick={loadTickets} disabled={loading}>
                        <RefreshCw className={cn("h-4 w-4 mr-2", loading && "animate-spin")} />
                        Actualizar
                    </Button>
                    <Button asChild variant="secondary" size="sm">
                        <NavLink href="/resolbeb/tickets">Ver listado completo</NavLink>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <NavLink href="/calendar">
                            <CalendarDays className="h-4 w-4 mr-2" />
                            Calendario
                        </NavLink>
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <Card>
                    <CardContent className="p-4 flex items-center gap-3">
                        <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center">
                            <Ticket className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">En esta vista</p>
                            <p className="text-2xl font-bold">{stats.total}</p>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4 flex items-center gap-3">
                        <div className={cn("h-10 w-10 rounded-lg flex items-center justify-center", kpiCardSurface.warning.icon)}>
                            <AlertCircle className="h-5 w-5" />
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Abiertos</p>
                            <p className="text-2xl font-bold">{stats.open}</p>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4 flex items-center gap-3">
                        <div className="h-10 w-10 rounded-lg bg-destructive/10 flex items-center justify-center">
                            <Users className="h-5 w-5 text-destructive" />
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Sin asignar</p>
                            <p className="text-2xl font-bold">{stats.unassigned}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card className="border-border/60">
                <CardHeader className="pb-2">
                    <CardTitle className="text-base">{copy.listTitle}</CardTitle>
                    <CardDescription className="text-xs">{copy.listHint}</CardDescription>
                </CardHeader>
                <CardContent>
                    {loading ? (
                        <div className="space-y-2">
                            {[1, 2, 3, 4, 5].map((i) => (
                                <Skeleton key={i} className="h-14 w-full rounded-md" />
                            ))}
                        </div>
                    ) : tickets.length === 0 ? (
                        <p className="text-sm text-muted-foreground py-8 text-center">
                            No hay tickets en tu alcance.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {tickets.map((t) => {
                                const assigned = t.assigned_user || t.assignedUser;
                                const requester =
                                    t.requester ||
                                    t.requester_user ||
                                    t.created_by_user ||
                                    t.user;
                                return (
                                    <li key={t.id} className="py-3 first:pt-0">
                                        <NavLink
                                            href={`${LIST_BASE}/${t.id}`}
                                            className="block rounded-md hover:bg-muted/40 -mx-1 px-2 py-1 transition-colors"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-medium text-sm">
                                                    #{String(t.id).padStart(5, "0")} — {t.subject}
                                                </span>
                                                <Badge variant="secondary" className="text-[10px]">
                                                    {t.state?.name ?? "—"}
                                                </Badge>
                                            </div>
                                            <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                <span className="inline-flex items-center gap-1">
                                                    <User className="h-3 w-3" />
                                                    Solicitante:{" "}
                                                    <span className="text-foreground/90 font-medium">
                                                        {requester?.name ?? "—"}
                                                    </span>
                                                </span>
                                                <span className="inline-flex items-center gap-1">
                                                    <Users className="h-3 w-3" />
                                                    Atiende:{" "}
                                                    <span
                                                        className={cn(
                                                            "font-medium",
                                                            assigned
                                                                ? "text-foreground/90"
                                                                : hintWarning
                                                        )}
                                                    >
                                                        {assigned?.name ?? "Sin asignar"}
                                                    </span>
                                                </span>
                                                {t.priority?.name && (
                                                    <span>Prioridad: {t.priority.name}</span>
                                                )}
                                            </div>
                                        </NavLink>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
