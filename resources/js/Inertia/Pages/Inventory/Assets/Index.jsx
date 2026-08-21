import { useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import AssetFormDialog from "./AssetFormDialog";
import AssetDetailDialog from "./AssetDetailDialog";
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
import { ChevronLeft, ChevronRight, Download, Eye, FileSpreadsheet, Filter, History, Pencil, Plus, Search, Trash2, Upload, X } from "lucide-react";

const ALL = "__all__";
const DEFAULT_SORT = "name";

export default function Index() {
    const {
        assets, categories, statuses, labels, sites, locations, manufacturers, clientUsers, maintenanceOrigins, maintenanceModalities,
        assetSpecSchema, assetQuota = { used: 0, max: null }, filters = {}, initialAssetId, auth,
    } = usePage().props;
    // Permisos granulares de Inventario (fase 7.4): manage_assets sigue
    // siendo el nivel completo (incluye eliminar); edit_assets es todo lo
    // operativo SIN eliminar; sin ninguno de los dos, el usuario solo
    // puede ver (la ruta ya lo garantiza vía perm: en web.php, esto solo
    // controla qué botones se muestran).
    const can = (perm) => auth?.user?.permissions?.includes(perm) ?? false;
    const canManage = can("inventory.manage_assets");
    const canEdit = canManage || can("inventory.edit_assets");
    const atCapacity = assetQuota.max !== null && assetQuota.used >= assetQuota.max;
    const [search, setSearch] = useState(filters.search ?? "");
    const [categoryId, setCategoryId] = useState(filters.category_id ? String(filters.category_id) : ALL);
    const [statusId, setStatusId] = useState(filters.status_id ? String(filters.status_id) : ALL);
    const [siteId, setSiteId] = useState(filters.site_id ? String(filters.site_id) : ALL);
    const [assigned, setAssigned] = useState(filters.assigned ?? ALL);
    const [sort, setSort] = useState(filters.sort ?? DEFAULT_SORT);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [navLoading, setNavLoading] = useState(false);

    useEffect(() => {
        const stop1 = router.on("start", () => setNavLoading(true));
        const stop2 = router.on("finish", () => setNavLoading(false));

        return () => { stop1(); stop2(); };
    }, []);

    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState(null);
    const [importing, setImporting] = useState(false);
    const [importResult, setImportResult] = useState(null);

    const [formOpen, setFormOpen] = useState(false);
    const [formAsset, setFormAsset] = useState(null);

    const [viewAssetId, setViewAssetId] = useState(initialAssetId ?? null);
    const [viewOpen, setViewOpen] = useState(!!initialAssetId);

    const openCreate = () => { setFormAsset(null); setFormOpen(true); };
    const openEdit = (asset) => { setFormAsset(asset); setFormOpen(true); };
    const openView = (asset) => { setViewAssetId(asset.id); setViewOpen(true); };
    const onAssetSaved = () => router.reload({ only: ["assets", "assetQuota"] });

    const visit = (overrides = {}) => {
        const merged = {
            search: search || undefined,
            category_id: categoryId !== ALL ? categoryId : undefined,
            status_id: statusId !== ALL ? statusId : undefined,
            site_id: siteId !== ALL ? siteId : undefined,
            assigned: assigned !== ALL ? assigned : undefined,
            sort: sort !== DEFAULT_SORT ? sort : undefined,
            per_page: filters.per_page,
            page: undefined, // cualquier cambio de filtro/orden vuelve a la página 1
            ...overrides,
        };
        router.get("/inventory/assets", merged, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Búsqueda "ágil": aplica sola tras una pausa al teclear, sin botón. Los
    // Select (categoría/estatus/sede/responsable/orden) aplican de inmediato
    // en su propio onValueChange -- ver más abajo.
    const isFirstSearchRender = useRef(true);
    useEffect(() => {
        if (isFirstSearchRender.current) {
            isFirstSearchRender.current = false;
            return;
        }
        const timeout = setTimeout(() => visit({ search: search || undefined }), 400);
        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const activeFilterCount = [
        search.trim().length > 0,
        categoryId !== ALL,
        statusId !== ALL,
        siteId !== ALL,
        assigned !== ALL,
    ].filter(Boolean).length;

    const clearFilters = () => {
        setSearch("");
        setCategoryId(ALL);
        setStatusId(ALL);
        setSiteId(ALL);
        setAssigned(ALL);
        setSort(DEFAULT_SORT);
        visit({
            search: undefined, category_id: undefined, status_id: undefined,
            site_id: undefined, assigned: undefined, sort: undefined,
        });
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
                    {canEdit && (
                        <Button variant="outline" onClick={() => setImportOpen(true)} disabled={atCapacity}>
                            <Upload className="mr-2 h-4 w-4" />
                            {atCapacity ? "Sin cupo en tu plan" : "Importar"}
                        </Button>
                    )}
                    {canEdit && (
                        <Button onClick={openCreate} disabled={atCapacity}>
                            <Plus className="mr-2 h-4 w-4" />
                            {atCapacity ? "Sin cupo en tu plan" : "Nuevo activo"}
                        </Button>
                    )}
                </div>
            </div>

            <Card className="mb-4 border border-border/50 shadow-sm bg-card/60 backdrop-blur-sm">
                <CardContent className="p-4">
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                            <div className="relative flex-1">
                                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                    className="pl-9 bg-background"
                                    placeholder="Nombre, número de inventario o serie…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === "Enter") { e.preventDefault(); visit({ search: search || undefined }); }
                                    }}
                                />
                            </div>
                            <div className="flex items-center gap-2 self-end sm:self-auto">
                                {activeFilterCount > 0 && (
                                    <Button variant="ghost" size="sm" onClick={clearFilters} className="h-9 text-xs text-destructive hover:bg-destructive/10 px-2">
                                        <X className="h-3.5 w-3.5 mr-1" /> Limpiar
                                    </Button>
                                )}
                                <Badge variant={activeFilterCount > 0 ? "secondary" : "outline"} className="h-9 px-3">
                                    <Filter className="w-3 h-3 mr-1" />
                                    {activeFilterCount > 0 ? `${activeFilterCount} filtros` : "Filtros"}
                                </Badge>
                            </div>
                        </div>
                        <div role="separator" className="shrink-0 h-px w-full bg-border/40" />
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                            <Select
                                value={categoryId}
                                onValueChange={(v) => { setCategoryId(v); visit({ category_id: v !== ALL ? v : undefined }); }}
                            >
                                <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Categoría" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todas las categorías</SelectItem>
                                    {(categories ?? []).map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={statusId}
                                onValueChange={(v) => { setStatusId(v); visit({ status_id: v !== ALL ? v : undefined }); }}
                            >
                                <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Estatus" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todos los estatus</SelectItem>
                                    {(statuses ?? []).map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={siteId}
                                onValueChange={(v) => { setSiteId(v); visit({ site_id: v !== ALL ? v : undefined }); }}
                            >
                                <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Sede" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todas las sedes</SelectItem>
                                    {(sites ?? []).map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={assigned}
                                onValueChange={(v) => { setAssigned(v); visit({ assigned: v !== ALL ? v : undefined }); }}
                            >
                                <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Responsable" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Todos</SelectItem>
                                    <SelectItem value="1">Asignados</SelectItem>
                                    <SelectItem value="0">Sin asignar</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={sort}
                                onValueChange={(v) => { setSort(v); visit({ sort: v !== DEFAULT_SORT ? v : undefined }); }}
                            >
                                <SelectTrigger className="h-8 text-xs bg-background"><SelectValue placeholder="Orden" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={DEFAULT_SORT}>Nombre</SelectItem>
                                    <SelectItem value="peps">PEPS (más antiguos primero)</SelectItem>
                                    <SelectItem value="ueps">UEPS (más recientes primero)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
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
                                            <button type="button" onClick={() => openView(asset)} className="hover:underline text-left">
                                                {asset.name}
                                            </button>
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
                                            <Button variant="ghost" size="icon" onClick={() => openView(asset)}>
                                                <Eye className="h-4 w-4" />
                                            </Button>
                                            {canEdit && (
                                                <Button variant="ghost" size="icon" onClick={() => openEdit(asset)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {canManage && (
                                                <Button variant="ghost" size="icon" onClick={() => setDeleteTarget(asset)}>
                                                    <Trash2 className="h-4 w-4 text-destructive" />
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

            <div className="border border-border/50 rounded-lg px-4 py-3 bg-muted/20 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p className="text-xs text-muted-foreground">
                    {(assets?.total ?? 0) === 0 ? "Sin resultados" : (
                        <>Mostrando <span className="font-medium text-foreground">{assets.from}–{assets.to}</span> de <span className="font-medium text-foreground">{assets.total}</span> activos</>
                    )}
                </p>
                <div className="flex items-center gap-2">
                    <Select
                        value={String(filters.per_page ?? assets?.per_page ?? 25)}
                        onValueChange={(v) => visit({ per_page: v, page: 1 })}
                    >
                        <SelectTrigger className="w-16 h-8 text-xs bg-background"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {[10, 25, 50, 100].map((n) => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <div className="flex items-center border rounded-md bg-background shadow-sm">
                        <Button
                            variant="ghost" size="icon"
                            className="h-8 w-8 rounded-r-none border-r min-h-[44px] min-w-[44px] md:min-h-0 md:min-w-0"
                            onClick={() => visit({ page: Math.max(1, (assets?.current_page ?? 1) - 1) })}
                            disabled={(assets?.current_page ?? 1) <= 1 || navLoading}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <span className="text-xs font-medium w-24 text-center">{assets?.current_page ?? 1} / {assets?.last_page ?? 1}</span>
                        <Button
                            variant="ghost" size="icon"
                            className="h-8 w-8 rounded-l-none border-l min-h-[44px] min-w-[44px] md:min-h-0 md:min-w-0"
                            onClick={() => visit({ page: Math.min(assets?.last_page ?? 1, (assets?.current_page ?? 1) + 1) })}
                            disabled={(assets?.current_page ?? 1) >= (assets?.last_page ?? 1) || navLoading}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>

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
                manufacturers={manufacturers}
                statuses={statuses}
                labels={labels}
                sites={sites}
                locations={locations}
                specSchema={assetSpecSchema}
                onSaved={onAssetSaved}
            />

            <AssetDetailDialog
                open={viewOpen}
                onOpenChange={setViewOpen}
                assetId={viewAssetId}
                categories={categories}
                manufacturers={manufacturers}
                statuses={statuses}
                labels={labels}
                sites={sites}
                locations={locations}
                specSchema={assetSpecSchema}
                clientUsers={clientUsers}
                maintenanceOrigins={maintenanceOrigins}
                maintenanceModalities={maintenanceModalities}
                canEdit={canEdit}
                onChanged={() => router.reload({ only: ["assets", "assetQuota"] })}
            />
        </>
    );
}

Index.layout = (page) => <AuthenticatedLayout title="Activos de inventario">{page}</AuthenticatedLayout>;
