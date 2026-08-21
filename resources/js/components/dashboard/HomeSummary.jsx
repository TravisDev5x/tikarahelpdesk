import { useEffect, useState } from "react";
import { Link } from "@inertiajs/react";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { DashboardWelcome } from "@/components/dashboard/DashboardWelcome";
import { TinyVerticalBarChart } from "@/components/dashboard/TinyVerticalBarChart";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { INVENTORY_ALERTS } from "@/lib/inventoryAlerts";
import { AlertTriangle, ArrowRight, Flame, Ticket, XCircle } from "lucide-react";

const KpiSkeleton = ({ count = 3 }) => (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: count }).map((_, idx) => (
            <Skeleton key={idx} className="h-24 w-full rounded-xl" />
        ))}
    </div>
);

/**
 * Resumen ligero del panel general de Inicio para el perfil admin (fase 1
 * de la separación de dashboards, 2026-08-20) -- reemplaza a DashboardAdmin
 * (filtros + gráficas + /api/tickets/analytics, mucho más pesado). Solo
 * KPIs + accesos directos a los dashboards dedicados de cada módulo
 * (/resolbeb para Tickets, /inventory/monitor para Inventario), sin
 * gráficas ni interacciones propias -- esas ya viven ahí.
 */
export default function HomeSummary() {
    const { user } = useAuth();
    const [tickets, setTickets] = useState(null);
    const [inventory, setInventory] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let active = true;

        Promise.allSettled([
            axios.get("/api/tickets/summary"),
            axios.get("/api/inv-assets/monitor/summary"),
        ]).then(([ticketsResult, inventoryResult]) => {
            if (!active) return;
            if (ticketsResult.status === "fulfilled") setTickets(ticketsResult.value.data);
            if (inventoryResult.status === "fulfilled") setInventory(inventoryResult.value.data);
            setLoading(false);
        });

        return () => { active = false; };
    }, []);

    return (
        <div className="space-y-6">
            <DashboardWelcome user={user}>
                <p className="text-sm text-muted-foreground mt-1">
                    Vistazo rápido de Tickets e Inventario. Para el detalle, entra al dashboard de cada módulo.
                </p>
            </DashboardWelcome>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Ticket className="h-4 w-4" />
                        Tickets
                    </CardTitle>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/resolbeb">
                            Ver dashboard de Tickets
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {loading ? (
                        <KpiSkeleton count={3} />
                    ) : (
                        <div className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <KpiCard title="Total Tickets" value={tickets?.total ?? 0} icon={Ticket} variant="blue" />
                                <KpiCard title="Críticos/Quemados" value={tickets?.burned ?? 0} icon={Flame} variant="red" hint="Sin atención > 72h" />
                                <KpiCard title="Cancelados" value={tickets?.canceled ?? 0} icon={XCircle} variant="slate" />
                            </div>
                            {tickets?.by_state?.length > 0 && (
                                <TinyVerticalBarChart data={tickets.by_state} cardTitle="Tickets por estado" height={180} />
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="h-4 w-4" />
                            Inventario
                        </CardTitle>
                        {!loading && (
                            <p className="text-xs text-muted-foreground mt-1">
                                {inventory?.total_assets ?? 0} activos registrados
                            </p>
                        )}
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/inventory/monitor">
                            Ver dashboard de Inventario
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {loading ? (
                        <KpiSkeleton count={4} />
                    ) : (
                        <div className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {INVENTORY_ALERTS.map((alert) => {
                                    const count = inventory?.[alert.countKey] ?? 0;
                                    return (
                                        <KpiCard
                                            key={alert.key}
                                            title={alert.title}
                                            value={count}
                                            icon={alert.icon}
                                            hint={alert.hint}
                                            variant={count > 0 ? "warning" : "success"}
                                        />
                                    );
                                })}
                            </div>
                            {inventory?.by_status?.length > 0 && (
                                <TinyVerticalBarChart data={inventory.by_status} cardTitle="Activos por estatus" height={180} />
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
