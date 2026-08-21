import { useMemo, useState } from "react";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Dialog,
    DialogContent,
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
import { Loader2 } from "lucide-react";

const CONDITIONS = [
    { value: "NUEVO", label: "Nuevo" },
    { value: "BUENO", label: "Bueno" },
    { value: "REGULAR", label: "Regular" },
    { value: "MALO", label: "Malo" },
    { value: "PARA_PIEZAS", label: "Para piezas" },
];

const NONE = "__none__";

/**
 * Alta/edición de un activo como diálogo (paridad con HelpdeskECD2026 --
 * un solo modal para los dos modos, montado sobre el listado o sobre el
 * detalle, no una página aparte). Las fotos NO viven aquí -- Helpdesk
 * tampoco las mete en este formulario; en Tikara viven en la card "Fotos"
 * de Show.jsx, que sí existe como página de detalle rica (Helpdesk no
 * tiene una, por eso allá usa un segundo modal para fotos).
 *
 * El padre debe montar este componente con `key={asset?.id ?? "new"}`
 * para que el estado interno se reinicie al cambiar de activo objetivo.
 */
export default function AssetFormDialog({ open, onOpenChange, asset, categories, manufacturers, statuses, labels, sites, locations, specSchema, onSaved }) {
    const isEdit = !!asset;

    const [data, setData] = useState({
        internal_tag: asset?.internal_tag ?? "",
        serial: asset?.serial ?? "",
        name: asset?.name ?? "",
        category_id: asset?.category_id ? String(asset.category_id) : "",
        manufacturer_id: asset?.manufacturer_id ? String(asset.manufacturer_id) : NONE,
        model: asset?.model ?? "",
        status_id: asset?.status_id ? String(asset.status_id) : "",
        label_id: asset?.label_id ? String(asset.label_id) : NONE,
        condition: asset?.condition ?? NONE,
        site_id: asset?.site_id ? String(asset.site_id) : "",
        location_id: asset?.location_id ? String(asset.location_id) : NONE,
        // Ficha técnica estructurada (fase 2.1) -- {key: value}, no más el
        // textarea libre de antes (specs.notes, ya no se escribe).
        specs: Object.fromEntries((asset?.specs ?? []).map((s) => [s.key, s.value ?? ""])),
        cost: asset?.cost ?? "",
        purchase_date: asset?.purchase_date ?? "",
        warranty_expiry: asset?.warranty_expiry ?? "",
        supplier: asset?.supplier ?? "",
        invoice_number: asset?.invoice_number ?? "",
        notes: asset?.notes ?? "",
    });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const setField = (key, value) => {
        setData((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => {
            if (!prev[key]) return prev;
            const next = { ...prev };
            delete next[key];
            return next;
        });
    };

    const filteredLocations = useMemo(() => {
        if (!data.site_id) return [];
        return (locations ?? []).filter((l) => String(l.site_id) === data.site_id);
    }, [locations, data.site_id]);

    const selectedCategory = useMemo(
        () => (categories ?? []).find((c) => String(c.id) === data.category_id),
        [categories, data.category_id]
    );
    const specFields = specSchema?.[selectedCategory?.type] ?? [];

    const setSpecField = (key, value) => {
        setData((prev) => ({ ...prev, specs: { ...prev.specs, [key]: value } }));
    };

    const handleSiteChange = (value) => {
        setData((prev) => ({ ...prev, site_id: value, location_id: NONE }));
        setErrors((prev) => {
            if (!prev.site_id) return prev;
            const next = { ...prev };
            delete next.site_id;
            return next;
        });
    };

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        const payload = {
            ...data,
            label_id: data.label_id === NONE ? null : data.label_id,
            manufacturer_id: data.manufacturer_id === NONE ? null : data.manufacturer_id,
            condition: data.condition === NONE ? null : data.condition,
            location_id: data.location_id === NONE ? null : data.location_id,
            cost: data.cost === "" ? null : data.cost,
            specs: Object.entries(data.specs).map(([key, value]) => ({ key, value })),
        };

        try {
            if (isEdit) {
                await axios.put(`/api/inv-assets/${asset.id}`, payload);
                notify.success("Activo actualizado correctamente");
            } else {
                await axios.post("/api/inv-assets", payload);
                notify.success("Activo creado correctamente");
            }
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            if (handleAuthError(err)) return;
            if (err.response?.status === 422) {
                setErrors(err.response.data.errors ?? {});
                notify.error("Revisa los campos marcados.");
            } else {
                notify.error(getApiErrorMessage(err, "No se pudo guardar el activo"));
            }
        } finally {
            setSaving(false);
        }
    };

    const fieldError = (key) => {
        const e = errors[key];
        if (Array.isArray(e) && e.length) return e[0];
        if (typeof e === "string") return e;
        return null;
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{isEdit ? `Editar activo — ${asset.name}` : "Nuevo activo"}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-1.5 md:col-span-2">
                            <Label>Nombre *</Label>
                            <Input value={data.name} onChange={(e) => setField("name", e.target.value)} placeholder="Ej. Laptop Dell Latitude 5420" />
                            {fieldError("name") && <p className="text-xs text-destructive">{fieldError("name")}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Número de inventario *</Label>
                            <Input value={data.internal_tag} onChange={(e) => setField("internal_tag", e.target.value)} placeholder="Ej. LAP-0001" />
                            {fieldError("internal_tag") && <p className="text-xs text-destructive">{fieldError("internal_tag")}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Número de serie</Label>
                            <Input value={data.serial} onChange={(e) => setField("serial", e.target.value)} />
                            {fieldError("serial") && <p className="text-xs text-destructive">{fieldError("serial")}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Categoría *</Label>
                            <Select value={data.category_id} onValueChange={(v) => setField("category_id", v)}>
                                <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                <SelectContent>
                                    {(categories ?? []).map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError("category_id") && <p className="text-xs text-destructive">{fieldError("category_id")}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Fabricante</Label>
                            <Select value={data.manufacturer_id} onValueChange={(v) => setField("manufacturer_id", v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Sin especificar</SelectItem>
                                    {(manufacturers ?? []).map((m) => (
                                        <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Modelo</Label>
                            <Input value={data.model} onChange={(e) => setField("model", e.target.value)} placeholder="Ej. Latitude 5420" />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Estatus *</Label>
                            <Select value={data.status_id} onValueChange={(v) => setField("status_id", v)}>
                                <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                <SelectContent>
                                    {(statuses ?? []).map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError("status_id") && <p className="text-xs text-destructive">{fieldError("status_id")}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Etiqueta</Label>
                            <Select value={data.label_id} onValueChange={(v) => setField("label_id", v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Sin etiqueta</SelectItem>
                                    {(labels ?? []).map((l) => (
                                        <SelectItem key={l.id} value={String(l.id)}>{l.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Condición</Label>
                            <Select value={data.condition} onValueChange={(v) => setField("condition", v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Sin especificar</SelectItem>
                                    {CONDITIONS.map((c) => (
                                        <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Sede *</Label>
                            <Select value={data.site_id} onValueChange={handleSiteChange}>
                                <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                <SelectContent>
                                    {(sites ?? []).map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError("site_id") && <p className="text-xs text-destructive">{fieldError("site_id")}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Ubicación</Label>
                            <Select value={data.location_id} onValueChange={(v) => setField("location_id", v)} disabled={!data.site_id}>
                                <SelectTrigger><SelectValue placeholder={data.site_id ? "Seleccionar…" : "Elige una sede primero"} /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Sin ubicación</SelectItem>
                                    {filteredLocations.map((l) => (
                                        <SelectItem key={l.id} value={String(l.id)}>{l.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Costo</Label>
                            <Input type="number" min="0" step="0.01" value={data.cost} onChange={(e) => setField("cost", e.target.value)} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Proveedor</Label>
                            <Input value={data.supplier} onChange={(e) => setField("supplier", e.target.value)} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Número de factura</Label>
                            <Input value={data.invoice_number} onChange={(e) => setField("invoice_number", e.target.value)} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Fecha de compra</Label>
                            <Input type="date" value={data.purchase_date ?? ""} onChange={(e) => setField("purchase_date", e.target.value)} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Vencimiento de garantía</Label>
                            <Input type="date" value={data.warranty_expiry ?? ""} onChange={(e) => setField("warranty_expiry", e.target.value)} />
                        </div>

                        {specFields.length > 0 && (
                            <div className="space-y-2 md:col-span-2">
                                <Label>Especificaciones técnicas</Label>
                                <div className="grid gap-3 sm:grid-cols-2 rounded-lg border p-3">
                                    {specFields.map((field) => (
                                        <div key={field.key} className="space-y-1">
                                            <Label className="text-xs font-normal text-muted-foreground">{field.label}</Label>
                                            <Input
                                                value={data.specs[field.key] ?? ""}
                                                onChange={(e) => setSpecField(field.key, e.target.value)}
                                            />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="space-y-1.5 md:col-span-2">
                            <Label>Notas</Label>
                            <Textarea rows={3} value={data.notes} onChange={(e) => setField("notes", e.target.value)} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={saving}>
                            {saving ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Guardando…
                                </>
                            ) : isEdit ? "Guardar cambios" : "Crear activo"}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
