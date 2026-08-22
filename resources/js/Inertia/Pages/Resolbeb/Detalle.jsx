import { useEffect, useMemo, useState } from "react";
import { usePage, Link as InertiaLink } from "@inertiajs/react";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { cn } from "@/lib/utils";
import { kpiCardSurface, hintWarning, noticeWarningPanel } from "@/lib/badgeStyles";
import { notify } from "@/lib/notify";
import { Skeleton } from "@/components/ui/skeleton";
import { Loader2, MessageSquare, ArrowLeft, ChevronDown, ChevronUp, UserCheck, AlertTriangle, XCircle, BellRing, ArrowUpFromLine, Paperclip, Download, Package, Search, X } from "lucide-react";
import { TicketPriorityBadgeByName, TicketStateBadgeByName } from "@/components/badges/EntityBadges";

const RESOLVE_BASE = "/resolbeb";

/** Par etiqueta/valor legible -- reemplaza el <strong>Label:</strong> value en línea. */
function Field({ label, value, className }) {
    return (
        <div className={cn("space-y-0.5", className)}>
            <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
            <p className="text-sm text-foreground">{value ?? "—"}</p>
        </div>
    );
}

function formatBytes(bytes) {
    if (!bytes && bytes !== 0) return "";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Adjuntos del ticket (ticket.attachments ya viene eager-loaded desde TicketController::show). */
function TicketAttachments({ attachments }) {
    if (!attachments || attachments.length === 0) return null;
    return (
        <div className="border-t pt-3 space-y-2">
            <p className="text-xs font-semibold text-muted-foreground flex items-center gap-1.5">
                <Paperclip className="h-3.5 w-3.5" /> Adjuntos ({attachments.length})
            </p>
            <ul className="flex flex-wrap gap-2">
                {attachments.map((a) => (
                    <li key={a.id}>
                        <a
                            href={a.url}
                            target="_blank"
                            rel="noreferrer"
                            className="flex items-center gap-2 rounded-md border border-border/60 bg-muted/20 px-2.5 py-1.5 text-xs hover:bg-muted/40 transition-colors max-w-[220px]"
                            title={a.original_name}
                        >
                            <Download className="h-3.5 w-3.5 flex-shrink-0 text-muted-foreground" />
                            <span className="truncate">{a.original_name}</span>
                            {typeof a.size === "number" && (
                                <span className="text-muted-foreground flex-shrink-0">{formatBytes(a.size)}</span>
                            )}
                        </a>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Pasos de la timeline: Creado -> Asignado -> En Proceso -> Resuelto. */
const TIMELINE_STEPS = [
    { key: "creado", label: "Creado" },
    { key: "asignado", label: "Asignado" },
    { key: "en_proceso", label: "En proceso" },
    { key: "resuelto", label: "Resuelto" },
];

function normalizeStateKey(name) {
    if (!name) return "";
    return String(name).toLowerCase().replace(/\s+/g, "_").replace(/ó/g, "o");
}

/** Vista solo lectura / rastreador para el solicitante. */
function RequesterView({
    ticket,
    id,
    attendedBy,
    descExpanded,
    setDescExpanded,
    commentEntries,
    note,
    setNote,
    update,
    canComment,
    canAlert,
    alertMessage,
    setAlertMessage,
    sendAlert,
    canCancel,
    cancelTicket,
    sendingAlert,
    cancelling,
    timelineStepsWithStatus,
    isRequester,
    assignedUser,
    updating,
    isCancelledState,
}) {
    const descLong = (ticket.description || "").length > 280;
    return (
        <>
            {isRequester && attendedBy.length > 0 && (
                <Card className="border-primary/20 bg-primary/5">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm flex items-center gap-2">
                            <UserCheck className="h-4 w-4" /> Quién ha atendido este ticket
                        </CardTitle>
                        <CardDescription className="text-xs">Personas que han intervenido en la atención de tu solicitud.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="flex flex-wrap gap-2">
                            {attendedBy.map((p) => (
                                <li key={p.id}>
                                    <Badge variant="secondary" className="font-normal">{p.name}</Badge>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    <Card className={ticket.is_overdue ? "border-l-4 border-l-destructive" : ""}>
                        <CardHeader className="pb-2">
                            <div className="flex flex-wrap items-center gap-2 mb-2">
                                <TicketStateBadgeByName name={ticket.state?.name} />
                                <TicketPriorityBadgeByName name={ticket.priority?.name} />
                                <Badge variant="outline" className="text-xs">{ticket.area_current?.name ?? "—"}</Badge>
                                {ticket.is_overdue && <Badge variant="destructive">SLA vencido</Badge>}
                            </div>
                            <CardTitle className="text-lg md:text-xl">Ticket #{ticket.id} — {ticket.subject}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-muted-foreground whitespace-pre-wrap text-sm">
                                {descLong && !descExpanded ? (
                                    <>
                                        {(ticket.description || "").slice(0, 280)}…
                                        <button type="button" onClick={() => setDescExpanded(true)} className="ml-2 text-primary hover:underline inline-flex items-center gap-0.5 text-xs font-medium">Ver más <ChevronDown className="h-3 w-3" /></button>
                                    </>
                                ) : descLong && descExpanded ? (
                                    <>
                                        {ticket.description}
                                        <button type="button" onClick={() => setDescExpanded(false)} className="ml-2 text-primary hover:underline inline-flex items-center gap-0.5 text-xs font-medium">Ver menos <ChevronUp className="h-3 w-3" /></button>
                                    </>
                                ) : (
                                    ticket.description || "—"
                                )}
                            </p>
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-3 border-t pt-3">
                                <Field label="Área origen" value={ticket.area_origin?.name} />
                                <Field label="Tipo" value={ticket.ticket_type?.name} />
                                <Field label="Solicitante" value={ticket.requester?.name} />
                                <Field label="Responsable" value={assignedUser ? assignedUser.name : "Sin asignar"} />
                                <Field label="Fecha límite" value={ticket.due_at ? new Date(ticket.due_at).toLocaleString() : "—"} />
                                {ticket.sla_status_text && (
                                    <Field
                                        label="SLA"
                                        value={
                                            <span className={ticket.is_overdue ? "text-destructive font-medium" : ""}>
                                                {ticket.sla_status_text}
                                            </span>
                                        }
                                    />
                                )}
                            </div>
                            <TicketAttachments attachments={ticket.attachments} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base flex items-center gap-2">
                                <MessageSquare className="h-4 w-4" /> Historial y comentarios
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {commentEntries.length ? (
                                <ul className="space-y-3">
                                    {commentEntries.map((h) => {
                                        const isUser = Number(h.actor_id) === Number(ticket.requester_id);
                                        return (
                                            <li key={h.id} className={isUser ? "flex justify-end" : "flex justify-start"}>
                                                <div className={cn(
                                                    "max-w-[85%] rounded-2xl px-4 py-2.5 text-sm",
                                                    isUser ? "bg-primary text-primary-foreground rounded-br-md" : "bg-muted rounded-bl-md"
                                                )}>
                                                    <div className="text-xs opacity-90 mb-0.5">{h.actor?.name} · {new Date(h.created_at).toLocaleString()}</div>
                                                    <div className="whitespace-pre-wrap">{h.note || "—"}</div>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : (
                                <p className="text-sm text-muted-foreground">Sin comentarios aún.</p>
                            )}
                            {canComment && (
                                <div className="border-t pt-4 space-y-2">
                                    <Textarea placeholder="Escribe un comentario..." value={note} onChange={(e) => setNote(e.target.value)} disabled={updating} className="min-h-[80px] resize-y" />
                                    <Button onClick={() => update({})} disabled={updating || !note.trim()}>{updating ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null} Enviar comentario</Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
                <div className="lg:col-span-1 space-y-6">
                    {canAlert && (
                        <Card>
                            <CardContent className="pt-6 space-y-3">
                                <Textarea placeholder="Mensaje opcional (ej: Llevo días sin respuesta...)" value={alertMessage} onChange={(e) => setAlertMessage(e.target.value)} disabled={sendingAlert} className="min-h-[72px] resize-y text-sm" maxLength={1000} />
                                <Button variant="destructive" className="w-full" onClick={sendAlert} disabled={sendingAlert}>
                                    {sendingAlert ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : <BellRing className="h-4 w-4 mr-2" />}
                                    Enviar Alerta a Soporte
                                </Button>
                                {canCancel && (
                                    <Button variant="outline" size="sm" className="w-full mt-2 text-destructive hover:text-destructive" onClick={cancelTicket} disabled={cancelling}>
                                        {cancelling ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : <XCircle className="h-4 w-4 mr-2" />}
                                        Cancelar ticket
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-base">Progreso</CardTitle></CardHeader>
                        <CardContent>
                            {isCancelledState ? (
                                <div className="flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2.5">
                                    <XCircle className="h-4 w-4 text-destructive flex-shrink-0" />
                                    <p className="text-sm font-medium text-destructive">
                                        {ticket.state?.name ?? "Cancelado"} — no continúa el flujo normal.
                                    </p>
                                </div>
                            ) : (
                                <div className="relative">
                                    <div className="absolute left-2 top-2 bottom-2 w-0.5 border-l-2 border-border" aria-hidden="true" />
                                    <ul className="space-y-0">
                                        {timelineStepsWithStatus.map((step) => (
                                            <li key={step.key} className="relative flex gap-3 pb-6 last:pb-0">
                                                <div className={cn("relative z-10 h-4 w-4 rounded-full border-2 flex-shrink-0 mt-0.5", step.completed ? "bg-primary border-primary" : "bg-background border-muted-foreground/30")} />
                                                <div className="flex-1 min-w-0 pt-0">
                                                    <p className={cn("text-sm font-medium", step.completed ? "text-foreground" : "text-muted-foreground")}>{step.label}</p>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

/** Panel de control de TI: edición de estado/prioridad/área, tomar, escalar, plantillas. */
function ManagerView({
    ticket,
    id,
    catalogs,
    note,
    setNote,
    isInternalNote,
    setIsInternalNote,
    update,
    takeTicket,
    assignTicket,
    unassignTicket,
    assigneeId,
    setAssigneeId,
    dueAtLocal,
    setDueAtLocal,
    macros,
    macrosLoading,
    selectedMacroId,
    setSelectedMacroId,
    insertMacro,
    escalateTicket,
    canChangeArea,
    canChangeStatus,
    canComment,
    canAssign,
    canRelease,
    canEdit,
    hasAssignee,
    assignedUser,
    areaUsers,
    updating,
    commentEntries,
    internalNoteEntries,
    assignmentEvents,
    stateChangeWithDiff,
    alertEntries,
    canEscalate,
    assetQuery,
    setAssetQuery,
    assetResults,
    searchingAssets,
    linkingAssetId,
    linkAsset,
    unlinkAsset,
}) {
    const [escalateAreaId, setEscalateAreaId] = useState("");
    const [escalateNote, setEscalateNote] = useState("");
    const [showEscalateForm, setShowEscalateForm] = useState(false);
    const descLong = (ticket.description || "").length > 280;
    const [descExpanded, setDescExpanded] = useState(false);
    const areas = catalogs.areas || [];

    const handleEscalate = () => {
        if (!escalateAreaId) return;
        escalateTicket(escalateAreaId, escalateNote);
        setEscalateAreaId("");
        setEscalateNote("");
        setShowEscalateForm(false);
    };

    return (
        <>
            <div className="flex flex-wrap items-center gap-2 mb-4">
                <Button size="sm" onClick={takeTicket} disabled={updating || hasAssignee}><Loader2 className={updating ? "h-4 w-4 animate-spin mr-2" : ""} /> Tomar ticket</Button>
                {canEscalate && (
                    <>
                        {!showEscalateForm ? (
                            <Button size="sm" variant="outline" onClick={() => setShowEscalateForm(true)}><ArrowUpFromLine className="h-4 w-4 mr-2" /> Escalar ticket</Button>
                        ) : (
                            <Card className={cn("flex-1 min-w-[280px] p-3 border", kpiCardSurface.warning.card)}>
                                <div className="flex flex-wrap items-end gap-2">
                                    <div className="flex-1 min-w-[160px] space-y-1">
                                        <Label className="text-xs">Área destino</Label>
                                        <Select value={escalateAreaId} onValueChange={setEscalateAreaId}>
                                            <SelectTrigger className="h-9"><SelectValue placeholder="Seleccionar área" /></SelectTrigger>
                                            <SelectContent>{areas.filter((a) => Number(a.id) !== Number(ticket.area_current_id)).map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </div>
                                    <div className="flex-1 min-w-[120px] space-y-1">
                                        <Label className="text-xs">Nota (opcional)</Label>
                                        <Input className="h-9" placeholder="Motivo..." value={escalateNote} onChange={(e) => setEscalateNote(e.target.value)} />
                                    </div>
                                    <Button size="sm" onClick={handleEscalate} disabled={updating || !escalateAreaId}>Escalar</Button>
                                    <Button size="sm" variant="ghost" onClick={() => { setShowEscalateForm(false); setEscalateAreaId(""); setEscalateNote(""); }}>Cancelar</Button>
                                </div>
                            </Card>
                        )}
                    </>
                )}
            </div>
            <Card className={ticket.is_overdue ? "border-l-4 border-l-destructive" : ""}>
                <CardHeader className="pb-2">
                    <CardTitle className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-lg md:text-xl">Ticket #{ticket.id} — {ticket.subject}</span>
                        <Badge variant={ticket.is_overdue ? "destructive" : "secondary"}>{ticket.state?.name}</Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-6 gap-y-3">
                        <Field label="Área actual" value={ticket.area_current?.name} />
                        <Field label="Área origen" value={ticket.area_origin?.name} />
                        <Field label="Tipo" value={ticket.ticket_type?.name} />
                        <Field label="Prioridad" value={ticket.priority?.name} />
                        <Field label="Solicitante" value={ticket.requester?.name} />
                        <Field
                            label="Responsable"
                            value={assignedUser ? `${assignedUser.name}${assignedUser.position?.name ? " — " + assignedUser.position.name : ""}` : "Sin asignar"}
                        />
                        <Field label="Fecha límite" value={ticket.due_at ? new Date(ticket.due_at).toLocaleString() : "—"} />
                        {ticket.sla_status_text && (
                            <Field
                                label="SLA"
                                value={
                                    <span className={ticket.is_overdue ? "text-destructive font-medium" : ""}>
                                        {ticket.sla_status_text}
                                    </span>
                                }
                            />
                        )}
                    </div>
                    <div>
                        <strong className="text-muted-foreground text-sm block mb-1">Descripción</strong>
                        <div className="text-muted-foreground whitespace-pre-wrap rounded-md bg-muted/30 p-3 text-sm">
                            {descLong && !descExpanded ? <>{(ticket.description || "").slice(0, 280)}… <button type="button" onClick={() => setDescExpanded(true)} className="ml-2 text-primary hover:underline text-xs font-medium">Ver más <ChevronDown className="h-3 w-3" /></button></> : descLong && descExpanded ? <>{ticket.description} <button type="button" onClick={() => setDescExpanded(false)} className="ml-2 text-primary hover:underline text-xs font-medium">Ver menos <ChevronUp className="h-3 w-3" /></button></> : (ticket.description || "—")}
                        </div>
                    </div>
                    <TicketAttachments attachments={ticket.attachments} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Package className="h-4 w-4" />
                        Activos relacionados
                    </CardTitle>
                    <CardDescription>Equipo de Inventario del que trata este ticket.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {(ticket.asset_links ?? []).length === 0 ? (
                        <p className="text-sm text-muted-foreground">Sin activos vinculados todavía.</p>
                    ) : (
                        <ul className="flex flex-wrap gap-2">
                            {ticket.asset_links.map((link) => (
                                <li
                                    key={link.id}
                                    className="flex items-center gap-2 rounded-md border border-border/60 bg-muted/20 px-2.5 py-1.5 text-xs"
                                >
                                    <span>
                                        {link.asset?.name} <span className="text-muted-foreground font-mono">({link.asset?.internal_tag})</span>
                                    </span>
                                    {canEdit && (
                                        <button
                                            type="button"
                                            onClick={() => unlinkAsset(link.id)}
                                            className="text-muted-foreground hover:text-destructive"
                                            title="Quitar"
                                        >
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {canEdit && (
                        <div className="relative max-w-sm">
                            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                            <Input
                                className="h-9 pl-8 text-sm"
                                placeholder="Buscar por nombre, número de inventario o serie…"
                                value={assetQuery}
                                onChange={(e) => setAssetQuery(e.target.value)}
                            />
                            {(searchingAssets || assetResults.length > 0) && assetQuery.trim() && (
                                <div className="absolute z-10 mt-1 w-full rounded-md border bg-popover shadow-md max-h-56 overflow-y-auto">
                                    {searchingAssets ? (
                                        <p className="px-3 py-2 text-xs text-muted-foreground">Buscando…</p>
                                    ) : assetResults.length === 0 ? (
                                        <p className="px-3 py-2 text-xs text-muted-foreground">Sin resultados.</p>
                                    ) : (
                                        assetResults.map((asset) => (
                                            <button
                                                key={asset.id}
                                                type="button"
                                                disabled={linkingAssetId === asset.id}
                                                onClick={() => linkAsset(asset.id)}
                                                className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-xs hover:bg-muted/60 disabled:opacity-50"
                                            >
                                                <span>{asset.name} <span className="text-muted-foreground font-mono">({asset.internal_tag})</span></span>
                                                {linkingAssetId === asset.id && <Loader2 className="h-3 w-3 animate-spin" />}
                                            </button>
                                        ))
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            {canEdit && (
                <Card>
                    <CardHeader><CardTitle>Actualizar</CardTitle><p className="text-sm text-muted-foreground">Cambios de estado, prioridad o área quedan registrados en el historial.</p></CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-3">
                        <Select value={String(ticket.area_current_id)} onValueChange={(v) => update({ area_current_id: Number(v) })} disabled={!canChangeArea || updating}>
                            <SelectTrigger><SelectValue placeholder="Área actual" /></SelectTrigger>
                            <SelectContent>{(catalogs.areas || []).map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>)}</SelectContent>
                        </Select>
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">Estado</Label>
                            <Select value={String(ticket.ticket_state_id)} onValueChange={(v) => update({ ticket_state_id: Number(v) })} disabled={!canChangeStatus || updating}>
                                <SelectTrigger><SelectValue placeholder="Estado" /></SelectTrigger>
                                <SelectContent>{(catalogs.ticket_states || []).map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">Prioridad</Label>
                            <Select value={ticket.priority_id ? String(ticket.priority_id) : ""} onValueChange={(v) => update({ priority_id: v ? Number(v) : null })} disabled={!canChangeStatus || updating}>
                                <SelectTrigger><SelectValue placeholder="Prioridad" /></SelectTrigger>
                                <SelectContent>{(catalogs.priorities || []).map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="md:col-span-3 flex flex-wrap items-end gap-2">
                            <div className="flex-1 min-w-[200px] space-y-1">
                                <Label className="text-xs">Fecha límite (opcional)</Label>
                                <Input type="datetime-local" value={dueAtLocal} onChange={(e) => setDueAtLocal(e.target.value)} disabled={!canChangeStatus || updating} className="h-9" />
                            </div>
                            <Button type="button" variant="outline" size="sm" disabled={!canChangeStatus || updating} onClick={() => update({ due_at: dueAtLocal ? new Date(dueAtLocal).toISOString() : null })}>Actualizar fecha</Button>
                        </div>
                        <div className="md:col-span-3 space-y-2">
                            {canComment && macros.length > 0 && (
                                <div className="flex items-center gap-2">
                                    <Label className="text-xs text-muted-foreground">Plantilla:</Label>
                                    <Select value={selectedMacroId || "none"} onValueChange={(v) => { if (v && v !== "none") insertMacro(v); }} disabled={macrosLoading}>
                                        <SelectTrigger className="w-[220px] h-8 text-xs"><SelectValue placeholder="Insertar plantilla" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">Insertar plantilla…</SelectItem>
                                            {macros.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.name}{m.category ? ` (${m.category})` : ""}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <Textarea placeholder="Nota o comentario (opcional)" value={note} onChange={(e) => setNote(e.target.value)} disabled={!canComment || updating} />
                            {canComment && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <label className="flex items-center gap-2 text-sm cursor-pointer">
                                        <Checkbox checked={isInternalNote} onCheckedChange={(v) => setIsInternalNote(!!v)} disabled={updating} />
                                        <span className="text-muted-foreground">Nota interna (no visible para el solicitante)</span>
                                    </label>
                                    <Button onClick={() => update({})} disabled={updating}>{updating && <Loader2 className="mr-2 h-4 w-4 animate-spin" />} Guardar nota / cambio</Button>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}
            {canAssign && (
                <Card>
                    <CardHeader><CardTitle>Responsable</CardTitle><p className="text-sm text-muted-foreground">Tomar, reasignar o liberar el ticket.</p></CardHeader>
                    <CardContent className="space-y-3">
                        <div className="text-sm"><strong>Actual:</strong> {assignedUser ? `${assignedUser.name}${assignedUser.position?.name ? " — " + assignedUser.position.name : ""}` : "Sin asignar"}</div>
                        <div className="grid gap-2 md:grid-cols-3">
                            <Select value={assigneeId} onValueChange={setAssigneeId} disabled={updating}>
                                <SelectTrigger><SelectValue placeholder="Seleccionar responsable" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">Seleccionar responsable</SelectItem>{areaUsers.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                            </Select>
                            <Button onClick={assignTicket} disabled={updating || assigneeId === "none" || (hasAssignee && !canAssign)}>Reasignar</Button>
                            <div className="flex gap-2">
                                <Button onClick={takeTicket} disabled={updating || hasAssignee || !canAssign}>Tomar</Button>
                                <Button variant="outline" onClick={unassignTicket} disabled={updating || !hasAssignee || !canRelease} title={hasAssignee && !canRelease ? "Solo el responsable actual puede liberar" : ""}>Liberar</Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            )}
            <Card>
                <CardHeader><CardTitle>Asignaciones</CardTitle></CardHeader>
                <CardContent>
                    {assignmentEvents.length ? <div className="space-y-2">{assignmentEvents.map((h) => {
                        const fromName = h.from_assignee?.name || "-"; const toName = h.to_assignee?.name || "-";
                        let text = "Movimiento"; if (h.action === "assigned") text = `Asignado a ${toName}`; if (h.action === "reassigned") text = `Reasignado de ${fromName} a ${toName}`; if (h.action === "unassigned") text = h.note ? `${h.note} (antes ${fromName})` : `Liberado (antes ${fromName})`;
                        return <div key={h.id} className="text-sm"><div className="font-medium">{text}</div><div className="text-xs text-muted-foreground">{new Date(h.created_at).toLocaleString()} • {h.actor?.name || "—"}</div></div>;
                    })}</div> : <div className="text-sm text-muted-foreground">Sin asignaciones</div>}
                </CardContent>
            </Card>
            <Card>
                <CardHeader><CardTitle>Historial y comentarios</CardTitle></CardHeader>
                <CardContent className="space-y-6">
                    <div>
                        <h4 className="text-sm font-semibold flex items-center gap-2 mb-2"><MessageSquare className="h-4 w-4" /> Comentarios (visibles para el solicitante)</h4>
                        {commentEntries.length ? <div className="space-y-3">{commentEntries.map((h) => <div key={h.id} className="rounded-lg border bg-muted/30 p-3 text-sm"><div className="text-muted-foreground text-xs mb-1">{h.actor?.name} · {new Date(h.created_at).toLocaleString()}</div><div className="whitespace-pre-wrap">{h.note || "—"}</div></div>)}</div> : <p className="text-sm text-muted-foreground">Sin comentarios visibles.</p>}
                    </div>
                    <div>
                        <h4 className="text-sm font-semibold flex items-center gap-2 mb-2">Notas internas</h4>
                        {internalNoteEntries.length ? <div className="space-y-3">{internalNoteEntries.map((h) => <div key={h.id} className={noticeWarningPanel}><div className="text-muted-foreground text-xs mb-1">{h.actor?.name} · {new Date(h.created_at).toLocaleString()}</div><div className="whitespace-pre-wrap">{h.note || "—"}</div></div>)}</div> : <p className="text-sm text-muted-foreground">Sin notas internas.</p>}
                    </div>
                    {alertEntries.length > 0 && <div><h4 className="text-sm font-semibold flex items-center gap-2 mb-2"><AlertTriangle className={cn("h-4 w-4", hintWarning)} /> Alertas del solicitante</h4><div className="space-y-3">{alertEntries.map((h) => <div key={h.id} className={noticeWarningPanel}><div className="text-muted-foreground text-xs mb-1">{h.actor?.name} · {new Date(h.created_at).toLocaleString()}</div><div className="whitespace-pre-wrap">{h.note || "—"}</div></div>)}</div></div>}
                    <div>
                        <h4 className="text-sm font-semibold mb-2">Cambios de estado</h4>
                        {stateChangeWithDiff.length ? <div className="space-y-2">{stateChangeWithDiff.map((h) => <div key={h.id} className="text-sm flex flex-wrap gap-x-4 gap-y-1"><span>{new Date(h.created_at).toLocaleString()}</span><span>{h.actor?.name}</span><span>{h.from_area?.name || "—"} → {h.to_area?.name || "—"}</span><span>{h.state?.name || "—"}</span><span className="text-muted-foreground">{h.note || "—"}</span></div>)}</div> : <p className="text-sm text-muted-foreground">Sin cambios de estado</p>}
                    </div>
                </CardContent>
            </Card>
        </>
    );
}

export default function Resolvev1Detalle() {
    const { auth, catalogs: propCatalogs, ticketId } = usePage().props;
    const user = auth?.user;
    const can = (perm) => auth?.user?.permissions?.includes(perm) ?? false;
    const backToListLink = (can("tickets.manage_all") || can("tickets.view_area")) ? `${RESOLVE_BASE}/tickets` : RESOLVE_BASE;
    const [ticket, setTicket] = useState(null);
    const [catalogs, setCatalogs] = useState(propCatalogs ?? {});
    const [note, setNote] = useState("");
    const [updating, setUpdating] = useState(false);
    const [assigneeId, setAssigneeId] = useState("none");
    const [dueAtLocal, setDueAtLocal] = useState("");
    const [isInternalNote, setIsInternalNote] = useState(true);
    const [descExpanded, setDescExpanded] = useState(false);
    const [alertMessage, setAlertMessage] = useState("");
    const [sendingAlert, setSendingAlert] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [macros, setMacros] = useState([]);
    const [macrosLoading, setMacrosLoading] = useState(false);
    const [selectedMacroId, setSelectedMacroId] = useState("");
    // Activos relacionados (auditoría de Inventario, fase 3.1).
    const [assetQuery, setAssetQuery] = useState("");
    const [assetResults, setAssetResults] = useState([]);
    const [searchingAssets, setSearchingAssets] = useState(false);
    const [linkingAssetId, setLinkingAssetId] = useState(null);

    const load = async () => {
        try {
            const ticketRes = await axios.get(`/api/tickets/${ticketId}`);
            setTicket(ticketRes.data);
            const t = ticketRes.data;
            setDueAtLocal(t?.due_at ? new Date(t.due_at).toISOString().slice(0, 16) : "");
        } catch (err) {
            notify.error("No se pudo cargar el ticket");
        }
    };

    useEffect(() => { load(); }, [ticketId]);

    useEffect(() => {
        let mounted = true;
        setMacrosLoading(true);
        axios.get("/api/ticket-macros", { params: { active_only: 1 } })
            .then((res) => { if (mounted) setMacros(Array.isArray(res.data) ? res.data : []); })
            .catch(() => { if (mounted) notify.error("No se pudieron cargar las plantillas"); })
            .finally(() => { if (mounted) setMacrosLoading(false); });
        return () => { mounted = false; };
    }, []);

    const insertMacro = (macroId) => {
        const macro = macros.find((m) => String(m.id) === String(macroId));
        if (!macro) return;
        setNote((prev) => (prev?.trim() ? prev + "\n\n" + macro.content : macro.content));
        setSelectedMacroId("");
    };

    // Activos relacionados (auditoría de Inventario, fase 3.1) -- búsqueda
    // acotada al tenant del ticket (TicketAssetController::search), no al
    // scope de inventario del usuario.
    useEffect(() => {
        if (!assetQuery.trim()) { setAssetResults([]); return; }
        let active = true;
        setSearchingAssets(true);
        const timeout = setTimeout(() => {
            axios.get(`/api/tickets/${ticketId}/assets/search`, { params: { q: assetQuery } })
                .then((res) => { if (active) setAssetResults(res.data ?? []); })
                .catch(() => { if (active) setAssetResults([]); })
                .finally(() => { if (active) setSearchingAssets(false); });
        }, 350);
        return () => { active = false; clearTimeout(timeout); };
    }, [assetQuery, ticketId]);

    const linkAsset = async (assetId) => {
        setLinkingAssetId(assetId);
        try {
            await axios.post(`/api/tickets/${ticketId}/assets`, { asset_id: assetId });
            notify.success("Activo vinculado");
            setAssetQuery("");
            setAssetResults([]);
            load();
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo vincular el activo");
        } finally {
            setLinkingAssetId(null);
        }
    };

    const unlinkAsset = async (linkId) => {
        try {
            await axios.delete(`/api/tickets/${ticketId}/assets/${linkId}`);
            load();
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo quitar el activo");
        }
    };

    const escalateTicket = async (areaDestinoId, escalateNote) => {
        setUpdating(true);
        try {
            const { data } = await axios.post(`/api/tickets/${ticketId}/escalate`, {
                area_destino_id: Number(areaDestinoId),
                note: escalateNote?.trim() || null,
            });
            setTicket(data);
            notify.success("Ticket escalado correctamente");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo escalar el ticket");
        } finally { setUpdating(false); }
    };

    const update = async (payload) => {
        setUpdating(true);
        try {
            const { data } = await axios.put(`/api/tickets/${ticketId}`, { ...payload, note, is_internal: isInternalNote });
            setTicket(data);
            setNote("");
            setAssigneeId("none");
            setDueAtLocal(data?.due_at ? new Date(data.due_at).toISOString().slice(0, 16) : "");
            notify.success("Ticket actualizado");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo actualizar");
        } finally { setUpdating(false); }
    };

    const takeTicket = async () => {
        setUpdating(true);
        try {
            const { data } = await axios.post(`/api/tickets/${ticketId}/take`);
            setTicket(data);
            notify.success("Ticket tomado");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo tomar");
        } finally { setUpdating(false); }
    };

    const assignTicket = async () => {
        if (assigneeId === "none") return;
        setUpdating(true);
        try {
            const { data } = await axios.post(`/api/tickets/${ticketId}/assign`, { assigned_user_id: Number(assigneeId) });
            setTicket(data);
            setAssigneeId("none");
            notify.success("Responsable asignado");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo reasignar");
        } finally { setUpdating(false); }
    };

    const unassignTicket = async () => {
        setUpdating(true);
        try {
            const { data } = await axios.post(`/api/tickets/${ticketId}/unassign`);
            setTicket(data);
            notify.success("Ticket liberado");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo liberar");
        } finally { setUpdating(false); }
    };

    const sendAlert = async () => {
        setSendingAlert(true);
        try {
            const { data } = await axios.post(`/api/tickets/${ticketId}/alert`, { message: alertMessage.trim() || undefined });
            if (data.ticket) setTicket(data.ticket);
            setAlertMessage("");
            notify.success("Alerta enviada. Se ha notificado al responsable y a supervisores.");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo enviar la alerta");
        } finally { setSendingAlert(false); }
    };

    const cancelTicket = async () => {
        if (!window.confirm("¿Estás seguro de que deseas cancelar este ticket? Esta acción no se puede deshacer.")) return;
        setCancelling(true);
        try {
            const { data } = await axios.post(`/api/tickets/${ticketId}/cancel`);
            setTicket(data);
            notify.success("Ticket cancelado");
        } catch (err) {
            notify.error(err?.response?.data?.message || "No se pudo cancelar el ticket");
        } finally { setCancelling(false); }
    };

    const attendedBy = useMemo(() => {
        if (!ticket) return [];
        const assigned = ticket.assigned_user || ticket.assignedUser;
        const raw = ticket.histories ?? ticket.histories_list ?? [];
        const hist = Array.isArray(raw) ? raw : (Array.isArray(raw?.data) ? raw.data : (raw && typeof raw === 'object' && !Array.isArray(raw) ? Object.values(raw) : []));
        const ids = new Set();
        const names = [];
        if (assigned?.id) {
            ids.add(assigned.id);
            names.push({ id: assigned.id, name: assigned.name });
        }
        hist.forEach((h) => {
            if (h.actor_id && h.actor_id !== ticket.requester_id && !ids.has(h.actor_id)) {
                ids.add(h.actor_id);
                names.push({ id: h.actor_id, name: h.actor?.name || "—" });
            }
        });
        return names;
    }, [ticket]);

    // Basado en state.code (abierto/en_progreso/en_espera/resuelto/cerrado/cancelado/rechazado),
    // no en substrings del nombre -- "Abierto" ya no encendía por error el paso
    // "En proceso" solo por contener la palabra "abierto".
    const completedStepKeys = useMemo(() => {
        if (!ticket) return new Set(["creado"]);
        const hasAssignee = Boolean(ticket.assigned_user_id);
        const code = (ticket.state?.code || "").toLowerCase();
        const keys = new Set(["creado"]);
        if (hasAssignee) keys.add("asignado");
        if (["en_progreso", "en_espera", "resuelto", "cerrado"].includes(code)) keys.add("en_proceso");
        if (["resuelto", "cerrado"].includes(code)) keys.add("resuelto");
        return keys;
    }, [ticket]);

    const isCancelledState = useMemo(
        () => ["cancelado", "rechazado"].includes((ticket?.state?.code || "").toLowerCase()),
        [ticket]
    );

    const timelineStepsWithStatus = useMemo(() => TIMELINE_STEPS.map((step) => ({
        ...step,
        completed: completedStepKeys.has(step.key),
    })), [completedStepKeys]);

    if (!ticket) {
        return (
            <div className="space-y-6 w-full">
                <div className="flex items-center gap-3">
                    <Skeleton className="h-9 w-9 rounded-md" />
                    <div className="space-y-2">
                        <Skeleton className="h-6 w-48" />
                        <Skeleton className="h-4 w-32" />
                    </div>
                </div>
                <Card>
                    <CardHeader><Skeleton className="h-5 w-3/4" /></CardHeader>
                    <CardContent className="space-y-3">
                        {[1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="h-4 w-full" />)}
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-6"><Skeleton className="h-24 w-full" /></CardContent>
                </Card>
            </div>
        );
    }

    const abilities = ticket.abilities || {};
    const canChangeArea = Boolean(abilities.change_area);
    const canChangeStatus = Boolean(abilities.change_status);
    const canComment = Boolean(abilities.comment);
    const canAssign = Boolean(abilities.assign);
    const canRelease = Boolean(abilities.release);
    const canAlert = Boolean(abilities.alert);
    const canCancel = Boolean(abilities.cancel);

    const canEdit = canChangeArea || canChangeStatus || canComment;
    const hasAssignee = Boolean(ticket.assigned_user_id);
    const assignedUser = ticket.assigned_user || ticket.assignedUser;

    const rawHist = ticket.histories ?? [];
    const histories = Array.isArray(rawHist) ? rawHist : (Array.isArray(rawHist?.data) ? rawHist.data : (rawHist && typeof rawHist === 'object' && !Array.isArray(rawHist) ? Object.values(rawHist) : []));
    const assignmentEvents = histories.filter(h => ["assigned", "reassigned", "unassigned"].includes(h.action));
    const commentEntries = histories.filter(h => h.action === "comment" && !h.is_internal);
    const internalNoteEntries = histories.filter(h => h.action === "comment" && h.is_internal);
    const stateChangeEntries = histories.filter(h => h.action && h.action !== "comment" && ["escalated", "state_change", "assigned", "reassigned", "unassigned"].includes(h.action));
    const alertEntries = histories.filter(h => h.action === "requester_alert");
    const stateChangeWithDiff = stateChangeEntries.map((h, idx) => {
        const next = stateChangeEntries[idx + 1];
        if (!next) return { ...h, diff: null };
        const current = new Date(h.created_at);
        const nextDate = new Date(next.created_at);
        const diffHours = Math.round((current - nextDate) / 3600000 * 10) / 10;
        return { ...h, diff: diffHours };
    });
    const areaUsers = catalogs.area_users || [];

    const isRequester = user && Number(user.id) === Number(ticket.requester_id);
    const isManager = Boolean(
        ticket.abilities && (ticket.abilities.change_area || ticket.abilities.change_status || ticket.abilities.assign || ticket.abilities.comment)
    );
    const canEscalate = Boolean(ticket.abilities?.escalate);

    return (
        <div className="space-y-6 w-full pb-8">
            <div className="flex items-center gap-3">
                <Button variant="ghost" size="sm" asChild className="text-muted-foreground hover:text-foreground -ml-2">
                    <InertiaLink href={backToListLink}><ArrowLeft className="h-4 w-4 mr-1" /> Volver al listado</InertiaLink>
                </Button>
            </div>
            {isManager ? (
                <ManagerView
                    ticket={ticket}
                    id={ticketId}
                    catalogs={catalogs}
                    note={note}
                    setNote={setNote}
                    isInternalNote={isInternalNote}
                    setIsInternalNote={setIsInternalNote}
                    update={update}
                    takeTicket={takeTicket}
                    assignTicket={assignTicket}
                    unassignTicket={unassignTicket}
                    assigneeId={assigneeId}
                    setAssigneeId={setAssigneeId}
                    dueAtLocal={dueAtLocal}
                    setDueAtLocal={setDueAtLocal}
                    macros={macros}
                    macrosLoading={macrosLoading}
                    selectedMacroId={selectedMacroId}
                    setSelectedMacroId={setSelectedMacroId}
                    insertMacro={insertMacro}
                    escalateTicket={escalateTicket}
                    canChangeArea={canChangeArea}
                    canChangeStatus={canChangeStatus}
                    canComment={canComment}
                    canAssign={canAssign}
                    canRelease={canRelease}
                    canEdit={canEdit}
                    hasAssignee={hasAssignee}
                    assignedUser={assignedUser}
                    areaUsers={areaUsers}
                    updating={updating}
                    commentEntries={commentEntries}
                    internalNoteEntries={internalNoteEntries}
                    assignmentEvents={assignmentEvents}
                    stateChangeWithDiff={stateChangeWithDiff}
                    alertEntries={alertEntries}
                    canEscalate={canEscalate}
                    assetQuery={assetQuery}
                    setAssetQuery={setAssetQuery}
                    assetResults={assetResults}
                    searchingAssets={searchingAssets}
                    linkingAssetId={linkingAssetId}
                    linkAsset={linkAsset}
                    unlinkAsset={unlinkAsset}
                />
            ) : (
                <RequesterView
                    ticket={ticket}
                    attendedBy={attendedBy}
                    descExpanded={descExpanded}
                    setDescExpanded={setDescExpanded}
                    commentEntries={commentEntries}
                    note={note}
                    setNote={setNote}
                    update={update}
                    canComment={canComment}
                    canAlert={canAlert}
                    alertMessage={alertMessage}
                    setAlertMessage={setAlertMessage}
                    sendAlert={sendAlert}
                    canCancel={canCancel}
                    cancelTicket={cancelTicket}
                    sendingAlert={sendingAlert}
                    cancelling={cancelling}
                    timelineStepsWithStatus={timelineStepsWithStatus}
                    isRequester={isRequester}
                    assignedUser={assignedUser}
                    updating={updating}
                    isCancelledState={isCancelledState}
                />
            )}
        </div>
    );
}

Resolvev1Detalle.layout = (page) => (
    <AuthenticatedLayout title="Detalle de ticket">
        {page}
    </AuthenticatedLayout>
);
