
import { useEffect, useState, useRef } from "react";
import { usePage } from "@inertiajs/react";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
import { notify } from "@/lib/notify";
import { IncidentSeverityBadge, IncidentStatusBadge } from "@/components/badges/EntityBadges";
import { Loader2, Paperclip, Trash2, AlertTriangle, Building2, CalendarDays, User, MessageSquare, UserCog, ChevronLeft } from "lucide-react";

export default function Detalle() {
    const { incidentId, catalogs: initialCatalogs } = usePage().props;
    const [incident, setIncident] = useState(null);
    const [cats, setCats] = useState(initialCatalogs ?? {});
    const [note, setNote] = useState("");
    const [updating, setUpdating] = useState(false);
    const [assigneeId, setAssigneeId] = useState("none");
    const [files, setFiles] = useState([]);
    const [uploading, setUploading] = useState(false);
    const updateSectionRef = useRef(null);
    const assignSectionRef = useRef(null);

    const load = async () => {
        try {
            const incidentRes = await axios.get(`/api/incidents/${incidentId}`);
            setIncident(incidentRes.data);
        } catch (err) {
            notify.error("No se pudo cargar la incidencia");
        }
    };

    useEffect(() => { load(); }, [incidentId]);

    const update = async (payload) => {
        setUpdating(true);
        try {
            const { data } = await axios.put(`/api/incidents/${incidentId}`, { ...payload, note });
            setIncident(data);
            setNote("");
            setAssigneeId("none");
            notify.success("Incidencia actualizada");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo actualizar");
        } finally { setUpdating(false); }
    };

    const takeIncident = async () => {
        setUpdating(true);
        try {
            const { data } = await axios.post(`/api/incidents/${incidentId}/take`);
            setIncident(data);
            notify.success("Incidencia tomada");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo tomar");
        } finally { setUpdating(false); }
    };

    const assignIncident = async () => {
        if (assigneeId === "none") return;
        setUpdating(true);
        try {
            const { data } = await axios.post(`/api/incidents/${incidentId}/assign`, { assigned_user_id: Number(assigneeId) });
            setIncident(data);
            setAssigneeId("none");
            notify.success("Responsable asignado");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo reasignar");
        } finally { setUpdating(false); }
    };

    const unassignIncident = async () => {
        setUpdating(true);
        try {
            const { data } = await axios.post(`/api/incidents/${incidentId}/unassign`);
            setIncident(data);
            notify.success("Incidencia liberada");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo liberar");
        } finally { setUpdating(false); }
    };

    const uploadAttachments = async () => {
        if (!files.length) return;
        setUploading(true);
        try {
            const payload = new FormData();
            files.forEach((f) => payload.append("attachments[]", f));
            const { data } = await axios.post(`/api/incidents/${incidentId}/attachments`, payload, {
                headers: { "Content-Type": "multipart/form-data" }
            });
            setIncident((prev) => ({
                ...prev,
                attachments: [...(prev?.attachments || []), ...(data || [])],
            }));
            setFiles([]);
            notify.success("Adjuntos cargados");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudieron cargar los adjuntos");
        } finally { setUploading(false); }
    };

    const removeAttachment = async (attachment) => {
        try {
            await axios.delete(`/api/incidents/${incidentId}/attachments/${attachment.id}`);
            setIncident((prev) => ({
                ...prev,
                attachments: (prev?.attachments || []).filter((a) => a.id !== attachment.id),
            }));
        } catch (err) {
            notify.error("No se pudo eliminar el adjunto");
        }
    };

    if (!incident) {
        return (
            <div className="w-full space-y-6">
                <Skeleton className="h-10 w-1/3" />
                <Skeleton className="h-40 w-full rounded-xl" />
                <Skeleton className="h-64 w-full rounded-xl" />
            </div>
        );
    }

    const abilities = incident.abilities || {};
    const canChangeStatus = Boolean(abilities.change_status);
    const canComment = Boolean(abilities.comment);
    const canAssign = Boolean(abilities.assign);

    const assignedUser = incident.assigned_user || incident.assignedUser;
    const histories = incident.histories || [];
    const attachments = incident.attachments || [];
    const areaUsers = cats.area_users || [];
    const status = incident.incident_status || incident.incidentStatus;
    const severity = incident.incident_severity || incident.incidentSeverity;
    const type = incident.incident_type || incident.incidentType;

    const scrollToRef = (ref) => {
        ref?.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    };

    return (
        <div className="w-full space-y-6 pb-content-mobile">
            <div className="flex flex-col md:flex-row md:items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                    <a
                        href="/incidents"
                        className="hidden md:flex items-center justify-center h-9 w-9 rounded-md border border-border/50 bg-background hover:bg-muted/50 transition-colors shrink-0"
                        title="Volver a incidencias"
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </a>
                    <div className="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center shadow-sm">
                        <AlertTriangle className="h-5 w-5" />
                    </div>
                    <div className="space-y-0.5">
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Incidencia #{incident.id}
                        </h1>
                        <p className="text-sm text-muted-foreground">Detalle y seguimiento de la incidencia.</p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <IncidentStatusBadge status={status} />
                    <IncidentSeverityBadge severity={severity} />
                </div>
            </div>

            <Card className="border border-border/50 shadow-sm">
                <CardHeader className="pb-3">
                    <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="text-lg font-semibold">{incident.subject}</CardTitle>
                            <CardDescription className="text-xs text-muted-foreground">Folio #{incident.id}</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <Separator className="mx-6 opacity-50" />
                <CardContent className="pt-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div className="space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Tipo</Label>
                        <div className="font-medium">{type?.name || "-"}</div>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Severidad</Label>
                        <div className="font-medium">{severity?.name || "-"}</div>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Area</Label>
                        <div className="font-medium">{incident.area?.name || "-"}</div>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Sede</Label>
                        <div className="font-medium flex items-center gap-1.5">
                            <Building2 className="h-3.5 w-3.5 text-muted-foreground" />
                            {incident.site?.name || "-"}
                        </div>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Reporta</Label>
                        <div className="font-medium flex items-center gap-1.5">
                            <User className="h-3.5 w-3.5 text-muted-foreground" />
                            {incident.reporter?.name || "-"}
                        </div>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Involucrado</Label>
                        <div className="font-medium">{incident.involved_user?.name || incident.involvedUser?.name || "Sin involucrado"}</div>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Responsable</Label>
                        <div className="font-medium">{assignedUser ? assignedUser.name : "Sin asignar"}</div>
                    </div>
                    {incident.occurred_at && (
                        <div className="space-y-1">
                            <Label className="text-[10px] uppercase text-muted-foreground font-bold">Fecha incidente</Label>
                            <div className="font-medium flex items-center gap-1.5">
                                <CalendarDays className="h-3.5 w-3.5 text-muted-foreground" />
                                {new Date(incident.occurred_at).toLocaleDateString()}
                            </div>
                        </div>
                    )}
                    {incident.enabled_at && (
                        <div className="space-y-1">
                            <Label className="text-[10px] uppercase text-muted-foreground font-bold">Fecha de habilitacion</Label>
                            <div className="font-medium flex items-center gap-1.5">
                                <CalendarDays className="h-3.5 w-3.5 text-muted-foreground" />
                                {new Date(incident.enabled_at).toLocaleDateString()}
                            </div>
                        </div>
                    )}
                    <div className="md:col-span-2 space-y-1">
                        <Label className="text-[10px] uppercase text-muted-foreground font-bold">Descripcion</Label>
                        <div className="text-sm text-muted-foreground whitespace-pre-wrap bg-muted/10 border border-border/50 rounded-lg p-3">
                            {incident.description || "Sin descripcion."}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {(canChangeStatus || canComment) && (
                <Card ref={updateSectionRef} className="border border-border/50 shadow-sm">
                    <CardHeader className="pb-3">
                        <CardTitle>Actualizar</CardTitle>
                        <CardDescription className="text-xs">Cambios de estado o severidad quedan registrados en el historial.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-3">
                        <div className="space-y-2">
                            <Label className="text-[10px] uppercase text-muted-foreground font-bold">Estado</Label>
                            <Select value={String(incident.incident_status_id)} onValueChange={(v) => update({ incident_status_id: Number(v) })} disabled={!canChangeStatus || updating}>
                                <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Estado" /></SelectTrigger>
                                <SelectContent>{(cats.incident_statuses || []).map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label className="text-[10px] uppercase text-muted-foreground font-bold">Severidad</Label>
                            <Select value={String(incident.incident_severity_id)} onValueChange={(v) => update({ incident_severity_id: Number(v) })} disabled={!canChangeStatus || updating}>
                                <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Severidad" /></SelectTrigger>
                                <SelectContent>{(cats.incident_severities || []).map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="md:col-span-3 space-y-2">
                            <Label className="text-[10px] uppercase text-muted-foreground font-bold">Nota</Label>
                            <Textarea
                                placeholder="Nota (opcional)"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                disabled={!canComment || updating}
                                className="min-h-[100px] bg-muted/10"
                            />
                            {canComment && (
                                <Button onClick={() => update({})} disabled={updating} className="min-h-[44px] md:min-h-0">
                                    {updating && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                    Guardar nota / cambio
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}

            {canAssign && (
                <Card ref={assignSectionRef} className="border border-border/50 shadow-sm">
                    <CardHeader className="pb-3">
                        <CardTitle>Responsable</CardTitle>
                        <CardDescription className="text-xs">Tomar, reasignar o liberar la incidencia.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="text-sm">
                            <span className="text-muted-foreground">Actual:</span>{" "}
                            <span className="font-medium">{assignedUser ? assignedUser.name : "Sin asignar"}</span>
                        </div>
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="space-y-2">
                                <Label className="text-[10px] uppercase text-muted-foreground font-bold">Responsable</Label>
                                <Select value={assigneeId} onValueChange={setAssigneeId} disabled={updating}>
                                    <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Seleccionar responsable" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Seleccionar responsable</SelectItem>
                                        {areaUsers.map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button onClick={assignIncident} disabled={updating || assigneeId === "none"} className="self-end min-h-[44px] md:min-h-0">
                                Reasignar
                            </Button>
                            <div className="flex gap-2 self-end">
                                <Button onClick={takeIncident} disabled={updating || Boolean(incident.assigned_user_id)} className="min-h-[44px] md:min-h-0">
                                    Tomar
                                </Button>
                                <Button variant="outline" onClick={unassignIncident} disabled={updating || !incident.assigned_user_id} className="min-h-[44px] md:min-h-0">
                                    Liberar
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Barra de acciones fija en móvil */}
            {(canChangeStatus || canComment || canAssign) && (
                <div
                    className="md:hidden fixed left-0 right-0 z-40 flex items-center justify-center gap-2 border-t border-border/60 bg-background/95 backdrop-blur-sm py-2 px-4"
                    style={{ bottom: "max(4.5rem, calc(env(safe-area-inset-bottom) + 4rem))" }}
                >
                    {(canChangeStatus || canComment) && (
                        <Button variant="outline" size="sm" className="flex-1 min-h-[44px] gap-1.5" onClick={() => scrollToRef(updateSectionRef)}>
                            <MessageSquare className="h-4 w-4" /> Comentar / Estado
                        </Button>
                    )}
                    {canAssign && (
                        <Button variant="outline" size="sm" className="flex-1 min-h-[44px] gap-1.5" onClick={() => scrollToRef(assignSectionRef)}>
                            <UserCog className="h-4 w-4" /> Asignar
                        </Button>
                    )}
                </div>
            )}

            <Card className="border border-border/50 shadow-sm">
                <CardHeader className="pb-3">
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle>Evidencias</CardTitle>
                            <CardDescription className="text-xs">Adjunta archivos de soporte.</CardDescription>
                        </div>
                        <Badge variant="secondary" className="text-[10px]">{attachments.length} adjuntos</Badge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-3 md:grid-cols-[1fr_auto] items-end">
                        <div className="space-y-2">
                            <Label className="text-[10px] uppercase text-muted-foreground font-bold">Archivos</Label>
                            <Input type="file" multiple onChange={(e) => setFiles(Array.from(e.target.files || []))} />
                        </div>
                        <Button onClick={uploadAttachments} disabled={uploading || files.length === 0} className="h-9">
                            {uploading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Paperclip className="mr-2 h-4 w-4" />}
                            Subir
                        </Button>
                    </div>

                    {attachments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Sin adjuntos.</p>
                    ) : (
                        <Table>
                            <TableHeader className="bg-muted/40">
                                <TableRow className="border-b border-border/50 hover:bg-transparent">
                                    <TableHead className="font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Archivo</TableHead>
                                    <TableHead className="font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Tamano</TableHead>
                                    <TableHead className="text-right font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Acciones</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {attachments.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell>
                                            <a
                                                href={`/api/incidents/${incident.id}/attachments/${a.id}/download`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary hover:underline"
                                            >
                                                {a.original_name}
                                            </a>
                                        </TableCell>
                                        <TableCell className="text-xs text-muted-foreground">{Math.round((a.size || 0) / 1024)} KB</TableCell>
                                        <TableCell className="text-right">
                                            <Button variant="ghost" size="icon" onClick={() => removeAttachment(a)}>
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            <Card className="border border-border/50 shadow-sm">
                <CardHeader className="pb-3">
                    <CardTitle>Historial</CardTitle>
                    <CardDescription className="text-xs">Bitacora de cambios y comentarios.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {histories.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Sin movimientos.</p>
                    ) : (
                        <Table>
                            <TableHeader className="bg-muted/40">
                                <TableRow className="border-b border-border/50 hover:bg-transparent">
                                    <TableHead className="font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Fecha</TableHead>
                                    <TableHead className="font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Actor</TableHead>
                                    <TableHead className="font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Cambio</TableHead>
                                    <TableHead className="font-bold text-[11px] uppercase tracking-wider text-muted-foreground">Nota</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {histories.map((h) => (
                                    <TableRow key={h.id}>
                                        <TableCell className="text-xs">{new Date(h.created_at).toLocaleString()}</TableCell>
                                        <TableCell className="text-xs">{h.actor?.name}</TableCell>
                                        <TableCell className="text-xs space-y-1">
                                            {h.from_status?.name && h.to_status?.name && (
                                                <div>Estado: {h.from_status.name} -&gt; {h.to_status.name}</div>
                                            )}
                                            {h.from_assignee?.name || h.to_assignee?.name ? (
                                                <div>Responsable: {h.from_assignee?.name || "-"} -&gt; {h.to_assignee?.name || "-"}</div>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="text-xs text-muted-foreground">{h.note || "-"}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

Detalle.layout = (page) => (
    <AuthenticatedLayout title="Detalle de incidencia">{page}</AuthenticatedLayout>
);
