import { useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import AssetFormDialog from "./AssetFormDialog";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
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
import { Download, Eye, FileSpreadsheet, History, Pencil, Plus, Search, Trash2, Upload } from "lucide-react";

const ALL = "__all__";

export default function Index() {
    const { assets, categories, statuses, labels, sites, locations, assetQuota = { used: 0, max: null } } = usePage().props;
    const atCapacity = assetQuota.max !== null && assetQuota.used >= assetQuota.max;
    const [search, setSearch] = useState("");
    const [categoryId, setCategoryId] = useState(ALL);
    const [statusId, setStatusId] = useState(ALL);
    const [siteId, setSiteId] = useState(ALL);
    const [assigned, setAssigned] = useState(ALL);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleting, setDeleting] = useState(false);

    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState(null);
    const [importing, setImporting] = useState(false);
    const [importResult, setImportResult] = useState(null);

    const [formOpen, setFormOpen] = useState(false);
    const [formAsset, setFormAsset] = useState(null);

    const openCreate = () => { setFormAsset(null); setFormOpen(true); };
    const openEdit = (asset) => { setFormAsset(asset); setFormOpen(true); };
    const onAssetSaved = () => router.reload({ only: ["assets", "assetQuota"] });

    const applyFilters = (e) => {
        e?.preventDefault();
        router.get(
            "/inventory/assets",
            {
                search: search || undefined,
                category_id: categoryId !== ALL ? categoryId : undefined,
                status_id: statusId !== ALL ? statusId : undefined,
                site_id: siteId !== ALL ? siteId : undefined,
                assigned: assigned !== ALL ? assigned : undefined,
            },
            { preserveState: true, replace: true }
        );
    };

    const exportHref = `/api/inv-assets/export${typeof window !== "undefined" ? window.location.search : ""}`;

    const confirmDelete = async () => {
        if (!deleteTarget) return;
        setDeleting(true);
        try {
            await axios.delete(`/api/inv-assets/${deleteTarget.id}`);
            notify.success("Activo eliminado correctamente");
            setDeleteTarget(null);
            router.reload({ only: ["assets", "assetQuota"] });
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo eliminar el activo"));
            }
        } finally {
            setDeleting(false);
        }
    };

    const closeImport = (open) => {
        if (open) return;
        setImportOpen(false);
        setImportFile(null);
        setImportResult(null);
        if (importResult?.created > 0) router.reload({ only: ["assets", "assetQuota"] });
    };

    const runImport = async () => {
        if (!importFile) return;
        setImporting(true);
        setImportResult(null);
        try {
            const formData = new FormData();
            formData.append("file", importFile);
            const { data } = await axios.post("/api/inv-assets/import", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            setImportResult(data);
            if (data.created > 0) {
                notify.success(`${data.created} activo(s) creado(s)`);
            }
            if ((data.errors ?? []).length === 0) {
                notify.success("Import completado sin errores");
            }
        } catch (err) {
            if (!handleAuthError(err)) {
                notify.error(getApiErrorMessage(err, "No se pudo procesar el archivo"));
            }
        } finally {
            setImporting(false);
        }
    };

    const rows = assets?.data ?? [];

    return (
        <>
            <div className="flex items-center justify-between mb-4">
                <div>
                    <h1 className="text-xl font-semibold">Activos de inventario</h1>
                    <p className="text-sm text-muted-foreground">
                        Registro de equipo (hardware, software, consumibles).
                    </p>
                    {assetQuota.max !== null && (
                        <Badge variant="outline" className="mt-1 text-[10px]">
                            {assetQuota.used} / {assetQuota.max} activos de tu plan
                        </Badge>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    <Button asChild variant="outline">
                        <a href="/api/inv-movements/export">
                            <History className="mr-2 h-4 w-4" />
                            Exportar historial
                        </a>
                    </Button>
                    <Button asChild variant="outline">
                        <a href={exportHref}>
                            <FileSpreadsheet className="mr-2 h-4 w-4" />
                            Exportar
                        </a>
                    </Button>
                    <Button variant="outline" onClick={() => setImportOpen(true)} disabled={atCapacity}>
                        <Upload className="mr-2 h-4 w-4" />
                        {atCapacity ? "Sin cupo en tu plan" : "Importar"}
                    </Button>
                    <Button onClick={openCreate} disabled={atCapacity}>
                        <Plus className="mr-2 h-4 w-4" />
                        {atCapacity ? "Sin cupo en tu plan" : "Nuevo activo"}
                    </Button>
                </div>
            </div>

            <Card className="mb-4">
                <CardContent className="pt-4">
                    <form onSubmit={applyFilters} className="grid gap-3 md:grid-cols-6 items-end">
                        <div className="md:col-span-2 space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Buscar</label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    className="pl-8"
                                    placeholder="Nombre, número de inventario o serie…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Categoría</label>
                            <Select value={categoryId} onValueChange={setCategoryId}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todas</SelectItem>
                                    {(categories ?? []).map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Estatus</label>
                            <Select value={statusId} onValueChange={setStatusId}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todos</SelectItem>
                                    {(statuses ?? []).map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Sede</label>
                            <Select value={siteId} onValueChange={setSiteId}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todas</SelectItem>
                                    {(sites ?? []).map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Responsable</label>
                            <Select value={assigned} onValueChange={setAssigned}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todos</SelectItem>
                                    <SelectItem value="1">Asignados</SelectItem>
                                    <SelectItem value="0">Sin asignar</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="submit" variant="outline" className="md:col-span-6 md:w-auto md:justify-self-start">
                            Aplicar filtros
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nombre</TableHead>
                                <TableHead>Núm. inventario</TableHead>
                                <TableHead>Categoría</TableHead>
                                <TableHead>Estatus</TableHead>
                                <TableHead>Sede</TableHead>
                                <TableHead className="text-right">Acciones</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                                        No hay activos que coincidan con los filtros.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                rows.map((asset) => (
                                    <TableRow key={asset.id}>
                                        <TableCell className="font-medium">
                                            <Link href={`/inventory/assets/${asset.id}`} className="hover:underline">
                                                {asset.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="font-mono text-sm">{asset.internal_tag}</TableCell>
                                        <TableCell>{asset.category?.name ?? "—"}</TableCell>
                                        <TableCell>
                                            {asset.status ? (
                                                <Badge variant="outline">{asset.status.name}</Badge>
                                            ) : "—"}
                                        </TableCell>
                                        <TableCell>{asset.site?.name ?? "—"}</TableCell>
                                        <TableCell className="text-right space-x-1">
                                            <Button asChild variant="ghost" size="icon">
                                                <Link href={`/inventory/assets/${asset.id}`}><Eye className="h-4 w-4" /></Link>
                                            </Button>
                                            <Button variant="ghost" size="icon" onClick={() => openEdit(asset)}>
                                                <Pencil className="h-4 w-4" />
                                            </Button>
                                            <Button variant="ghost" size="icon" onClick={() => setDeleteTarget(asset)}>
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <AlertDialog open={!!deleteTarget} onOpenChange={(open) => !open && setDeleteTarget(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>¿Eliminar activo?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Se eliminará "{deleteTarget?.name}" ({deleteTarget?.internal_tag}). Esta acción no se puede deshacer.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleting}>Cancelar</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete} disabled={deleting}>
                            {deleting ? "Eliminando…" : "Eliminar"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog open={importOpen} onOpenChange={closeImport}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Importar activos desde Excel</DialogTitle></DialogHeader>
                    <div className="space-y-4">
                        <a
                            href="/api/inv-assets/import/template"
                            className="inline-flex items-center text-sm text-primary hover:underline"
                        >
                            <Download className="mr-1.5 h-4 w-4" />
                            Descargar plantilla
                        </a>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Archivo (.xlsx, .xls o .csv)</label>
                            <Input
                                type="file"
                                accept=".xlsx,.xls,.csv"
                                onChange={(e) => { setImportFile(e.target.files?.[0] ?? null); setImportResult(null); }}
                            />
                        </div>

                        {importResult && (
                            <div className="space-y-2">
                                <p className="text-sm">
                                    <strong>{importResult.created}</strong> activo(s) creado(s)
                                    {importResult.errors?.length > 0 && (
                                        <>, <strong>{importResult.errors.length}</strong> fila(s) con error</>
                                    )}.
                                </p>
                                {importResult.errors?.length > 0 && (
                                    <div className="max-h-64 overflow-y-auto border rounded-md">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead className="w-16">Fila</TableHead>
                                                    <TableHead>Errores</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {importResult.errors.map((e, i) => (
                                                    <TableRow key={i}>
                                                        <TableCell>{e.row}</TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {e.messages.join(" ")}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => closeImport(false)} disabled={importing}>
                            Cerrar
                        </Button>
                        <Button onClick={runImport} disabled={!importFile || importing}>
                            {importing ? "Importando…" : "Importar"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AssetFormDialog
                key={formAsset?.id ?? "new"}
                open={formOpen}
                onOpenChange={setFormOpen}
                asset={formAsset}
                categories={categories}
                statuses={statuses}
                labels={labels}
                sites={sites}
                locations={locations}
                onSaved={onAssetSaved}
            />
        </>
    );
}

Index.layout = (page) => <AuthenticatedLayout title="Activos de inventario">{page}</AuthenticatedLayout>;
