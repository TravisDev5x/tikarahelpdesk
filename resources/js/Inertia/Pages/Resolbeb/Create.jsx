import { useEffect, useMemo, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { Field, SectionHeading } from "@/components/tickets/TicketFormFields";
import { MarkdownToolbar } from "@/components/tickets/MarkdownToolbar";
import { priorityClassByLevel } from "@/lib/badgeStyles";
import { cn } from "@/lib/utils";
import { notify } from "@/lib/notify";
import {
    ArrowLeft,
    Loader2,
    CheckCircle2,
    MapPin,
    Building2,
    User,
    Paperclip,
    X,
} from "lucide-react";

const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

const RESOLVE_BASE = "/resolbeb";

function FieldError({ errors, field }) {
    const message = errors?.[field];
    if (!message) return null;
    const text = Array.isArray(message) ? message[0] : message;
    return <p className="text-sm text-destructive mt-1">{text}</p>;
}

export default function ResolbebCreate({ catalogs: catalogsProp }) {
    const { user, can } = useAuth();
    const catalogs = catalogsProp ?? {};
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [pendingFiles, setPendingFiles] = useState([]);
    const descriptionRef = useRef(null);
    const [form, setForm] = useState({
        subject: "",
        description: "",
        site_id: "",
        area_origin_id: "",
        area_current_id: "",
        ticket_type_id: "",
        impact_level_id: "",
        urgency_level_id: "",
        ticket_state_id: "",
    });

    useEffect(() => {
        if (!catalogs.ticket_states?.length) return;
        const openState =
            catalogs.ticket_states.find((s) => (s.code || "").toLowerCase() === "abierto") ||
            catalogs.ticket_states[0];
        setForm((prev) => ({
            ...prev,
            site_id: prev.site_id || String(user?.site_id || user?.site?.id || ""),
            area_origin_id: prev.area_origin_id || String(user?.area_id || ""),
            ticket_type_id: prev.ticket_type_id || String(catalogs.ticket_types?.[0]?.id || ""),
            impact_level_id: prev.impact_level_id || String(catalogs.impact_levels?.[0]?.id || ""),
            urgency_level_id: prev.urgency_level_id || String(catalogs.urgency_levels?.[0]?.id || ""),
            ticket_state_id: String(openState?.id || ""),
        }));
    }, [
        catalogs.ticket_states,
        catalogs.ticket_types,
        catalogs.impact_levels,
        catalogs.urgency_levels,
        user?.site_id,
        user?.site?.id,
        user?.area_id,
    ]);

    const isSolicitanteOnly = !can("tickets.manage_all") && !can("tickets.view_area");
    const backTo = isSolicitanteOnly ? RESOLVE_BASE : `${RESOLVE_BASE}/tickets`;

    const calculatedPriority = useMemo(() => {
        const matrix = catalogs.priority_matrix || [];
        const row = matrix.find(
            (m) =>
                Number(m.impact_level_id) === Number(form.impact_level_id) &&
                Number(m.urgency_level_id) === Number(form.urgency_level_id)
        );
        const pid = row?.priority_id;
        return (catalogs.priorities || []).find((x) => Number(x.id) === Number(pid)) || null;
    }, [catalogs.priority_matrix, catalogs.priorities, form.impact_level_id, form.urgency_level_id]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setErrors({});

        if (!form.subject?.trim()) {
            notify.error("El asunto es obligatorio");
            return;
        }
        if (!user?.site_id && !user?.site?.id) {
            notify.error("Tu usuario no tiene sede asignada. Contacta al administrador.");
            return;
        }
        if (
            !form.area_origin_id ||
            !form.area_current_id ||
            !form.ticket_type_id ||
            !form.impact_level_id ||
            !form.urgency_level_id ||
            !form.ticket_state_id
        ) {
            notify.error("Completa todos los campos obligatorios (incl. Impacto y Urgencia)");
            return;
        }

        setSaving(true);
        try {
            const now = new Date().toISOString();
            const payload = {
                subject: form.subject.trim(),
                description: form.description?.trim() || null,
                area_origin_id: Number(form.area_origin_id),
                area_current_id: Number(form.area_current_id),
                impact_level_id: Number(form.impact_level_id),
                urgency_level_id: Number(form.urgency_level_id),
                ticket_type_id: Number(form.ticket_type_id),
                ticket_state_id: Number(form.ticket_state_id),
                created_at: now,
            };
            const { data } = await axios.post("/api/tickets", payload);
            notify.success("Ticket creado correctamente");
            const ticketId = data?.id ?? data?.ticket?.id;

            if (ticketId && pendingFiles.length > 0) {
                const attachmentsForm = new FormData();
                pendingFiles.forEach((file) => attachmentsForm.append("attachments[]", file));
                try {
                    await axios.post(`/api/tickets/${ticketId}/attachments`, attachmentsForm);
                } catch {
                    notify.error("El ticket se creó, pero los adjuntos no se pudieron subir.");
                }
            }

            if (ticketId) {
                router.visit(`${RESOLVE_BASE}/tickets/${ticketId}`);
            } else if (isSolicitanteOnly) {
                router.visit(RESOLVE_BASE);
            } else {
                router.visit(`${RESOLVE_BASE}/tickets`);
            }
        } catch (err) {
            if (err?.response?.status === 422) {
                setErrors(err.response.data.errors ?? {});
                notify.error(err?.response?.data?.message || "Revisa los campos del formulario");
            } else {
                notify.error(err?.response?.data?.message || "Error al crear el ticket");
            }
        } finally {
            setSaving(false);
        }
    };

    const addFiles = (fileList) => {
        const incoming = Array.from(fileList || []);
        const tooLarge = incoming.filter((f) => f.size > MAX_ATTACHMENT_BYTES);
        if (tooLarge.length > 0) {
            notify.error(`${tooLarge.length === 1 ? "Un archivo supera" : `${tooLarge.length} archivos superan`} el límite de 10 MB.`);
        }
        const accepted = incoming.filter((f) => f.size <= MAX_ATTACHMENT_BYTES);
        if (accepted.length > 0) setPendingFiles((prev) => [...prev, ...accepted]);
    };

    const removeFile = (index) => {
        setPendingFiles((prev) => prev.filter((_, i) => i !== index));
    };

    const siteName =
        user?.site?.name ||
        (catalogs.sites || []).find((s) => String(s.id) === String(user?.site_id))?.name ||
        "—";

    return (
        <AuthenticatedLayout title="Nuevo ticket">
            <Head title="Nuevo ticket" />

            <div className="w-full space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500 pb-content-mobile">
                <div className="flex flex-col gap-4 border-b border-border/40 pb-6 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-muted-foreground">
                        Completa los datos para registrar tu solicitud.
                    </p>
                    <Button variant="ghost" size="sm" asChild className="-ml-2 sm:ml-0">
                        <a href={backTo}>
                            <ArrowLeft className="h-4 w-4 mr-1" /> Volver
                        </a>
                    </Button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-8">
                    <div className="space-y-4">
                        <SectionHeading>¿Qué pasó?</SectionHeading>
                        <Field label="Asunto" required>
                            <Input
                                required
                                autoFocus
                                placeholder="Ej: Fallo en impresora de recepción"
                                value={form.subject}
                                onChange={(e) => setForm({ ...form, subject: e.target.value })}
                            />
                            <FieldError errors={errors} field="subject" />
                        </Field>
                        <Field label="Descripción del problema">
                            <div className="space-y-1.5">
                                <MarkdownToolbar
                                    textareaRef={descriptionRef}
                                    onChange={(v) => setForm({ ...form, description: v })}
                                    disabled={saving}
                                />
                                <Textarea
                                    ref={descriptionRef}
                                    placeholder="Describe qué ocurrió, cuándo y si hay mensajes de error..."
                                    className="min-h-[120px] resize-y"
                                    value={form.description}
                                    onChange={(e) => setForm({ ...form, description: e.target.value })}
                                />
                            </div>
                            <FieldError errors={errors} field="description" />
                        </Field>

                        <Field
                            label="Adjuntar evidencia (opcional)"
                            hint="Capturas de pantalla, fotos u otros archivos de soporte. Máx. 10 MB por archivo."
                        >
                            <Input
                                type="file"
                                multiple
                                accept="image/*,.pdf"
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

                    <Separator />

                    <div className="space-y-4">
                        <SectionHeading>Clasificación</SectionHeading>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field label="Tipo de ticket">
                                <Select
                                    value={form.ticket_type_id}
                                    onValueChange={(v) => setForm({ ...form, ticket_type_id: v })}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Seleccionar" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(catalogs.ticket_types || []).map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>
                                                {t.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FieldError errors={errors} field="ticket_type_id" />
                            </Field>
                            <Field label="Impacto" required>
                                <Select
                                    value={form.impact_level_id}
                                    onValueChange={(v) => setForm({ ...form, impact_level_id: v })}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Seleccionar" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(catalogs.impact_levels || []).map((i) => (
                                            <SelectItem key={i.id} value={String(i.id)}>
                                                {i.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FieldError errors={errors} field="impact_level_id" />
                            </Field>
                            <Field label="Urgencia" required>
                                <Select
                                    value={form.urgency_level_id}
                                    onValueChange={(v) => setForm({ ...form, urgency_level_id: v })}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Seleccionar" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(catalogs.urgency_levels || []).map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FieldError errors={errors} field="urgency_level_id" />
                            </Field>
                        </div>
                        <div className="flex items-center gap-2 rounded-lg border border-border/50 bg-muted/20 px-3 py-2">
                            <span className="text-xs font-medium text-muted-foreground">
                                Prioridad calculada:
                            </span>
                            {calculatedPriority ? (
                                <Badge
                                    variant="outline"
                                    className={cn("text-xs", priorityClassByLevel(calculatedPriority.level))}
                                >
                                    {calculatedPriority.name}
                                </Badge>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    Selecciona Impacto y Urgencia
                                </span>
                            )}
                        </div>
                    </div>

                    <Separator />

                    <div className="space-y-4">
                        <SectionHeading>Asignación</SectionHeading>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Cliente">
                                <div className="flex h-10 items-center gap-2 rounded-md border border-border/60 bg-muted/30 px-3 text-sm">
                                    <Building2 className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                                    <span>{user?.client_name || "Sin cliente"}</span>
                                </div>
                            </Field>
                            <Field label="Sede" hint="Se asignan automáticamente según tu perfil.">
                                <div className="flex h-10 items-center gap-2 rounded-md border border-border/60 bg-muted/30 px-3 text-sm">
                                    <MapPin className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                                    <span>{siteName}</span>
                                </div>
                            </Field>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Área responsable" required>
                                <Select
                                    value={form.area_current_id}
                                    onValueChange={(v) => setForm({ ...form, area_current_id: v })}
                                >
                                    <div className="relative">
                                        <User className="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                                        <SelectTrigger className="pl-9">
                                            <SelectValue placeholder="Área que atenderá" />
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
                                <FieldError errors={errors} field="area_current_id" />
                            </Field>
                            <Field label="Área de origen" hint="Quién reporta el incidente.">
                                <Select
                                    value={form.area_origin_id}
                                    onValueChange={(v) => setForm({ ...form, area_origin_id: v })}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Tu área" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(catalogs.areas || []).map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FieldError errors={errors} field="area_origin_id" />
                            </Field>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-border/40 pt-6">
                        <Button type="button" variant="ghost" asChild>
                            <a href={backTo}>Cancelar</a>
                        </Button>
                        <Button type="submit" disabled={saving}>
                            {saving ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <CheckCircle2 className="mr-2 h-4 w-4" />
                            )}
                            Crear ticket
                        </Button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
