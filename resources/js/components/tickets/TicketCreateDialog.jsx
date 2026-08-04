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
import { Building2, CheckCircle2, Loader2, MapPin, Ticket, User } from "lucide-react";
import { Field, SectionHeading } from "@/components/tickets/TicketFormFields";

/**
 * Modal unificado para crear tickets (Resolbeb / listados legacy).
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
}) {
    const autoSite = Boolean(siteContext?.siteName);
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl gap-0 overflow-hidden p-0 [&>div.flex-1]:px-0 [&>div.flex-1]:pt-0 [&>div.flex-1]:pb-0">
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

                <form onSubmit={onSubmit} className="flex flex-col">
                    <div className="space-y-5 px-6 py-5">
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
                            <Textarea
                                className="min-h-[100px] resize-y"
                                placeholder="Qué ocurrió, cuándo y si hay mensajes de error…"
                                value={form.description}
                                onChange={(e) => setForm({ ...form, description: e.target.value })}
                            />
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
            </DialogContent>
        </Dialog>
    );
}
