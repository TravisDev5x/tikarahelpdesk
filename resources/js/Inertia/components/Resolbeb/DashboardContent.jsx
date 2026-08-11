import { useCallback, useEffect, useRef, useState } from "react";
import { Link as InertiaLink, usePage } from "@inertiajs/react";
import { getResolbebDashboard } from "@/services/resolbebApi";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { chartColor } from "@/lib/chartColors";
import { KpiCard } from "@/components/dashboard/KpiCard";
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  AreaChart,
  Area,
  Legend,
} from "recharts";
import {
  Ticket,
  AlertCircle,
  UserX,
  Snowflake,
  Clock,
  RefreshCw,
  Users,
  TrendingUp,
  PieChart as PieChartIcon,
  AlertTriangle,
  ArrowRightCircle,
  Building2,
  RotateCcw,
  Medal,
  Award,
  Maximize,
  Minimize,
  MonitorPlay,
  ExternalLink,
} from "lucide-react";

const RESOLVE_BASE = "/resolbeb";

function CustomTooltip({ active, payload, label }) {
  if (active && payload && payload.length) {
    return (
      <div className="bg-popover text-popover-foreground p-3 rounded-md shadow-xl border border-border">
        {(label != null && label !== "") && (
          <p className="font-semibold border-b border-border pb-1 mb-2 text-muted-foreground">{label}</p>
        )}
        {payload.map((entry, index) => (
          <p key={index} className="text-sm">
            <span className="font-bold" style={{ color: entry.color || chartColor(0) }}>{entry.name}:</span> {entry.value}
          </p>
        ))}
      </div>
    );
  }
  return null;
}

function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {[1, 2, 3, 4].map((i) => (
          <Skeleton key={i} className="h-28 w-full rounded-xl" />
        ))}
      </div>
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Skeleton className="h-[350px] w-full rounded-xl" />
        <Skeleton className="h-[350px] w-full rounded-xl" />
        <Skeleton className="h-[350px] w-full rounded-xl" />
      </div>
      <Skeleton className="h-64 w-full rounded-xl" />
    </div>
  );
}

function ExpandableCard({ title, description, chartHeight = 350, children }) {
  const cardRef = useRef(null);
  const [isFullscreen, setIsFullscreen] = useState(false);

  useEffect(() => {
    const onFullscreenChange = () => {
      if (document.fullscreenElement === cardRef.current) setIsFullscreen(true);
      else setIsFullscreen(false);
    };
    document.addEventListener("fullscreenchange", onFullscreenChange);
    return () => document.removeEventListener("fullscreenchange", onFullscreenChange);
  }, []);

  const toggleFullscreen = useCallback(async () => {
    if (!cardRef.current) return;
    try {
      if (!document.fullscreenElement) {
        await cardRef.current.requestFullscreen();
      } else if (document.fullscreenElement === cardRef.current) {
        await document.exitFullscreen();
      }
    } catch (e) {
      console.warn("Fullscreen error", e);
    }
  }, []);

  return (
    <div
      ref={cardRef}
      className={cn(
        "rounded-lg border border-border/50 bg-card overflow-hidden",
        isFullscreen && "flex flex-col h-screen w-screen p-8 bg-background"
      )}
    >
      <div className={cn("flex items-start justify-between gap-2", isFullscreen ? "pb-4" : "px-6 pt-6 pb-2")}>
        <div>
          <h3 className="text-base font-semibold text-foreground flex items-center gap-2">{title}</h3>
          {description && <p className="text-sm text-muted-foreground mt-0.5">{description}</p>}
        </div>
        <Button
          variant="ghost"
          size="icon"
          className="h-8 w-8 shrink-0"
          onClick={toggleFullscreen}
          title={isFullscreen ? "Salir de pantalla completa" : "Pantalla completa"}
        >
          {isFullscreen ? <Minimize className="h-4 w-4" /> : <Maximize className="h-4 w-4" />}
        </Button>
      </div>
      <div className={cn("w-full", !isFullscreen && "px-6 pb-6", isFullscreen && "flex-1 min-h-0 flex flex-col")}>
        <div className={cn("w-full", isFullscreen ? "flex-1 min-h-0" : chartHeight === 300 ? "h-[300px]" : "h-[350px]")}>
          {children}
        </div>
      </div>
    </div>
  );
}

export default function DashboardContent({ isStandalone = false }) {
  const { auth, catalogs: catalogsProp } = usePage().props;
  const can = (perm) => auth?.user?.permissions?.includes(perm) ?? false;

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const catalogs = catalogsProp ?? { sites: [], area_users: [] };
  const [filters, setFilters] = useState({});
  const [lastRefresh, setLastRefresh] = useState(null);
  const [isTvMode, setIsTvMode] = useState(false);

  const canSeeTickets = can("tickets.manage_all") || can("tickets.view_area");
  const canFilterSite = can("tickets.filter_by_site") || can("tickets.manage_all");

  const fetchDashboard = useCallback(async () => {
    setLoading(true);
    setError(null);
    const params = {};
    if (filters.site_id != null && filters.site_id !== "") params.site_id = filters.site_id;
    if (filters.assigned_user_id != null && filters.assigned_user_id !== "") params.assigned_user_id = filters.assigned_user_id;
    if (filters.assigned_to) params.assigned_to = filters.assigned_to;
    if (filters.assigned_status) params.assigned_status = filters.assigned_status;

    try {
      const { data: res, error: err } = await getResolbebDashboard(params);
      if (err) {
        setError(err);
        setData(null);
      } else {
        setData(res);
      }
    } catch (e) {
      const status = e?.response?.status;
      if ((status === 401 || status === 419) && isStandalone) {
        console.warn("Sesión expirada en modo Wallboard. Recargando para ir al Login...");
        setTimeout(() => {
          window.location.reload();
        }, 2000);
        return;
      }
      console.error("Error cargando dashboard:", e);
      setError(e?.response?.data?.message || e?.message || "Error al cargar el dashboard.");
      setData(null);
    } finally {
      setLastRefresh(new Date());
      setLoading(false);
    }
  }, [filters.site_id, filters.assigned_user_id, filters.assigned_to, filters.assigned_status, isStandalone]);

  useEffect(() => {
    if (canSeeTickets) fetchDashboard();
  }, [canSeeTickets, fetchDashboard]);

  useEffect(() => {
    if (!isTvMode) return;
    const interval = setInterval(() => {
      fetchDashboard();
    }, 300000);
    return () => clearInterval(interval);
  }, [isTvMode, fetchDashboard]);

  useEffect(() => {
    const handleFullscreenChange = () => {
      if (!document.fullscreenElement) {
        setIsTvMode(false);
      }
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
      if (document.fullscreenElement) {
        await document.exitFullscreen();
      }
    }
  }, [isTvMode]);

  if (!canSeeTickets) {
    const noPermContent = (
      <div className="space-y-6 pb-20">
        <h1 className="text-3xl font-black tracking-tighter uppercase text-foreground flex items-center gap-3">
          <Ticket className="h-8 w-8 text-primary" />
          Resolbeb
        </h1>
        <Card>
          <CardContent className="p-8 text-center text-muted-foreground">
            No tienes permisos para ver el dashboard de tickets.
          </CardContent>
        </Card>
      </div>
    );
    if (isStandalone) {
      return (
        <div className="bg-background min-h-screen p-6">
          {noPermContent}
        </div>
      );
    }
    return noPermContent;
  }

  const sites = catalogs.sites || [];
  const agents = catalogs.area_users || [];

  const dashboardContent = (
    <div
      className={cn(
        "space-y-6 pb-20 animate-in fade-in duration-300",
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
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-black tracking-tighter uppercase text-foreground flex items-center gap-3">
            <Ticket className="h-8 w-8 text-primary" />
            Dashboard operativo
          </h1>
          <p className="text-muted-foreground font-medium text-sm mt-1">
            Carga de trabajo, SLA y tendencia de resolución en tiempo real.
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
                <a href="/tickets/wallboard" target="_blank" rel="noopener noreferrer" title="Abrir en monitor externo">
                  <ExternalLink className="h-4 w-4 mr-2" />
                  Abrir Wallboard
                </a>
              </Button>
            )}
            <Button variant="outline" size="sm" onClick={toggleTvMode} title="Modo TV (wallboard inmersivo)">
              <MonitorPlay className="h-4 w-4 mr-2" />
              Modo TV
            </Button>
            <Button variant="outline" size="sm" onClick={() => fetchDashboard()} disabled={loading}>
              <RefreshCw className={cn("h-4 w-4 mr-2", loading && "animate-spin")} />
              Actualizar
            </Button>
          </div>
        )}
      </div>

      {/* Filtros rápidos */}
      <Card className="border-border/50">
        <CardHeader className="py-3">
          <CardTitle className="text-sm font-medium flex items-center gap-2">Filtros rápidos</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-4">
          {canFilterSite && sites.length > 0 && (
            <div className="space-y-2 w-full sm:w-auto">
              <Label className="text-xs">Sede</Label>
              <Select
                value={filters.site_id != null ? String(filters.site_id) : "all"}
                onValueChange={(v) => setFilters((f) => ({ ...f, site_id: v === "all" ? null : v }))}
              >
                <SelectTrigger className="w-full h-9 sm:w-[180px]">
                  <SelectValue placeholder="Todas las sedes" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todas las sedes</SelectItem>
                  {sites.map((s) => (
                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          <div className="space-y-2 w-full sm:w-auto">
            <Label className="text-xs">Agente asignado</Label>
            <Select
              value={filters.assigned_user_id != null ? String(filters.assigned_user_id) : "all"}
              onValueChange={(v) => setFilters((f) => ({ ...f, assigned_user_id: v === "all" ? null : v }))}
            >
              <SelectTrigger className="w-full h-9 sm:w-[200px]">
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todos los agentes</SelectItem>
                {agents.map((a) => (
                  <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {error && (
        <Card className="border-destructive/50 bg-destructive/5">
          <CardContent className="p-4 flex items-center gap-2 text-destructive">
            <AlertCircle className="h-4 w-4 shrink-0" />
            {error}
          </CardContent>
        </Card>
      )}

      {loading && <DashboardSkeleton />}

      {!loading && data && (
        <>
          {/* Fila 1: KPI Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-4">
            <KpiCard
              title="Vencidos hoy"
              value={data.kpis?.vencidos_hoy ?? 0}
              icon={AlertCircle}
              variant={(data.kpis?.vencidos_hoy ?? 0) > 0 ? "danger" : "default"}
              hint="SLA superado"
            />
            <KpiCard
              title="Sin asignar"
              value={data.kpis?.sin_asignar ?? 0}
              icon={UserX}
              variant={(data.kpis?.sin_asignar ?? 0) > 0 ? "warning" : "default"}
              hint="Nadie ha tomado el ticket"
            />
            <KpiCard
              title="Congelados (>48h)"
              value={data.kpis?.congelados ?? 0}
              icon={Snowflake}
              variant={(data.kpis?.congelados ?? 0) > 0 ? "warning" : "default"}
              hint="Esperando proveedor / Pausado"
            />
            <KpiCard
              title="MTTR semanal"
              value={data.kpis?.mttr_semanal != null ? `${data.kpis.mttr_semanal}h` : "—"}
              icon={Clock}
              variant="default"
              hint="Promedio resolución esta semana"
            />
            {(data.kpi_reaperturas != null) && (
              <KpiCard
                title="% de Reapertura"
                value={data.kpi_reaperturas.porcentaje != null ? `${data.kpi_reaperturas.porcentaje}%` : "—"}
                icon={RotateCcw}
                variant={(data.kpi_reaperturas.porcentaje ?? 0) > 10 ? "danger" : (data.kpi_reaperturas.porcentaje ?? 0) > 5 ? "warning" : "default"}
                hint={data.kpi_reaperturas.cantidad_reaperturas != null ? `${data.kpi_reaperturas.cantidad_reaperturas} de ${data.kpi_reaperturas.total_resueltos} resueltos` : null}
              />
            )}
          </div>

          {/* Fila 2: Gráficas */}
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {/* Balance de carga (BarChart horizontal) */}
            <ExpandableCard
              title={<><Users className="h-4 w-4 text-primary" /> Balance de carga</>}
              description="Tickets abiertos/en proceso por agente"
              chartHeight={350}
            >
              {(data.balance_carga?.length ?? 0) > 0 ? (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={data.balance_carga}
                    layout="vertical"
                    margin={{ top: 8, right: 24, left: 8, bottom: 8 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis type="number" tick={{ fontSize: 11 }} />
                    <YAxis type="category" dataKey="agente" width={100} tick={{ fontSize: 11 }} />
                    <Tooltip content={<CustomTooltip />} cursor={{ fill: "rgba(255, 255, 255, 0.05)" }} />
                    <Bar dataKey="total" name="Tickets" fill={chartColor(0)} radius={[0, 4, 4, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div className="h-full flex items-center justify-center text-muted-foreground text-sm">
                  Sin datos de asignación
                </div>
              )}
            </ExpandableCard>

            {/* Tendencia de resolución (AreaChart) */}
            <ExpandableCard
              title={<><TrendingUp className="h-4 w-4 text-primary" /> Tendencia de resolución</>}
              description="Últimos 15 días: creados vs cerrados"
              chartHeight={350}
            >
              {(data.tendencia?.length ?? 0) > 0 ? (
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart
                    data={data.tendencia}
                    margin={{ top: 8, right: 8, left: 8, bottom: 8 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis dataKey="etiqueta" tick={{ fontSize: 10 }} />
                    <YAxis tick={{ fontSize: 11 }} />
                    <Tooltip content={<CustomTooltip />} cursor={{ fill: "rgba(255, 255, 255, 0.05)" }} />
                    <Legend />
                    <Area type="monotone" dataKey="creados" name="Creados" stackId="1" stroke={chartColor(0)} fill={chartColor(0)} fillOpacity={0.5} />
                    <Area type="monotone" dataKey="cerrados" name="Cerrados" stackId="2" stroke={chartColor(1)} fill={chartColor(1)} fillOpacity={0.5} />
                  </AreaChart>
                </ResponsiveContainer>
              ) : (
                <div className="h-full flex items-center justify-center text-muted-foreground text-sm">
                  Sin datos
                </div>
              )}
            </ExpandableCard>

            {/* Top incidentes (PieChart) */}
            <ExpandableCard
              title={<><PieChartIcon className="h-4 w-4 text-primary" /> Top incidentes</>}
              description="Por categoría / tipo de ticket"
              chartHeight={350}
            >
              {(data.top_incidentes?.length ?? 0) > 0 ? (
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie
                      data={data.top_incidentes}
                      dataKey="total"
                      nameKey="categoria"
                      cx="50%"
                      cy="50%"
                      outerRadius={100}
                      label={({ categoria, total }) => `${categoria}: ${total}`}
                    >
                      {(data.top_incidentes || []).map((_, i) => (
                        <Cell key={i} fill={chartColor(i)} />
                      ))}
                    </Pie>
                    <Tooltip content={<CustomTooltip />} cursor={{ fill: "rgba(255, 255, 255, 0.05)" }} />
                  </PieChart>
                </ResponsiveContainer>
              ) : (
                <div className="h-full flex items-center justify-center text-muted-foreground text-sm">
                  Sin datos
                </div>
              )}
            </ExpandableCard>
          </div>

          {/* Inteligencia Operativa */}
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-foreground flex items-center gap-2">
              <Award className="h-5 w-5 text-primary" />
              Inteligencia Operativa
            </h2>
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {/* Top Sedes (BarChart) */}
              <ExpandableCard
                title="Top Sedes"
                description="Tickets creados este mes por sede"
                chartHeight={300}
              >
                {(data.top_sites?.length ?? 0) > 0 ? (
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data.top_sites} margin={{ top: 8, right: 8, left: 8, bottom: 8 }}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                      <XAxis dataKey="site" tick={{ fontSize: 10 }} />
                      <YAxis tick={{ fontSize: 11 }} />
                      <Tooltip content={<CustomTooltip />} cursor={{ fill: "rgba(255, 255, 255, 0.05)" }} />
                      <Bar dataKey="total" name="Tickets" fill={chartColor(0)} radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                ) : (
                  <div className="h-full flex items-center justify-center text-muted-foreground text-sm">Sin datos</div>
                )}
              </ExpandableCard>

              {/* Tipos de Fallas (PieChart) */}
              <ExpandableCard
                title="Tipos de Fallas"
                description="Categorías más reportadas este mes"
                chartHeight={300}
              >
                {(data.top_fallas?.length ?? 0) > 0 ? (
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={data.top_fallas}
                        dataKey="total"
                        nameKey="categoria"
                        cx="50%"
                        cy="50%"
                        outerRadius={80}
                        label={({ categoria, total }) => `${categoria}: ${total}`}
                      >
                        {(data.top_fallas || []).map((_, i) => (
                          <Cell key={i} fill={chartColor(i)} />
                        ))}
                      </Pie>
                      <Tooltip content={<CustomTooltip />} cursor={{ fill: "rgba(255, 255, 255, 0.05)" }} />
                    </PieChart>
                  </ResponsiveContainer>
                ) : (
                  <div className="h-full flex items-center justify-center text-muted-foreground text-sm">Sin datos</div>
                )}
              </ExpandableCard>

              {/* Top Resolvers (ranking) */}
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base flex items-center gap-2">
                    <Medal className="h-4 w-4 text-primary" />
                    Top Resolvers
                  </CardTitle>
                  <CardDescription>Agentes que más cerraron tickets (últimos 30 días)</CardDescription>
                </CardHeader>
                <CardContent>
                  {(data.top_resolvers?.length ?? 0) > 0 ? (
                    <ul className="space-y-3">
                      {(data.top_resolvers || []).map((r, idx) => (
                        <li key={idx} className="flex items-center gap-3 rounded-lg border border-border/50 bg-muted/30 px-3 py-2">
                          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                            {idx === 0 ? "1°" : idx === 1 ? "2°" : "3°"}
                          </span>
                          <div className="min-w-0 flex-1">
                            <p className="font-medium text-foreground truncate">{r.nombre}</p>
                            <p className="text-xs text-muted-foreground">{r.tickets_cerrados} tickets cerrados</p>
                          </div>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground text-sm">Sin datos</div>
                  )}
                </CardContent>
              </Card>
            </div>
          </div>

          {/* Tabla: Prioridad extrema (Top 5 críticos) */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 text-destructive" />
                Prioridad extrema
              </CardTitle>
              <CardDescription>5 tickets más críticos (urgente + SLA a punto de vencer)</CardDescription>
            </CardHeader>
            <CardContent>
              {(data.top_5_criticos?.length ?? 0) > 0 ? (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-[80px]">#</TableHead>
                      <TableHead>Asunto</TableHead>
                      <TableHead>Estado</TableHead>
                      <TableHead>Prioridad</TableHead>
                      <TableHead>Asignado</TableHead>
                      <TableHead>Sede</TableHead>
                      <TableHead>SLA</TableHead>
                      <TableHead className="w-[100px] text-right">Acción</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(data.top_5_criticos || []).map((t) => (
                      <TableRow
                        key={t.id}
                        className={cn(t.is_overdue && "bg-destructive/5 border-l-2 border-l-destructive")}
                      >
                        <TableCell className="font-mono text-xs font-bold text-primary/80">
                          #{String(t.id).padStart(5, "0")}
                        </TableCell>
                        <TableCell className="font-medium max-w-[240px] truncate" title={t.subject}>
                          {t.subject}
                        </TableCell>
                        <TableCell className="text-xs">{t.state?.name ?? "—"}</TableCell>
                        <TableCell className="text-xs">{t.priority?.name ?? "—"}</TableCell>
                        <TableCell className="text-xs">{t.assigned_user?.name ?? "Sin asignar"}</TableCell>
                        <TableCell className="text-xs flex items-center gap-1">
                          <Building2 className="h-3 w-3 text-muted-foreground" />
                          {t.site?.name ?? "—"}
                        </TableCell>
                        <TableCell>
                          <span className={cn("text-xs font-medium", t.is_overdue && "text-destructive")}>
                            {t.sla_status_text ?? "—"}
                          </span>
                        </TableCell>
                        <TableCell className="text-right">
                          <Button asChild variant="ghost" size="sm">
                            <InertiaLink href={`${RESOLVE_BASE}/tickets/${t.id}`} className="flex items-center gap-1">
                              Gestionar <ArrowRightCircle className="h-3.5 w-3.5" />
                            </InertiaLink>
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ) : (
                <p className="text-sm text-muted-foreground py-6 text-center">
                  No hay tickets en prioridad extrema.
                </p>
              )}
            </CardContent>
          </Card>
        </>
      )}

      {!loading && !data && !error && canSeeTickets && (
        <Card>
          <CardContent className="p-8 text-center text-muted-foreground">
            No se pudieron cargar los datos del dashboard.
          </CardContent>
        </Card>
      )}
    </div>
  );

  if (isStandalone) {
    return (
      <div className="bg-background min-h-screen p-6">
        {dashboardContent}
      </div>
    );
  }
  return dashboardContent;
}
