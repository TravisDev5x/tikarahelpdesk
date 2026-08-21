import { useCallback, useEffect, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { TinyVerticalBarChart } from "@/components/dashboard/TinyVerticalBarChart";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { AlertTriangle, Download, ExternalLink, MonitorPlay, RefreshCw } from "lucide-react";
import { INVENTORY_ALERTS as ALERTS } from "@/lib/inventoryAlerts";
import { cn } from "@/lib/utils";

const REPORTS = [
    { key: "byCategory", title: "Activos por categoría" },
    { key: "byStatus", title: "Activos por estatus" },
    { key: "bySite", title: "Activos por sede" },
    { key: "topAssignees", title: "Top responsables", clickable: true },
    { key: "costByCategory", title: "Costo por categoría" },
    { key: "monthlyTrend", title: "Tendencia de altas (6 meses)" },
];

const RELOAD_PROPS = [
    "warrantyExpiring", "unassigned", "repeatedTransfers", "staleMaintenances",
    "byCategory", "byStatus", "bySite", "topAssignees", "totalValue", "costByCategory", "monthlyTrend",
];

const currency = (value) => `$${Number(value ?? 0).toLocaleString("es-MX", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Contenido del dashboard de alertas y reportes de Inventario, compartido
 * entre la página normal (Inventory/Monitor.jsx, con layout/sidebar) y el
 * wallboard standalone (Inventory/Wallboard.jsx, sin layout, pensado para
 * un monitor/TV aparte) -- mismo patrón que
 * Inertia/components/Resolbeb/DashboardContent.jsx del lado de Tickets.
 */
export default function MonitorContent({ isStandalone = false }) {
    const {
        warrantyExpiring, unassigned, repeatedTransfers, staleMaintenances,
        byCategory, byStatus, bySite, topAssignees, totalValue, costByCategory, monthlyTrend,
    } = usePage().props;
    const [openAlert, setOpenAlert] = useState(null);
    const [isTvMode, setIsTvMode] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [lastRefresh, setLastRefresh] = useState(null);

    const reportsByKey = { byCategory, byStatus, bySite, topAssignees, costByCategory, monthlyTrend };

    const goToAssignee = (barPayload) => {
        const match = (topAssignees ?? []).find((t) => t.label === barPayload.label);
        if (match?.user_id) router.visit(`/inventory/assets?user_id=${match.user_id}`);
    };

    const data = { warrantyExpiring, unassigned, repeatedTransfers, staleMaintenances };
    const active = ALERTS.find((a) => a.key === openAlert);
    const rows = active ? data[active.key] ?? [] : [];

    const refresh = useCallback(() => {
        setRefreshing(true);
        router.reload({
            only: RELOAD_PROPS,
            onFinish: () => { setRefreshing(false); setLastRefresh(new Date()); },
            onError: () => {
                // Sesión expirada en modo wallboard -- recargar de plano
                // para que caiga a login, en vez de quedar pegado.
                if (isStandalone) setTimeout(() => window.location.reload(), 2000);
            },
        });
    }, [isStandalone]);

    useEffect(() => {
        if (!isTvMode) return;
        const interval = setInterval(refresh, 300000);
        return () => clearInterval(interval);
    }, [isTvMode, refresh]);

    useEffect(() => {
        const handleFullscreenChange = () => {
            if (!document.fullscreenElement) setIsTvMode(false);
        };
        document.addEventListener("fullscreenchange", handleFullscreenChange);
        return () => document.removeEventListener("fullscreenchange", handleFullscreenChange);
    }, []);

    const toggleTvMode = useCallback(async () => {
        if (!isTvMode) {
            setIsTvMode(true);
            try {
                await document.documentElement.requestFullscreen();
            } catch (err) {
                console.error("Error al entrar en fullscreen:", err);
            }
        } else {
            setIsTvMode(false);
            if (document.fullscreenElement) await document.exitFullscreen();
        }
    }, [isTvMode]);

    return (
        <div className={cn("bg-background", isStandalone && "min-h-screen p-6")}>
            <Head title="Alertas y reportes de inventario" />
            <div
                className={cn(
                    "space-y-6",
                    isTvMode && "fixed inset-0 z-[9999] bg-background overflow-auto p-8"
                )}
            >
                {isTvMode && (
                    <Button
                        variant="destructive"
                        size="sm"
                        className="fixed top-4 right-4 z-[10000] shadow-lg font-semibold"
                        onClick={toggleTvMode}
                        title="Salir de Modo TV"
                    >
                        <MonitorPlay className="h-4 w-4 mr-2" />
                        Salir de Modo TV
                    </Button>
                )}

                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5" />
                            Alertas y reportes de inventario
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Casos que valen la pena revisar, y un vistazo general de tu inventario.
                        </p>
                    </div>
                    {!isTvMode && (
                        <div className="flex items-center gap-2">
                            {lastRefresh && (
                                <span className="text-xs text-muted-foreground">
                                    Actualizado {lastRefresh.toLocaleTimeString("es-MX", { hour: "2-digit", minute: "2-digit" })}
                                </span>
                            )}
                            {!isStandalone && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href="/inventory/wallboard" target="_blank" rel="noopener noreferrer" title="Abrir en monitor externo">
                                        <ExternalLink className="mr-2 h-4 w-4" />
                                        Abrir Wallboard
                                    </a>
                                </Button>
                            )}
                            <Button variant="outline" size="sm" onClick={toggleTvMode} title="Modo TV (wallboard inmersivo)">
                                <MonitorPlay className="mr-2 h-4 w-4" />
                                Modo TV
                            </Button>
                            <Button variant="outline" size="sm" onClick={refresh} disabled={refreshing}>
                                <RefreshCw className={cn("mr-2 h-4 w-4", refreshing && "animate-spin")} />
                                Actualizar
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <a href="/api/inv-assets/monitor/export">
                                    <Download className="mr-2 h-4 w-4" />
                                    Exportar
                                </a>
                            </Button>
                        </div>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {ALERTS.map((alert) => {
                        const count = (data[alert.key] ?? []).length;
                        return (
                            <button
                                key={alert.key}
                                type="button"
                                className="text-left"
                                onClick={() => setOpenAlert(alert.key)}
                            >
                                <KpiCard
                                    title={alert.title}
                                    value={count}
                                    icon={alert.icon}
                                    hint={alert.hint}
                                    variant={count > 0 ? "warning" : "success"}
                                />
                            </button>
                        );
                    })}
                </div>

                <div className="space-y-3">
                    <h2 className="text-sm font-semibold text-muted-foreground">Reportes</h2>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {REPORTS.map((report) => {
                            const reportData = reportsByKey[report.key] ?? [];
                            return (
                                <Card key={report.key}>
                                    <CardHeader>
                                        <CardTitle className="text-sm">{report.title}</CardTitle>
                                        {report.key === "costByCategory" && (
                                            <p className="text-xs text-muted-foreground">Total: {currency(totalValue)}</p>
                                        )}
                                        {report.clickable && (
                                            <p className="text-xs text-muted-foreground">Clic en una barra para ver sus activos</p>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {reportData.length === 0 ? (
                                            <p className="text-sm text-muted-foreground py-6 text-center">Sin datos todavía.</p>
                                        ) : (
                                            <TinyVerticalBarChart
                                                data={reportData}
                                                cardTitle={report.title}
                                                height={220}
                                                onBarClick={report.clickable ? goToAssignee : undefined}
                                            />
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                </div>
            </div>

            <Dialog open={!!openAlert} onOpenChange={(open) => !open && setOpenAlert(null)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader><DialogTitle>{active?.title}</DialogTitle></DialogHeader>
                    {rows.length === 0 ? (
                        <p className="text-sm text-muted-foreground py-6 text-center">Sin casos en esta alerta.</p>
                    ) : (
                        <div className="max-h-96 overflow-y-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {openAlert === "staleMaintenances" ? (
                                            <>
                                                <TableHead>Activo</TableHead>
                                                <TableHead>Título</TableHead>
                                                <TableHead>Inicio</TableHead>
                                            </>
                                        ) : (
                                            <>
                                                <TableHead>Nombre</TableHead>
                                                <TableHead>Núm. inventario</TableHead>
                                                {openAlert === "warrantyExpiring" && <TableHead>Vence</TableHead>}
                                                {openAlert === "repeatedTransfers" && <TableHead>Traslados (24h)</TableHead>}
                                            </>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {openAlert === "staleMaintenances"
                                        ? rows.map((m) => (
                                            <TableRow key={m.id}>
                                                <TableCell>
                                                    <Link href={`/inventory/assets?asset=${m.asset_id}`} className="hover:underline">
                                                        {m.asset?.name} <span className="text-muted-foreground font-mono text-xs">({m.asset?.internal_tag})</span>
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-sm">{m.title}</TableCell>
                                                <TableCell className="text-sm">{m.start_date}</TableCell>
                                            </TableRow>
                                        ))
                                        : rows.map((a) => (
                                            <TableRow key={a.id}>
                                                <TableCell>
                                                    <Link href={`/inventory/assets?asset=${a.id}`} className="hover:underline">
                                                        {a.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">{a.internal_tag}</TableCell>
                                                {openAlert === "warrantyExpiring" && <TableCell className="text-sm">{a.warranty_expiry}</TableCell>}
                                                {openAlert === "repeatedTransfers" && <TableCell className="text-sm">{a.transfer_count}</TableCell>}
                                            </TableRow>
                                        ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
