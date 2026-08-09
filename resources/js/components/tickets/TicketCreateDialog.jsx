import { useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Building2, CheckCircle2, Loader2, MapPin, Paperclip, Ticket, User, X } from "lucide-react";
import { Field, SectionHeading } from "@/components/tickets/TicketFormFields";
import { MarkdownToolbar } from "@/components/tickets/MarkdownToolbar";
import { notify } from "@/lib/notify";

const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

/** Fade + escala suave, sin el slide-desde-esquina del Dialog base -- se siente
 *  como que el modal "se asienta" en su lugar en vez de un snap direccional. */
const GENTLE_DIALOG_MOTION =
    "duration-300 ease-out data-[state=open]:slide-in-from-top-[1%] data-[state=closed]:slide-out-to-top-[1%] " +
    "data-[state=open]:slide-in-from-left-0 data-[state=closed]:slide-out-to-left-0";

/**
 * Modal unificado para crear tickets (Resolbeb / listados legacy / dashboard
 * del solicitante). El caller sigue dueño de la llamada a POST /api/tickets
 * (onSubmit) -- este componente solo junta subject/descripción con markdown,
 * adjuntos pendientes y clasificación, y le pasa los adjuntos al submit para
 * que el caller los suba tras crear el ticket (mismo patrón que
 * Resolbeb/Create.jsx).
 */
export function TicketCreateDialog({
    open,
    onOpenChange,
    form,
    setForm,
    catalogs,
    saving,
    onSubmit,
    /** { siteName, clientName } — site/cliente del solicitante (solo lectura) */
    siteContext = null,
    /** Ticket recién creado: si viene, se muestra la vista de éxito en vez del formulario. */
    successTicketId = null,
    onSuccessClose,
    /** (ticketId) => href -- default /resolbeb/tickets/{id} */
    viewTicketHref = (id) => `/resolbeb/tickets/${id}`,
}) {
    const autoSite = Boolean(siteContext?.siteName);
    const descriptionRef = useRef(null);
    const [pendingFiles, setPendingFiles] = useState([]);

    // Adjuntos son un draft local: se limpian cada vez que el modal se cierra
    // (éxito, cancelar, o click afuera) para que el siguiente ticket empiece limpio.
    useEffect(() => {
        if (!open) setPendingFiles([]);
    }, [open]);

    const addFiles = (fileList) => {
        const incoming = Array.from(fileList || []);
        const tooLarge = incoming.filter((f) => f.size > MAX_ATTACHMENT_BYTES);
        if (tooLarge.length > 0) {
            notify.error(`${tooLarge.length === 1 ? "Un archivo supera" : `${tooLarge.length} archivos superan`} el límite de 10 MB.`);
        }
        const accepted = incoming.filter((f) => f.size <= MAX_ATTACHMENT_BYTES);
        if (accepted.length > 0) setPendingFiles((prev) => [...prev, ...accepted]);
    };
    const removeFile = (index) => setPendingFiles((prev) => prev.filter((_, i) => i !== index));

    const handleSubmit = (e) => {
        e.preventDefault();
        onSubmit(e, pendingFiles);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={`sm:max-w-xl gap-0 overflow-hidden p-0 [&>div.flex-1]:px-0 [&>div.flex-1]:pt-0 [&>div.flex-1]:pb-0 ${GENTLE_DIALOG_MOTION}`}>
                {successTicketId ? (
                    <>
                        <DialogHeader className="space-y-1 border-b border-border/60 bg-emerald-500/10 px-6 py-5 pr-12 text-left sm:pr-14">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                                    <CheckCircle2 className="h-5 w-5" aria-hidden />
                                </div>
                                <div className="min-w-0">
                                    <DialogTitle className="text-lg font-semibold tracking-tight">
                                        Solicitud registrada
                                    </DialogTitle>
                                    <DialogDescription className="text-sm text-muted-foreground">
                                        Tu ticket <strong>#{String(successTicketId).padStart(5, "0")}</strong> fue creado. Te avisaremos cuando haya novedades.
                                    </DialogDescription>
                                </div>
                            </div>
                        </DialogHeader>
                        <div className="flex flex-wrap items-center gap-2 px-6 py-5">
                            <Button asChild size="sm">
                                <a href={viewTicketHref(successTicketId)}>Ver solicitud</a>
                            </Button>
                            <Button type="button" variant="outline" size="sm" onClick={onSuccessClose}>
                                Cerrar
                            </Button>
                        </div>
                    </>
                ) : (
                    <>
                        <DialogHeader className="space-y-1 border-b border-border/60 px-6 py-5 pr-12 text-left sm:pr-14">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Ticket className="h-5 w-5" aria-hidden />
                                </div>
                                <div className="min-w-0">
                                    <DialogTitle className="text-lg font-semibold tracking-tight">
                                        Nuevo ticket
                                    </DialogTitle>
                                    <DialogDescription className="text-sm text-muted-foreground">
                                        Registra el incidente con la información mínima para asignarlo al área correcta.
                                    </DialogDescription>
                                </div>
                            </div>
                        </DialogHeader>

                        <form onSubmit={handleSubmit} className="flex flex-col">
                            <div className="max-h-[65vh] space-y-5 overflow-y-auto px-6 py-5">
                                <Field label="Asunto" required>
                                    <Input
                                        required
                                        autoFocus
                                        placeholder="Ej. Fallo en impresora de recepción"
                                        value={form.subject}
                                        onChange={(e) => setForm({ ...form, subject: e.target.value })}
                                    />
                                </Field>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="Tipo" required>
                                        <Select
                                            value={form.ticket_type_id}
                                            onValueChange={(v) => setForm({ ...form, ticket_type_id: v })}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Seleccionar tipo" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(catalogs.ticket_types || []).map((t) => (
                                                    <SelectItem key={t.id} value={String(t.id)}>
                                                        {t.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field label="Prioridad" required>
                                        <Select
                                            value={form.priority_id}
                                            onValueChange={(v) => setForm({ ...form, priority_id: v })}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Seleccionar prioridad" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(catalogs.priorities || []).map((p) => (
                                                    <SelectItem key={p.id} value={String(p.id)}>
                                                        {p.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                </div>

                                <SectionHeading>Ubicación y asignación</SectionHeading>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    {autoSite ? (
                                        <>
                                            <Field label="Cliente">
                                                <div className="flex h-10 items-center gap-2 rounded-md border border-border/60 bg-muted/30 px-3 text-sm">
                                                    <Building2 className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                                                    <span>{siteContext.clientName || "Sin cliente"}</span>
                                                </div>
                                            </Field>
                                            <Field label="Sede">
                                                <div className="flex h-10 items-center gap-2 rounded-md border border-border/60 bg-muted/30 px-3 text-sm">
                                                    <MapPin className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                                                    <span>{siteContext.siteName}</span>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    Se asignan automáticamente según tu perfil.
                                                </p>
                                            </Field>
                                        </>
                                    ) : (
                                        <Field label="Sede" required className="sm:col-span-2">
                                            <Select
                                                value={form.site_id}
                                                onValueChange={(v) => setForm({ ...form, site_id: v })}
                                            >
                                                <div className="relative">
                                                    <MapPin className="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                                                    <SelectTrigger className="pl-9">
                                                        <SelectValue placeholder="Seleccionar sede" />
                                                    </SelectTrigger>
                                                </div>
                                                <SelectContent>
                                                    {(catalogs.sites || []).map((s) => (
                                                        <SelectItem key={s.id} value={String(s.id)}>
                                                            {s.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <p className="text-xs text-destructive">
                                                Tu usuario no tiene sede asignada; contacta al administrador.
                                            </p>
                                        </Field>
                                    )}
                                    <Field label="Asignar a área" required className={autoSite ? "" : "sm:col-span-2"}>
                                        <Select
                                            value={form.area_current_id}
                                            onValueChange={(v) => setForm({ ...form, area_current_id: v })}
                                        >
                                            <div className="relative">
                                                <User className="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                                                <SelectTrigger className="pl-9">
                                                    <SelectValue placeholder="Área responsable" />
                                                </SelectTrigger>
                                            </div>
                                            <SelectContent>
                                                {(catalogs.areas || []).map((a) => (
                                                    <SelectItem key={a.id} value={String(a.id)}>
                                                        {a.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                </div>

                                <Field label="Área solicitante (origen)" hint="Quién reporta el incidente.">
                                    <Select
                                        value={form.area_origin_id}
                                        onValueChange={(v) => setForm({ ...form, area_origin_id: v })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Seleccionar área de origen" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(catalogs.areas || []).map((a) => (
                                                <SelectItem key={a.id} value={String(a.id)}>
                                                    {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>

                                <Field label="Detalle del incidente">
                                    <div className="space-y-1.5">
                                        <MarkdownToolbar
                                            textareaRef={descriptionRef}
                                            onChange={(v) => setForm({ ...form, description: v })}
                                            disabled={saving}
                                        />
                                        <Textarea
                                            ref={descriptionRef}
                                            className="min-h-[100px] resize-y"
                                            placeholder="Qué ocurrió, cuándo y si hay mensajes de error…"
                                            value={form.description}
                                            onChange={(e) => setForm({ ...form, description: e.target.value })}
                                        />
                                    </div>
                                </Field>

                                <Field
                                    label="Adjuntar evidencia"
                                    hint="Capturas de pantalla, fotos u otros archivos de soporte. Máx. 10 MB por archivo."
                                >
                                    <Input
                                        type="file"
                                        multiple
                                        accept="image/*,.pdf"
                                        disabled={saving}
                                        onChange={(e) => {
                                            addFiles(e.target.files);
                                            e.target.value = "";
                                        }}
                                    />
                                    {pendingFiles.length > 0 && (
                                        <ul className="mt-2 space-y-1.5">
                                            {pendingFiles.map((file, index) => (
                                                <li
                                                    key={`${file.name}-${index}`}
                                                    className="flex items-center justify-between gap-2 rounded-md border border-border/60 bg-muted/20 px-3 py-1.5 text-sm"
                                                >
                                                    <span className="flex min-w-0 items-center gap-1.5 truncate">
                                                        <Paperclip className="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden />
                                                        <span className="truncate">{file.name}</span>
                                                    </span>
                                                    <span className="flex shrink-0 items-center gap-2">
                                                        <span className="text-xs text-muted-foreground">
                                                            {Math.round(file.size / 1024)} KB
                                                        </span>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-6 w-6"
                                                            disabled={saving}
                                                            onClick={() => removeFile(index)}
                                                        >
                                                            <X className="h-3.5 w-3.5" aria-hidden />
                                                        </Button>
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Field>
                            </div>

                            <DialogFooter className="gap-2 border-t border-border/60 bg-muted/30 px-6 py-4 sm:justify-end">
                                <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
                                    Cancelar
                                </Button>
                                <Button type="submit" disabled={saving}>
                                    {saving ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
                                    ) : (
                                        <CheckCircle2 className="mr-2 h-4 w-4" aria-hidden />
                                    )}
                                    Crear ticket
                                </Button>
                            </DialogFooter>
                        </form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
