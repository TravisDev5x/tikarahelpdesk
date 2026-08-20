import { useEffect, useState } from "react";
import AssetFormDialog from "./AssetFormDialog";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Dialog,
    DialogContent,
    DialogFooter,
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
import { ArrowRightLeft, CheckCircle2, Cpu, Loader2, PackageMinus, Pencil, Plus, Trash2, Upload, UserMinus, UserPlus, Wrench } from "lucide-react";

function Field({ label, value }) {
    return (
        <div>
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p className="text-sm">{value ?? "—"}</p>
        </div>
    );
}

function userLabel(user) {
    if (!user) return null;
    return [user.first_name, user.paternal_last_name, user.maternal_last_name].filter(Boolean).join(" ");
}

const MOVEMENT_LABELS = {
    CHECKOUT: "Asignación",
    CHECKIN: "Devolución",
    TRASLADO: "Traslado",
    BAJA: "Baja",
    MAINTENANCE: "Mantenimiento",
};

const NONE = "__none__";

/**
 * Detalle de un activo como diálogo (el usuario pidió paridad con el
 * modal de alta/edición ya construido -- ver AssetFormDialog.jsx). Antes
 * era una página aparte (`/inventory/assets/{id}`, InvAssetPageController::
 * show()); ahora vive montado sobre Index.jsx, con `assetId` como única
 * entrada -- el detalle completo (movimientos/componentes/mantenimientos/
 * fotos) se trae de /api/inv-assets/{id} al abrir, no de props de página.
 */
export default function AssetDetailDialog({ open, onOpenChange, assetId, categories, statuses, labels, sites, locations, clientUsers, maintenanceOrigins, maintenanceModalities, onChanged }) {
    const [asset, setAsset] = useState(null);
    const [loading, setLoading] = useState(false);
    const [openDialog, setOpenDialog] = useState(null); // 'checkout' | 'checkin' | 'transfer' | 'retire' | null
    const [saving, setSaving] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [uploadingImages, setUploadingImages] = useState(false);

    const [checkoutUserId, setCheckoutUserId] = useState("");
    const [transferSiteId, setTransferSiteId] = useState("");
    const [transferLocationId, setTransferLocationId] = useState(NONE);
    const [retireStatusId, setRetireStatusId] = useState("");
    const [retireReason, setRetireReason] = useState("");

    const [selectedComponents, setSelectedComponents] = useState([]);
    const [newComponent, setNewComponent] = useState({ name: "", marca: "", modelo: "", serie: "", capacidad: "" });

    const [maintenanceForm, setMaintenanceForm] = useState({ origin_id: NONE, modality_id: NONE, title: "", diagnosis: "", start_date: "" });
    const [closingMaintenanceId, setClosingMaintenanceId] = useState(null);
    const [closeForm, setCloseForm] = useState({ end_date: "", solution: "", cost: "" });

    const fetchAsset = async () => {
        if (!assetId) return;
        setLoading(true);
        try {
            const { data } = await axios.get(`/api/inv-assets/${assetId}`);
            setAsset(data);
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo cargar el activo"));
            }
            onOpenChange(false);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open && assetId) {
            fetchAsset();
        } else {
            setAsset(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, assetId]);

    const close = () => {
        setOpenDialog(null);
        setCheckoutUserId("");
        setTransferSiteId("");
        setTransferLocationId(NONE);
        setRetireStatusId("");
        setRetireReason("");
        setNewComponent({ name: "", marca: "", modelo: "", serie: "", capacidad: "" });
        setMaintenanceForm({ origin_id: NONE, modality_id: NONE, title: "", diagnosis: "", start_date: "" });
        setClosingMaintenanceId(null);
        setCloseForm({ end_date: "", solution: "", cost: "" });
        if (openDialog === "disassemble") setSelectedComponents([]);
    };

    const toggleComponent = (id) => {
        setSelectedComponents((prev) =>
            prev.includes(id) ? prev.filter((c) => c !== id) : [...prev, id]
        );
    };

    const reload = () => {
        fetchAsset();
        onChanged?.();
    };

    const uploadImages = async (fileList) => {
        if (!fileList?.length) return;
        setUploadingImages(true);
        const form = new FormData();
        Array.from(fileList).forEach((file) => form.append("images[]", file));
        try {
            await axios.post(`/api/inv-assets/${asset.id}/images`, form, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            notify.success("Fotos agregadas");
            reload();
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudieron subir las fotos"));
            }
        } finally {
            setUploadingImages(false);
        }
    };

    const deleteImage = async (image) => {
        try {
            await axios.delete(`/api/inv-assets/${asset.id}/images/${image.id}`);
            reload();
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo borrar la foto"));
            }
        }
    };

    const runAction = async (url, payload, successMessage) => {
        setSaving(true);
        try {
            await axios.post(url, payload);
            notify.success(successMessage);
            close();
            reload();
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo completar la acción"));
            }
        } finally {
            setSaving(false);
        }
    };

    const runPut = async (url, payload, successMessage) => {
        setSaving(true);
        try {
            await axios.put(url, payload);
            notify.success(successMessage);
            close();
            reload();
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo completar la acción"));
            }
        } finally {
            setSaving(false);
        }
    };

    const openCloseMaintenance = (m) => {
        setClosingMaintenanceId(m.id);
        setCloseForm({ end_date: new Date().toISOString().slice(0, 10), solution: "", cost: "" });
        setOpenDialog("close-maintenance");
    };

    const retirableStatuses = (statuses ?? []).filter((s) => !s.assignable);
    const siteLocations = (locations ?? []).filter((l) => String(l.site_id) === transferSiteId);

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                    {loading || !asset ? (
                        <div className="flex items-center justify-center py-16">
                            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                        </div>
                    ) : (
                        <div className="space-y-6">
                            <DialogHeader>
                                <div className="flex items-center justify-between gap-3 pr-6">
                                    <div>
                                        <DialogTitle>{asset.name}</DialogTitle>
                                        <p className="text-sm text-muted-foreground font-mono">{asset.internal_tag}</p>
                                    </div>
                                    <Button size="sm" onClick={() => setEditOpen(true)}>
                                        <Pencil className="mr-2 h-4 w-4" />
                                        Editar
                                    </Button>
                                </div>
                            </DialogHeader>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Detalle</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-4 md:grid-cols-3">
                                    <Field label="Categoría" value={asset.category?.name} />
                                    <Field
                                        label="Estatus"
                                        value={asset.status ? <Badge variant="outline">{asset.status.name}</Badge> : null}
                                    />
                                    <Field label="Etiqueta" value={asset.label?.name} />
                                    <Field label="Condición" value={asset.condition} />
                                    <Field label="Número de serie" value={asset.serial} />
                                    <Field label="Sede" value={asset.site?.name} />
                                    <Field label="Ubicación" value={asset.location?.name} />
                                    <Field label="Costo" value={asset.cost ? `$${asset.cost}` : null} />
                                    <Field label="Proveedor" value={asset.supplier} />
                                    <Field label="Número de factura" value={asset.invoice_number} />
                                    <Field label="Fecha de compra" value={asset.purchase_date} />
                                    <Field label="Vencimiento de garantía" value={asset.warranty_expiry} />
                                    <Field label="Especificaciones" value={asset.specs?.notes} />
                                    <div className="md:col-span-3">
                                        <Field label="Notas" value={asset.notes} />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Responsable y ciclo de vida</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <Field label="Responsable actual" value={userLabel(asset.current_user) ?? "Sin asignar"} />
                                    <div className="flex flex-wrap gap-2">
                                        {!asset.current_user_id ? (
                                            <Button size="sm" onClick={() => setOpenDialog("checkout")}>
                                                <UserPlus className="mr-2 h-4 w-4" />
                                                Asignar
                                            </Button>
                                        ) : (
                                            <Button size="sm" variant="outline" onClick={() => setOpenDialog("checkin")}>
                                                <UserMinus className="mr-2 h-4 w-4" />
                                                Devolver
                                            </Button>
                                        )}
                                        <Button size="sm" variant="outline" onClick={() => setOpenDialog("transfer")}>
                                            <ArrowRightLeft className="mr-2 h-4 w-4" />
                                            Trasladar
                                        </Button>
                                        <Button size="sm" variant="outline" className="text-destructive" onClick={() => setOpenDialog("retire")}>
                                            <PackageMinus className="mr-2 h-4 w-4" />
                                            Dar de baja
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                    <CardTitle>Componentes</CardTitle>
                                    <Button size="sm" variant="outline" onClick={() => setOpenDialog("add-component")}>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Agregar componente
                                    </Button>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {(asset.components ?? []).length === 0 ? (
                                        <p className="text-sm text-muted-foreground">Este activo no tiene componentes registrados.</p>
                                    ) : (
                                        <>
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead className="w-10" />
                                                        <TableHead>Nombre</TableHead>
                                                        <TableHead>Marca / Modelo</TableHead>
                                                        <TableHead>Serie</TableHead>
                                                        <TableHead>Estatus</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {asset.components.map((c) => (
                                                        <TableRow key={c.id}>
                                                            <TableCell>
                                                                <Checkbox
                                                                    checked={selectedComponents.includes(c.id)}
                                                                    onCheckedChange={() => toggleComponent(c.id)}
                                                                />
                                                            </TableCell>
                                                            <TableCell className="flex items-center gap-2"><Cpu className="h-3.5 w-3.5 text-muted-foreground" />{c.name}</TableCell>
                                                            <TableCell className="text-sm text-muted-foreground">{[c.marca, c.modelo].filter(Boolean).join(" / ") || "—"}</TableCell>
                                                            <TableCell className="text-sm font-mono">{c.serie || "—"}</TableCell>
                                                            <TableCell className="text-sm">{c.status || "—"}</TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="text-destructive"
                                                disabled={selectedComponents.length === 0}
                                                onClick={() => setOpenDialog("disassemble")}
                                            >
                                                <Wrench className="mr-2 h-4 w-4" />
                                                Desarmar seleccionados ({selectedComponents.length})
                                            </Button>
                                        </>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                    <CardTitle>Mantenimientos</CardTitle>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            setMaintenanceForm((p) => ({ ...p, start_date: new Date().toISOString().slice(0, 10) }));
                                            setOpenDialog("register-maintenance");
                                        }}
                                    >
                                        <Wrench className="mr-2 h-4 w-4" />
                                        Registrar mantenimiento
                                    </Button>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Título</TableHead>
                                                <TableHead>Origen</TableHead>
                                                <TableHead>Modalidad</TableHead>
                                                <TableHead>Inicio</TableHead>
                                                <TableHead>Estatus</TableHead>
                                                <TableHead className="w-10" />
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {(asset.maintenances ?? []).length === 0 ? (
                                                <TableRow>
                                                    <TableCell colSpan={6} className="text-center text-muted-foreground py-6">
                                                        Este activo no tiene mantenimientos registrados.
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                asset.maintenances.map((m) => (
                                                    <TableRow key={m.id}>
                                                        <TableCell className="text-sm">{m.title}</TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">{m.origin?.name || "—"}</TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">{m.modality?.name || "—"}</TableCell>
                                                        <TableCell className="text-sm">{m.start_date}</TableCell>
                                                        <TableCell>
                                                            {m.end_date ? (
                                                                <Badge variant="outline">Cerrado {m.end_date}</Badge>
                                                            ) : (
                                                                <Badge variant="secondary">Abierto</Badge>
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {!m.end_date && (
                                                                <Button size="sm" variant="ghost" onClick={() => openCloseMaintenance(m)}>
                                                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                                                    Cerrar
                                                                </Button>
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ))
                                            )}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Fotos</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="flex flex-wrap gap-3">
                                        {(asset.images ?? []).map((img) => (
                                            <div key={img.id} className="relative group">
                                                <img
                                                    src={`/storage/${img.path}`}
                                                    alt=""
                                                    className="h-32 w-32 rounded-md object-cover border"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => deleteImage(img)}
                                                    className="absolute -top-2 -right-2 rounded-full bg-destructive text-destructive-foreground p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                                                >
                                                    <Trash2 className="h-3 w-3" />
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                    <div>
                                        <Label htmlFor="asset-detail-images" className="inline-flex items-center gap-2 cursor-pointer text-sm text-brand-muted hover:underline">
                                            <Upload className="h-4 w-4" />
                                            {uploadingImages ? "Subiendo…" : "Agregar fotos"}
                                        </Label>
                                        <input
                                            id="asset-detail-images"
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            className="hidden"
                                            disabled={uploadingImages}
                                            onChange={(e) => uploadImages(e.target.files)}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Historial de movimientos</CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Fecha</TableHead>
                                                <TableHead>Tipo</TableHead>
                                                <TableHead>Usuario</TableHead>
                                                <TableHead>Registrado por</TableHead>
                                                <TableHead>Notas</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {(asset.movements ?? []).length === 0 ? (
                                                <TableRow>
                                                    <TableCell colSpan={5} className="text-center text-muted-foreground py-6">
                                                        Sin movimientos todavía.
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                asset.movements.map((m) => (
                                                    <TableRow key={m.id}>
                                                        <TableCell className="text-sm">{new Date(m.date).toLocaleString("es-ES")}</TableCell>
                                                        <TableCell><Badge variant="outline">{MOVEMENT_LABELS[m.type] ?? m.type}</Badge></TableCell>
                                                        <TableCell className="text-sm">{userLabel(m.user) ?? "—"}</TableCell>
                                                        <TableCell className="text-sm">{userLabel(m.admin) ?? "—"}</TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">{m.reason || m.notes || "—"}</TableCell>
                                                    </TableRow>
                                                ))
                                            )}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {asset && (
                <>
                    {/* Asignar */}
                    <Dialog open={openDialog === "checkout"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>Asignar activo</DialogTitle></DialogHeader>
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label>Responsable *</Label>
                                    <Select value={checkoutUserId} onValueChange={setCheckoutUserId}>
                                        <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                        <SelectContent>
                                            {(clientUsers ?? []).map((u) => (
                                                <SelectItem key={u.id} value={String(u.id)}>{userLabel(u)}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    disabled={!checkoutUserId || saving}
                                    onClick={() => runAction(`/api/inv-assets/${asset.id}/checkout`, { user_id: checkoutUserId }, "Activo asignado")}
                                >
                                    Asignar
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Devolver */}
                    <Dialog open={openDialog === "checkin"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>¿Confirmar devolución?</DialogTitle></DialogHeader>
                            <p className="text-sm text-muted-foreground">
                                Se liberará el activo de <strong>{userLabel(asset.current_user)}</strong>.
                            </p>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    disabled={saving}
                                    onClick={() => runAction(`/api/inv-assets/${asset.id}/checkin`, {}, "Activo devuelto")}
                                >
                                    Devolver
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Trasladar */}
                    <Dialog open={openDialog === "transfer"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>Trasladar activo</DialogTitle></DialogHeader>
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label>Nueva sede *</Label>
                                    <Select value={transferSiteId} onValueChange={(v) => { setTransferSiteId(v); setTransferLocationId(NONE); }}>
                                        <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                        <SelectContent>
                                            {(sites ?? []).map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Nueva ubicación</Label>
                                    <Select value={transferLocationId} onValueChange={setTransferLocationId} disabled={!transferSiteId}>
                                        <SelectTrigger><SelectValue placeholder={transferSiteId ? "Seleccionar…" : "Elige una sede primero"} /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>Sin ubicación</SelectItem>
                                            {siteLocations.map((l) => (
                                                <SelectItem key={l.id} value={String(l.id)}>{l.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    disabled={!transferSiteId || saving}
                                    onClick={() => runAction(`/api/inv-assets/${asset.id}/transfer`, {
                                        site_id: transferSiteId,
                                        location_id: transferLocationId === NONE ? null : transferLocationId,
                                    }, "Activo trasladado")}
                                >
                                    Trasladar
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Dar de baja */}
                    <Dialog open={openDialog === "retire"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>Dar de baja</DialogTitle></DialogHeader>
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label>Estatus de baja *</Label>
                                    <Select value={retireStatusId} onValueChange={setRetireStatusId}>
                                        <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                        <SelectContent>
                                            {retirableStatuses.length === 0 ? (
                                                <div className="px-2 py-1.5 text-xs text-muted-foreground">
                                                    No hay estatus marcados como "no asignable" — créalo primero en Inventario &gt; Configuración &gt; Estatus.
                                                </div>
                                            ) : (
                                                retirableStatuses.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Motivo *</Label>
                                    <Textarea rows={2} value={retireReason} onChange={(e) => setRetireReason(e.target.value)} placeholder="Ej. Equipo dañado sin reparación viable" />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    variant="destructive"
                                    disabled={!retireStatusId || !retireReason.trim() || saving}
                                    onClick={() => runAction(`/api/inv-assets/${asset.id}/retire`, {
                                        status_id: retireStatusId,
                                        reason: retireReason,
                                    }, "Activo dado de baja")}
                                >
                                    Dar de baja
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Agregar componente */}
                    <Dialog open={openDialog === "add-component"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>Agregar componente</DialogTitle></DialogHeader>
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label>Nombre *</Label>
                                    <Input
                                        value={newComponent.name}
                                        onChange={(e) => setNewComponent((p) => ({ ...p, name: e.target.value }))}
                                        placeholder="Ej. Memoria RAM 16GB"
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>Marca</Label>
                                        <Input value={newComponent.marca} onChange={(e) => setNewComponent((p) => ({ ...p, marca: e.target.value }))} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Modelo</Label>
                                        <Input value={newComponent.modelo} onChange={(e) => setNewComponent((p) => ({ ...p, modelo: e.target.value }))} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Serie</Label>
                                        <Input value={newComponent.serie} onChange={(e) => setNewComponent((p) => ({ ...p, serie: e.target.value }))} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Capacidad</Label>
                                        <Input value={newComponent.capacidad} onChange={(e) => setNewComponent((p) => ({ ...p, capacidad: e.target.value }))} placeholder="Ej. 16GB" />
                                    </div>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    disabled={!newComponent.name.trim() || saving}
                                    onClick={() => runAction("/api/inv-components", { ...newComponent, asset_id: asset.id }, "Componente agregado")}
                                >
                                    Agregar
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Desarmar (despiece) */}
                    <Dialog open={openDialog === "disassemble"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>¿Desarmar {selectedComponents.length} componente(s)?</DialogTitle></DialogHeader>
                            <p className="text-sm text-muted-foreground">
                                Quedarán sueltos (sin activo asignado), con este activo registrado como su origen.
                            </p>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    variant="destructive"
                                    disabled={saving}
                                    onClick={() => runAction(`/api/inv-assets/${asset.id}/disassemble`, { component_ids: selectedComponents }, "Componentes desarmados")}
                                >
                                    Desarmar
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Registrar mantenimiento */}
                    <Dialog open={openDialog === "register-maintenance"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>Registrar mantenimiento</DialogTitle></DialogHeader>
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label>Título *</Label>
                                    <Input
                                        value={maintenanceForm.title}
                                        onChange={(e) => setMaintenanceForm((p) => ({ ...p, title: e.target.value }))}
                                        placeholder="Ej. Cambio de disco duro"
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>Origen</Label>
                                        <Select
                                            value={maintenanceForm.origin_id}
                                            onValueChange={(v) => setMaintenanceForm((p) => ({ ...p, origin_id: v }))}
                                        >
                                            <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>Sin especificar</SelectItem>
                                                {(maintenanceOrigins ?? []).map((o) => (
                                                    <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Modalidad</Label>
                                        <Select
                                            value={maintenanceForm.modality_id}
                                            onValueChange={(v) => setMaintenanceForm((p) => ({ ...p, modality_id: v }))}
                                        >
                                            <SelectTrigger><SelectValue placeholder="Seleccionar…" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>Sin especificar</SelectItem>
                                                {(maintenanceModalities ?? []).map((mo) => (
                                                    <SelectItem key={mo.id} value={String(mo.id)}>{mo.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Fecha de inicio *</Label>
                                    <Input
                                        type="date"
                                        value={maintenanceForm.start_date}
                                        onChange={(e) => setMaintenanceForm((p) => ({ ...p, start_date: e.target.value }))}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Diagnóstico</Label>
                                    <Textarea
                                        rows={2}
                                        value={maintenanceForm.diagnosis}
                                        onChange={(e) => setMaintenanceForm((p) => ({ ...p, diagnosis: e.target.value }))}
                                    />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    disabled={!maintenanceForm.title.trim() || !maintenanceForm.start_date || saving}
                                    onClick={() => runAction(`/api/inv-assets/${asset.id}/maintenances`, {
                                        origin_id: maintenanceForm.origin_id === NONE ? null : maintenanceForm.origin_id,
                                        modality_id: maintenanceForm.modality_id === NONE ? null : maintenanceForm.modality_id,
                                        title: maintenanceForm.title,
                                        diagnosis: maintenanceForm.diagnosis || null,
                                        start_date: maintenanceForm.start_date,
                                    }, "Mantenimiento registrado")}
                                >
                                    Registrar
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Cerrar mantenimiento */}
                    <Dialog open={openDialog === "close-maintenance"} onOpenChange={(o) => !o && close()}>
                        <DialogContent>
                            <DialogHeader><DialogTitle>Cerrar mantenimiento</DialogTitle></DialogHeader>
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label>Fecha de cierre *</Label>
                                    <Input
                                        type="date"
                                        value={closeForm.end_date}
                                        onChange={(e) => setCloseForm((p) => ({ ...p, end_date: e.target.value }))}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Solución</Label>
                                    <Textarea
                                        rows={2}
                                        value={closeForm.solution}
                                        onChange={(e) => setCloseForm((p) => ({ ...p, solution: e.target.value }))}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Costo</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={closeForm.cost}
                                        onChange={(e) => setCloseForm((p) => ({ ...p, cost: e.target.value }))}
                                    />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={close} disabled={saving}>Cancelar</Button>
                                <Button
                                    disabled={!closeForm.end_date || saving}
                                    onClick={() => {
                                        const m = asset.maintenances.find((x) => x.id === closingMaintenanceId);
                                        runPut(`/api/inv-maintenances/${closingMaintenanceId}`, {
                                            title: m.title,
                                            origin_id: m.origin_id,
                                            modality_id: m.modality_id,
                                            diagnosis: m.diagnosis,
                                            start_date: m.start_date,
                                            end_date: closeForm.end_date,
                                            solution: closeForm.solution || null,
                                            cost: closeForm.cost === "" ? null : closeForm.cost,
                                        }, "Mantenimiento cerrado");
                                    }}
                                >
                                    Cerrar mantenimiento
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    <AssetFormDialog
                        open={editOpen}
                        onOpenChange={setEditOpen}
                        asset={asset}
                        categories={categories}
                        statuses={statuses}
                        labels={labels}
                        sites={sites}
                        locations={locations}
                        onSaved={reload}
                    />
                </>
            )}
        </>
    );
}
