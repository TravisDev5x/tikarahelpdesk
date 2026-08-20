import { useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
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
import { AlertTriangle, CalendarClock, Download, Repeat, UserX, Wrench } from "lucide-react";

const ALERTS = [
    { key: "warrantyExpiring", title: "Garantías por vencer", icon: CalendarClock, hint: "En los próximos 15 días" },
    { key: "unassigned", title: "Activos sin responsable", icon: UserX, hint: "Sin usuario asignado" },
    { key: "repeatedTransfers", title: "Traslados repetidos", icon: Repeat, hint: "2+ en las últimas 24h" },
    { key: "staleMaintenances", title: "Mantenimientos estancados", icon: Wrench, hint: "Abiertos hace más de 30 días" },
];

const REPORTS = [
    { key: "byCategory", title: "Activos por categoría" },
    { key: "byStatus", title: "Activos por estatus" },
    { key: "bySite", title: "Activos por sede" },
    { key: "topAssignees", title: "Top responsables", clickable: true },
    { key: "costByCategory", title: "Costo por categoría" },
    { key: "monthlyTrend", title: "Tendencia de altas (6 meses)" },
];

const currency = (value) => `$${Number(value ?? 0).toLocaleString("es-MX", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function Monitor() {
    const {
        warrantyExpiring, unassigned, repeatedTransfers, staleMaintenances,
        byCategory, byStatus, bySite, topAssignees, totalValue, costByCategory, monthlyTrend,
    } = usePage().props;
    const [openAlert, setOpenAlert] = useState(null);

    const reportsByKey = { byCategory, byStatus, bySite, topAssignees, costByCategory, monthlyTrend };

    const goToAssignee = (barPayload) => {
        const match = (topAssignees ?? []).find((t) => t.label === barPayload.label);
        if (match?.user_id) router.visit(`/inventory/assets?user_id=${match.user_id}`);
    };

    const data = { warrantyExpiring, unassigned, repeatedTransfers, staleMaintenances };
    const active = ALERTS.find((a) => a.key === openAlert);
    const rows = active ? data[active.key] ?? [] : [];

    return (
        <>
            <Head title="Alertas y reportes de inventario" />
            <div className="space-y-6">
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
                    <Button asChild variant="outline">
                        <a href="/api/inv-assets/monitor/export">
                            <Download className="mr-2 h-4 w-4" />
                            Exportar
                        </a>
                    </Button>
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
                                                    <Link href={`/inventory/assets/${m.asset_id}`} className="hover:underline">
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
                                                    <Link href={`/inventory/assets/${a.id}`} className="hover:underline">
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
        </>
    );
}

Monitor.layout = (page) => <AuthenticatedLayout title="Alertas y reportes de inventario">{page}</AuthenticatedLayout>;
