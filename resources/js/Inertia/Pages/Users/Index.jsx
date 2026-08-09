import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { DataTable } from "@/components/ui/data-table";
import { TablePagination } from "@/components/ui/table-pagination";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Badge } from "@/components/ui/badge";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import UserPermissionOverrides from "@/Inertia/components/Roles/UserPermissionOverrides";
import { notify } from "@/lib/notify";
import { getApiErrorMessage } from "@/lib/apiErrors";
import { strongPasswordSchema } from "@/lib/passwordSchema";
import { tableActionIcon, userStatusClass } from "@/lib/badgeStyles";
import { cn } from "@/lib/utils";
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Eye,
    Filter,
    Loader2,
    Mail,
    RotateCcw,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Trash2,
    UserPlus,
} from "lucide-react";

const EMPTY_FORM = {
    employee_number: "",
    first_name: "",
    paternal_last_name: "",
    maternal_last_name: "",
    email: "",
    phone: "",
    password: "",
    password_confirmation: "",
    role_id: "",
    campaign: "",
    area: "",
    position: "",
    site: "",
    location: "",
};

function initials(name) {
    const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "??";
    return (parts[0][0] + (parts[1]?.[0] || "")).toUpperCase();
}

function StatusBadge({ status, isBlacklisted }) {
    if (isBlacklisted) {
        return (
            <Badge variant="destructive" className="gap-1 text-[10px] uppercase font-bold tracking-wider">
                VETADO
            </Badge>
        );
    }
    const labels = {
        active: "Activo",
        pending_admin: "Pendiente Aprobación",
        pending_email: "Verificando Email",
        blocked: "Bloqueado",
    };
    return (
        <Badge
            variant="outline"
            className={cn(
                "uppercase text-[10px] font-bold tracking-wider",
                userStatusClass(status)
            )}
        >
            {labels[status] || status}
        </Badge>
    );
}

function UserCard({ user, renderRowActions }) {
    return (
        <Card className="border border-border/60 overflow-hidden shadow-sm">
            <CardContent className="p-4 flex flex-col gap-3">
                <div className="flex items-start justify-between gap-2 min-w-0">
                    <div className="min-w-0 flex-1">
                        <p className="font-semibold text-sm text-foreground truncate">{user.name}</p>
                        <p
                            className="text-[11px] text-muted-foreground font-mono truncate"
                            title={user.email}
                        >
                            #{user.employee_number}
                            {user.email ? ` · ${user.email}` : ""}
                        </p>
                    </div>
                    <StatusBadge status={user.status} isBlacklisted={user.is_blacklisted} />
                </div>
                {(user.campaign || user.site) && (
                    <p className="text-xs text-muted-foreground truncate">
                        {[user.campaign, user.site, user.location].filter(Boolean).join(" · ")}
                    </p>
                )}
                {user.position && (
                    <p className="text-[11px] text-muted-foreground truncate">{user.position}</p>
                )}
                <div className="flex justify-end pt-1 border-t border-border/40">
                    {renderRowActions(user)}
                </div>
            </CardContent>
        </Card>
    );
}

function resolveRoleId(user, roles) {
    const role = user?.roles?.[0];
    if (!role) return "";
    const byId = roles.find((r) => String(r.id) === String(role.id));
    if (byId) return String(byId.id);
    const byName = roles.find((r) => r.name === role.name);
    return byName ? String(byName.id) : "";
}

function userToForm(user, catalogs) {
    if (!user) return { ...EMPTY_FORM };
    return {
        employee_number: user.employee_number ?? "",
        first_name: user.first_name ?? "",
        paternal_last_name: user.paternal_last_name ?? "",
        maternal_last_name: user.maternal_last_name ?? "",
        email: user.email ?? "",
        phone: user.phone ?? "",
        password: "",
        password_confirmation: "",
        role_id: resolveRoleId(user, catalogs.roles ?? []),
        campaign: user.campaign && user.campaign !== "Sin Asignar" ? user.campaign : "",
        area: user.area && user.area !== "Sin Asignar" ? user.area : "",
        position: user.position && user.position !== "Sin Asignar" ? user.position : "",
        site: user.site && user.site !== "Sin Asignar" ? user.site : "",
        location: user.location ?? "",
    };
}

function validateForm(form, isEdit) {
    const errors = {};
    if (!form.first_name?.trim()) errors.first_name = "Requerido";
    if (!form.paternal_last_name?.trim()) errors.paternal_last_name = "Requerido";
    if (form.email?.trim()) {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
            errors.email = "Correo inválido";
        }
    }
    if (form.phone?.trim() && !/^\d{10}$/.test(form.phone.trim())) {
        errors.phone = "Debe tener exactamente 10 dígitos";
    }
    if (!form.role_id) errors.role_id = "Selecciona un rol";
    if (!form.campaign) errors.campaign = "Selecciona una campaña";
    if (!form.area) errors.area = "Selecciona un área";
    if (!form.position) errors.position = "Selecciona un puesto";
    if (!isEdit) {
        const pass = strongPasswordSchema.safeParse(form.password);
        if (!pass.success) errors.password = pass.error.errors[0]?.message ?? "Contraseña inválida";
        if (form.password !== form.password_confirmation) {
            errors.password_confirmation = "Las contraseñas no coinciden";
        }
    } else if (form.password) {
        const pass = strongPasswordSchema.safeParse(form.password);
        if (!pass.success) errors.password = pass.error.errors[0]?.message ?? "Contraseña inválida";
        if (form.password !== form.password_confirmation) {
            errors.password_confirmation = "Las contraseñas no coinciden";
        }
    }
    return errors;
}

export default function Index({ users, catalogs, filters: serverFilters = {} }) {
    const { auth } = usePage().props;
    const isSuperAdmin = (auth?.user?.roles ?? []).includes("super_admin");
    const showTrashed = serverFilters.status === "only";
    const rows = users?.data ?? [];

    const [navLoading, setNavLoading] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [filtersOpen, setFiltersOpen] = useState(false);

    const [draft, setDraft] = useState({
        search: serverFilters.search ?? "",
        user_status: serverFilters.user_status ?? "all",
        blacklist: serverFilters.blacklist ?? "all",
        role_id: serverFilters.role_id ?? "all",
        campaign: serverFilters.campaign ?? "all",
        area: serverFilters.area ?? "all",
        site: serverFilters.site ?? "all",
    });

    const [dialogOpen, setDialogOpen] = useState(false);
    const [editUser, setEditUser] = useState(null);
    const [approveMode, setApproveMode] = useState(false);
    const [form, setForm] = useState(EMPTY_FORM);
    const [formErrors, setFormErrors] = useState({});
    const [formLoading, setFormLoading] = useState(false);

    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteIds, setDeleteIds] = useState([]);
    const [deleteReason, setDeleteReason] = useState("");
    const [deleteLoading, setDeleteLoading] = useState(false);

    const [viewOpen, setViewOpen] = useState(false);
    const [viewUser, setViewUser] = useState(null);

    const searchDebounceRef = useRef(null);

    useEffect(() => {
        setDraft({
            search: serverFilters.search ?? "",
            user_status: serverFilters.user_status ?? "all",
            blacklist: serverFilters.blacklist ?? "all",
            role_id: serverFilters.role_id ?? "all",
            campaign: serverFilters.campaign ?? "all",
            area: serverFilters.area ?? "all",
            site: serverFilters.site ?? "all",
        });
    }, [serverFilters]);

    useEffect(() => {
        const unsubStart = router.on("start", () => setNavLoading(true));
        const unsubFinish = router.on("finish", () => setNavLoading(false));
        return () => {
            unsubStart();
            unsubFinish();
        };
    }, []);

    useEffect(() => {
        setSelectedIds((prev) => prev.filter((id) => rows.some((r) => r.id === id)));
    }, [rows]);

    const buildQuery = useCallback(
        (overrides = {}) => {
            const merged = { ...serverFilters, ...overrides };
            const q = {};
            if (merged.search?.trim()) q.search = merged.search.trim();
            if (merged.user_status && merged.user_status !== "all") q.user_status = merged.user_status;
            if (merged.blacklist && merged.blacklist !== "all") q.blacklist = merged.blacklist;
            if (merged.role_id && merged.role_id !== "all") q.role_id = merged.role_id;
            if (merged.campaign && merged.campaign !== "all") q.campaign = merged.campaign;
            if (merged.area && merged.area !== "all") q.area = merged.area;
            if (merged.site && merged.site !== "all") q.site = merged.site;
            if (merged.status === "only") q.status = "only";
            if (merged.per_page) q.per_page = merged.per_page;
            if (merged.page) q.page = merged.page;
            return q;
        },
        [serverFilters]
    );

    const visit = (overrides = {}) => {
        router.get("/users", buildQuery(overrides), {
            preserveState: true,
            preserveScroll: true,
            only: ["users", "filters"],
        });
    };

    const activeFilterCount = useMemo(() => {
        let n = 0;
        if (serverFilters.campaign && serverFilters.campaign !== "all") n++;
        if (serverFilters.area && serverFilters.area !== "all") n++;
        if (serverFilters.role_id && serverFilters.role_id !== "all") n++;
        if (serverFilters.user_status && serverFilters.user_status !== "all") n++;
        if (serverFilters.site && serverFilters.site !== "all") n++;
        if (serverFilters.blacklist && serverFilters.blacklist !== "all") n++;
        return n;
    }, [serverFilters]);

    const onFilterChange = (patch) => {
        const next = { ...draft, ...patch };
        setDraft(next);
        visit({ ...next, page: 1, status: showTrashed ? "only" : undefined });
    };

    const onSearchChange = (value) => {
        setDraft((d) => ({ ...d, search: value }));
        clearTimeout(searchDebounceRef.current);
        searchDebounceRef.current = setTimeout(() => {
            visit({ search: value, page: 1, status: showTrashed ? "only" : undefined });
        }, 400);
    };

    const clearFilters = () => {
        const cleared = {
            search: "",
            user_status: "all",
            blacklist: "all",
            role_id: "all",
            campaign: "all",
            area: "all",
            site: "all",
        };
        setDraft(cleared);
        visit({ ...cleared, page: 1, status: showTrashed ? "only" : undefined });
    };

    const toggleTrashed = () => {
        visit({
            page: 1,
            status: showTrashed ? undefined : "only",
        });
    };

    const openCreate = () => {
        setEditUser(null);
        setApproveMode(false);
        setForm(EMPTY_FORM);
        setFormErrors({});
        setDialogOpen(true);
    };

    const openEdit = (user, approve = false) => {
        setEditUser(user);
        setApproveMode(approve);
        setForm(userToForm(user, catalogs));
        setFormErrors({});
        setDialogOpen(true);
    };

    // Prellenado desde la cola de revisión de correos no reconocidos
    // (Resolbeb/PendingRequests.jsx, botón "Crear usuario nuevo con este
    // correo"): ?create=1&first_name=...&email=... abre el mismo diálogo de
    // "Nuevo usuario" ya con esos dos campos listos -- el resto (rol, sede,
    // contraseña) lo completa quien revisa, misma validación de siempre.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get("create") !== "1") return;

        openCreate();
        setForm((f) => ({
            ...f,
            first_name: params.get("first_name") ?? f.first_name,
            email: params.get("email") ?? f.email,
        }));

        const url = new URL(window.location.href);
        url.search = "";
        window.history.replaceState({}, "", url);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const reloadUsers = () =>
        router.reload({ only: ["users"], preserveScroll: true });

    const submitForm = async () => {
        const isEdit = !!editUser;
        const errors = validateForm(form, isEdit);
        setFormErrors(errors);
        if (Object.keys(errors).length) return;

        setFormLoading(true);
        const payload = {
            first_name: form.first_name.trim(),
            paternal_last_name: form.paternal_last_name.trim(),
            maternal_last_name: form.maternal_last_name?.trim() || "",
            employee_number: form.employee_number?.trim() || null,
            email: form.email?.trim() || null,
            phone: form.phone?.trim() || null,
            role_id: form.role_id,
            campaign: form.campaign,
            area: form.area,
            position: form.position,
            site: form.site || null,
            location: form.location || null,
        };
        if (form.password) payload.password = form.password;
        if (!isEdit) payload.password = form.password;
        if (approveMode) payload.status = "active";

        try {
            if (isEdit) {
                await axios.put(`/api/users/${editUser.id}`, payload);
                notify.success(approveMode ? "Usuario aprobado" : "Usuario actualizado");
            } else {
                await axios.post("/api/users", payload);
                notify.success("Usuario creado");
            }
            setDialogOpen(false);
            reloadUsers();
        } catch (err) {
            const apiErrors = err.response?.data?.errors;
            if (apiErrors) {
                const mapped = {};
                Object.entries(apiErrors).forEach(([k, v]) => {
                    mapped[k] = Array.isArray(v) ? v[0] : v;
                });
                setFormErrors(mapped);
            }
            notify.error(getApiErrorMessage(err, "Error al guardar usuario"));
        } finally {
            setFormLoading(false);
        }
    };

    const toggleBlacklist = async (user, blacklist) => {
        try {
            await axios.patch(`/api/users/${user.id}/blacklist`, { blacklist });
            notify.success(blacklist ? "Usuario vetado" : "Veto removido");
            reloadUsers();
        } catch (err) {
            notify.error(getApiErrorMessage(err, "Error al actualizar lista negra"));
        }
    };

    const restoreUser = async (id) => {
        try {
            await axios.post(`/api/users/${id}/restore`);
            notify.success("Usuario restaurado");
            reloadUsers();
        } catch (err) {
            notify.error(getApiErrorMessage(err, "Error al restaurar"));
        }
    };

    const forceDeleteUser = async (id) => {
        if (!confirm("¿Eliminar permanentemente este usuario?")) return;
        try {
            await axios.delete(`/api/users/${id}/force`);
            notify.success("Usuario eliminado permanentemente");
            reloadUsers();
        } catch (err) {
            notify.error(getApiErrorMessage(err, "Error al eliminar"));
        }
    };

    const openDelete = (ids) => {
        setDeleteIds(Array.isArray(ids) ? ids : [ids]);
        setDeleteReason("");
        setDeleteOpen(true);
    };

    const confirmDelete = async () => {
        if (deleteReason.trim().length < 5) return;
        setDeleteLoading(true);
        try {
            if (deleteIds.length === 1) {
                await axios.delete(`/api/users/${deleteIds[0]}`, {
                    data: { reason: deleteReason.trim() },
                });
            } else {
                await axios.post("/api/users/mass-delete", {
                    ids: deleteIds,
                    reason: deleteReason.trim(),
                });
            }
            notify.success("Baja técnica realizada");
            setDeleteOpen(false);
            setSelectedIds([]);
            reloadUsers();
        } catch (err) {
            notify.error(getApiErrorMessage(err, "Error al eliminar usuarios"));
        } finally {
            setDeleteLoading(false);
        }
    };

    const filteredLocations = useMemo(() => {
        const list = catalogs.locations ?? [];
        if (!form.site) return list;
        return list.filter((u) => u.site_name === form.site);
    }, [catalogs.locations, form.site]);

    const renderRowActions = useCallback(
        (u) => (
            <div className="flex justify-end items-center gap-1">
                {showTrashed ? (
                    <>
                        <Button
                            size="icon"
                            variant="ghost"
                            className={cn(tableActionIcon.base, tableActionIcon.restore)}
                            onClick={() => restoreUser(u.id)}
                            title="Restaurar"
                        >
                            <RotateCcw className="h-4 w-4" />
                        </Button>
                        {isSuperAdmin && (
                            <Button
                                size="icon"
                                variant="ghost"
                                className="h-8 w-8 text-destructive hover:bg-destructive/10"
                                onClick={() => forceDeleteUser(u.id)}
                                title="Eliminar permanente"
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                ) : (
                    <>
                        <Button
                            size="icon"
                            variant="ghost"
                            className={cn(tableActionIcon.base, tableActionIcon.view)}
                            onClick={() => {
                                setViewUser(u);
                                setViewOpen(true);
                            }}
                            title="Ver"
                        >
                            <Eye className="h-4 w-4" />
                        </Button>
                        {u.status === "pending_admin" && (
                            <Button
                                size="icon"
                                variant="ghost"
                                className={cn(tableActionIcon.base, tableActionIcon.approve)}
                                onClick={() => openEdit(u, true)}
                                title="Aprobar"
                            >
                                <CheckCircle2 className="h-4 w-4" />
                            </Button>
                        )}
                        <Button
                            size="icon"
                            variant="ghost"
                            className={cn(tableActionIcon.base, tableActionIcon.edit)}
                            onClick={() => openEdit(u)}
                            title="Editar"
                        >
                            <SlidersHorizontal className="h-4 w-4" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className={cn(
                                tableActionIcon.base,
                                u.is_blacklisted
                                    ? tableActionIcon.blacklistOn
                                    : tableActionIcon.blacklistOff
                            )}
                            onClick={() => toggleBlacklist(u, !u.is_blacklisted)}
                            title={u.is_blacklisted ? "Quitar veto" : "Vetar"}
                        >
                            {u.is_blacklisted ? (
                                <ShieldCheck className="h-4 w-4" />
                            ) : (
                                <Ban className="h-4 w-4" />
                            )}
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className={cn(tableActionIcon.base, tableActionIcon.delete)}
                            onClick={() => openDelete(u.id)}
                            title="Eliminar"
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    </>
                )}
            </div>
        ),
        [showTrashed, isSuperAdmin]
    );

    const columns = useMemo(
        () => [
            {
                id: "select",
                header: () => (
                    <Checkbox
                        checked={rows.length > 0 && selectedIds.length === rows.length}
                        onCheckedChange={(c) =>
                            setSelectedIds(c ? rows.map((u) => u.id) : [])
                        }
                    />
                ),
                cell: ({ row }) => (
                    <Checkbox
                        checked={selectedIds.includes(row.original.id)}
                        onCheckedChange={() =>
                            setSelectedIds((prev) =>
                                prev.includes(row.original.id)
                                    ? prev.filter((i) => i !== row.original.id)
                                    : [...prev, row.original.id]
                            )
                        }
                    />
                ),
                meta: { headerClassName: "w-10", className: "w-10" },
            },
            {
                id: "user",
                header: "Usuario",
                cell: ({ row }) => {
                    const u = row.original;
                    return (
                        <div className="flex items-center gap-3 min-w-0">
                            <Avatar className="h-9 w-9 shrink-0">
                                {u.avatar_url && (
                                    <AvatarImage src={u.avatar_url} alt={u.name} className="object-cover" />
                                )}
                                <AvatarFallback className="text-xs">{initials(u.name)}</AvatarFallback>
                            </Avatar>
                            <div className="min-w-0">
                                <p className="font-medium text-sm truncate">{u.name}</p>
                                <p className="text-xs text-muted-foreground truncate">{u.email || "—"}</p>
                            </div>
                        </div>
                    );
                },
            },
            {
                id: "employee",
                header: "#Empleado",
                cell: ({ row }) => (
                    <span className="text-xs font-mono text-muted-foreground">
                        {row.original.employee_number || "—"}
                    </span>
                ),
                meta: { headerClassName: "hidden lg:table-cell", className: "hidden lg:table-cell" },
            },
            {
                id: "roles",
                header: "Rol",
                cell: ({ row }) => (
                    <div className="flex flex-wrap gap-1">
                        {(row.original.roles ?? []).map((r) => (
                            <Badge key={r.id} variant="secondary" className="text-[10px]">
                                {r.name}
                            </Badge>
                        ))}
                    </div>
                ),
            },
            {
                id: "area",
                header: "Área",
                cell: ({ row }) => (
                    <span className="text-xs truncate max-w-[120px] block">{row.original.area}</span>
                ),
                meta: { headerClassName: "hidden md:table-cell", className: "hidden md:table-cell" },
            },
            {
                id: "status",
                header: "Estado",
                cell: ({ row }) => (
                    <StatusBadge
                        status={row.original.status}
                        isBlacklisted={row.original.is_blacklisted}
                    />
                ),
            },
            {
                id: "blacklist",
                header: "Vetado",
                cell: ({ row }) =>
                    row.original.is_blacklisted ? (
                        <Badge variant="destructive" className="text-[10px]">
                            Sí
                        </Badge>
                    ) : (
                        <span className="text-xs text-muted-foreground">—</span>
                    ),
                meta: { headerClassName: "hidden sm:table-cell", className: "hidden sm:table-cell" },
            },
            {
                id: "actions",
                header: "",
                cell: ({ row }) => renderRowActions(row.original),
                meta: { headerClassName: "w-[100px] text-right", className: "text-right py-1.5 px-2" },
            },
        ],
        [rows, selectedIds, catalogs, renderRowActions]
    );

    const perPage = String(serverFilters.per_page ?? users?.per_page ?? 15);

    return (
        <AuthenticatedLayout>
            <Head title={showTrashed ? "Papelera de usuarios" : "Usuarios"} />

            <div className="space-y-4 pb-12 animate-in fade-in duration-500">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center justify-between">
                    <div>
                        {showTrashed && (
                            <div className="flex items-center gap-2">
                                <Trash2 className="h-5 w-5 text-destructive" />
                                <h1 className="text-xl font-bold tracking-tight">Papelera de usuarios</h1>
                            </div>
                        )}
                        <p className="text-xs text-muted-foreground mt-0.5">
                            {showTrashed
                                ? "Usuarios eliminados"
                                : "Administración de usuarios y accesos"}
                        </p>
                    </div>
                    {!showTrashed && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" className="font-medium" asChild>
                                <Link href="/users/invitations">
                                    <Mail className="h-4 w-4 mr-2" /> Invitaciones
                                </Link>
                            </Button>
                            <Button size="sm" className="font-medium" onClick={openCreate}>
                                <UserPlus className="h-4 w-4 mr-2" /> Nuevo
                            </Button>
                        </div>
                    )}
                </div>

                <Card className="border-border/60 overflow-hidden">
                    <div className="p-3 flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between">
                        <div className="flex flex-1 w-full gap-2 flex-wrap">
                            <div className="relative flex-1 min-w-[160px] max-w-xs">
                                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                                <Input
                                    className="pl-8 h-8 text-sm bg-background"
                                    placeholder="Buscar..."
                                    value={draft.search}
                                    onChange={(e) => onSearchChange(e.target.value)}
                                />
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setFiltersOpen((o) => !o)}
                                className={`h-8 gap-1.5 text-sm ${filtersOpen ? "bg-muted border-primary/30" : ""} ${activeFilterCount > 0 ? "border-primary/50 text-primary" : ""}`}
                            >
                                <Filter className="h-3.5 w-3.5 shrink-0" />
                                Filtros
                                {activeFilterCount > 0 && (
                                    <span className="flex h-4 min-w-[18px] items-center justify-center rounded-full bg-primary/15 px-1 text-[10px] font-bold text-primary">
                                        {activeFilterCount}
                                    </span>
                                )}
                            </Button>
                            <Button
                                variant={showTrashed ? "destructive" : "outline"}
                                size="sm"
                                onClick={toggleTrashed}
                                className="h-8 gap-1.5 text-sm"
                            >
                                {showTrashed ? (
                                    <>
                                        <RotateCcw className="h-3.5 w-3.5" /> Activos
                                    </>
                                ) : (
                                    <>
                                        <Trash2 className="h-3.5 w-3.5" /> Papelera
                                    </>
                                )}
                            </Button>
                        </div>
                        {selectedIds.length > 0 && !showTrashed && (
                            <div className="flex items-center gap-2 animate-in slide-in-from-right-4 fade-in">
                                <span className="text-xs font-bold text-muted-foreground bg-muted px-2 py-1 rounded-md">
                                    {selectedIds.length} sel.
                                </span>
                                <Separator orientation="vertical" className="h-6" />
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => openDelete(selectedIds)}
                                    className="h-9"
                                >
                                    <Trash2 className="h-3.5 w-3.5 mr-2" /> Eliminar
                                </Button>
                            </div>
                        )}
                    </div>

                    {filtersOpen && (
                        <div className="px-4 pb-4 pt-0 border-t border-border/60">
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-4">
                                <div className="space-y-1">
                                    <Label className="text-xs">Estado</Label>
                                    <Select
                                        value={draft.user_status}
                                        onValueChange={(v) => onFilterChange({ user_status: v })}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Todos</SelectItem>
                                            <SelectItem value="active">Activo</SelectItem>
                                            <SelectItem value="pending_admin">Pendiente aprobación</SelectItem>
                                            <SelectItem value="pending_email">Verificando email</SelectItem>
                                            <SelectItem value="blocked">Bloqueado</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Lista negra</Label>
                                    <Select
                                        value={draft.blacklist}
                                        onValueChange={(v) => onFilterChange({ blacklist: v })}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Todos</SelectItem>
                                            <SelectItem value="yes">Vetados</SelectItem>
                                            <SelectItem value="no">No vetados</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Rol</Label>
                                    <Select
                                        value={draft.role_id}
                                        onValueChange={(v) => onFilterChange({ role_id: v })}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue placeholder="Todos" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Todos</SelectItem>
                                            {(catalogs.roles ?? []).map((r) => (
                                                <SelectItem key={r.id} value={String(r.id)}>
                                                    {r.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Campaña</Label>
                                    <Select
                                        value={draft.campaign}
                                        onValueChange={(v) => onFilterChange({ campaign: v })}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue placeholder="Todas" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Todas</SelectItem>
                                            {(catalogs.campaigns ?? []).map((c) => (
                                                <SelectItem key={c.id} value={c.name}>
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Área</Label>
                                    <Select
                                        value={draft.area}
                                        onValueChange={(v) => onFilterChange({ area: v })}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue placeholder="Todas" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Todas</SelectItem>
                                            {(catalogs.areas ?? []).map((a) => (
                                                <SelectItem key={a.id} value={a.name}>
                                                    {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Sede</Label>
                                    <Select
                                        value={draft.site}
                                        onValueChange={(v) => onFilterChange({ site: v })}
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue placeholder="Todas" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Todas</SelectItem>
                                            {(catalogs.sites ?? []).map((s) => (
                                                <SelectItem key={s.id} value={s.name}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            {activeFilterCount > 0 && (
                                <div className="flex justify-end pt-4">
                                    <Button variant="ghost" size="sm" onClick={clearFilters}>
                                        Limpiar filtros
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}
                </Card>

                <div className="block md:hidden space-y-3">
                    {navLoading ? (
                        Array.from({ length: 4 }).map((_, i) => (
                            <Card key={i} className="border border-border/60 overflow-hidden">
                                <CardContent className="p-4 space-y-3">
                                    <Skeleton className="h-4 w-32" />
                                    <Skeleton className="h-3 w-24" />
                                    <Skeleton className="h-8 w-full" />
                                </CardContent>
                            </Card>
                        ))
                    ) : rows.length === 0 ? (
                        <Card className="border border-dashed border-border/60">
                            <CardContent className="py-12 px-4 text-center">
                                <p className="text-sm font-medium text-muted-foreground">
                                    No se encontraron resultados
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        rows.map((u) => (
                            <UserCard key={u.id} user={u} renderRowActions={renderRowActions} />
                        ))
                    )}
                </div>

                <Card className="overflow-hidden border-border/60 hidden md:block">
                    <div className="overflow-x-auto">
                        <div className="min-w-[700px] [&_th]:py-1.5 [&_td]:py-1.5 [&_th]:text-xs [&_td]:text-sm">
                            <DataTable
                                columns={columns}
                                data={rows}
                                loading={navLoading}
                                getRowId={(row) => row.id}
                                selectedIds={selectedIds}
                                emptyColSpan={8}
                            />
                        </div>
                    </div>
                </Card>

                <TablePagination
                    total={users?.total ?? 0}
                    from={users?.from ?? 0}
                    to={users?.to ?? 0}
                    currentPage={users?.current_page ?? 1}
                    lastPage={users?.last_page ?? 1}
                    perPage={perPage}
                    perPageOptions={["10", "15", "25", "50", "100"]}
                    onPerPageChange={(v) => visit({ per_page: v, page: 1 })}
                    onPageChange={(p) => visit({ page: p })}
                    loading={navLoading}
                />
            </div>

            <Dialog open={dialogOpen} onOpenChange={(o) => !formLoading && setDialogOpen(o)}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {editUser
                                ? approveMode
                                    ? "Aprobar usuario"
                                    : "Editar usuario"
                                : "Nuevo usuario"}
                        </DialogTitle>
                        <DialogDescription>
                            {approveMode
                                ? "Asigna un rol y activa la cuenta."
                                : "Completa la información personal y de acceso."}
                        </DialogDescription>
                    </DialogHeader>

                    <Tabs defaultValue="personal" className="w-full">
                        <TabsList className={cn("grid w-full", editUser ? "grid-cols-3" : "grid-cols-2")}>
                            <TabsTrigger value="personal">Información personal</TabsTrigger>
                            <TabsTrigger value="access">Acceso y organización</TabsTrigger>
                            {editUser && <TabsTrigger value="permissions">Permisos</TabsTrigger>}
                        </TabsList>
                        <TabsContent value="personal" className="space-y-4 pt-4">
                            <div className="grid sm:grid-cols-2 gap-3">
                                <div className="space-y-1 sm:col-span-2">
                                    <Label>Número de empleado (opcional)</Label>
                                    <Input
                                        value={form.employee_number}
                                        onChange={(e) =>
                                            setForm((f) => ({
                                                ...f,
                                                employee_number: e.target.value,
                                            }))
                                        }
                                        placeholder="# Empleado (opcional)"
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>Nombre(s) *</Label>
                                    <Input
                                        value={form.first_name}
                                        onChange={(e) =>
                                            setForm((f) => ({ ...f, first_name: e.target.value }))
                                        }
                                    />
                                    {formErrors.first_name && (
                                        <p className="text-xs text-destructive">{formErrors.first_name}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Apellido paterno *</Label>
                                    <Input
                                        value={form.paternal_last_name}
                                        onChange={(e) =>
                                            setForm((f) => ({
                                                ...f,
                                                paternal_last_name: e.target.value,
                                            }))
                                        }
                                    />
                                    {formErrors.paternal_last_name && (
                                        <p className="text-xs text-destructive">
                                            {formErrors.paternal_last_name}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Apellido materno</Label>
                                    <Input
                                        value={form.maternal_last_name}
                                        onChange={(e) =>
                                            setForm((f) => ({
                                                ...f,
                                                maternal_last_name: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>Email</Label>
                                    <Input
                                        type="email"
                                        value={form.email}
                                        onChange={(e) =>
                                            setForm((f) => ({ ...f, email: e.target.value }))
                                        }
                                    />
                                    {formErrors.email && (
                                        <p className="text-xs text-destructive">{formErrors.email}</p>
                                    )}
                                </div>
                                <div className="space-y-1 sm:col-span-2">
                                    <Label>Teléfono (10 dígitos)</Label>
                                    <Input
                                        value={form.phone}
                                        maxLength={10}
                                        onChange={(e) =>
                                            setForm((f) => ({ ...f, phone: e.target.value }))
                                        }
                                    />
                                    {formErrors.phone && (
                                        <p className="text-xs text-destructive">{formErrors.phone}</p>
                                    )}
                                </div>
                            </div>
                        </TabsContent>
                        <TabsContent value="access" className="space-y-4 pt-4">
                            <div className="grid sm:grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <Label>{editUser ? "Nueva contraseña" : "Contraseña *"}</Label>
                                    <Input
                                        type="password"
                                        value={form.password}
                                        onChange={(e) =>
                                            setForm((f) => ({ ...f, password: e.target.value }))
                                        }
                                        placeholder={editUser ? "Opcional" : "Mín. 12 caracteres"}
                                    />
                                    {formErrors.password && (
                                        <p className="text-xs text-destructive">{formErrors.password}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Confirmar contraseña</Label>
                                    <Input
                                        type="password"
                                        value={form.password_confirmation}
                                        onChange={(e) =>
                                            setForm((f) => ({
                                                ...f,
                                                password_confirmation: e.target.value,
                                            }))
                                        }
                                    />
                                    {formErrors.password_confirmation && (
                                        <p className="text-xs text-destructive">
                                            {formErrors.password_confirmation}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Rol *</Label>
                                    <Select
                                        value={form.role_id}
                                        onValueChange={(v) =>
                                            setForm((f) => ({ ...f, role_id: v }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Seleccionar" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(catalogs.roles ?? []).map((r) => (
                                                <SelectItem key={r.id} value={String(r.id)}>
                                                    {r.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {formErrors.role_id && (
                                        <p className="text-xs text-destructive">{formErrors.role_id}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Campaña *</Label>
                                    <Select
                                        value={form.campaign}
                                        onValueChange={(v) =>
                                            setForm((f) => ({ ...f, campaign: v }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Seleccionar" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(catalogs.campaigns ?? []).map((c) => (
                                                <SelectItem key={c.id} value={c.name}>
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {formErrors.campaign && (
                                        <p className="text-xs text-destructive">{formErrors.campaign}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Área *</Label>
                                    <Select
                                        value={form.area}
                                        onValueChange={(v) =>
                                            setForm((f) => ({ ...f, area: v }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Seleccionar" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(catalogs.areas ?? []).map((a) => (
                                                <SelectItem key={a.id} value={a.name}>
                                                    {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {formErrors.area && (
                                        <p className="text-xs text-destructive">{formErrors.area}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Puesto *</Label>
                                    <Select
                                        value={form.position}
                                        onValueChange={(v) =>
                                            setForm((f) => ({ ...f, position: v }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Seleccionar" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(catalogs.positions ?? []).map((p) => (
                                                <SelectItem key={p.id} value={p.name}>
                                                    {p.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {formErrors.position && (
                                        <p className="text-xs text-destructive">{formErrors.position}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label>Sede</Label>
                                    <Select
                                        value={form.site || "none"}
                                        onValueChange={(v) =>
                                            setForm((f) => ({
                                                ...f,
                                                site: v === "none" ? "" : v,
                                                location: "",
                                            }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Opcional" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">Sin sede</SelectItem>
                                            {(catalogs.sites ?? []).map((s) => (
                                                <SelectItem key={s.id} value={s.name}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label>Ubicación</Label>
                                    <Select
                                        value={form.location || "none"}
                                        onValueChange={(v) =>
                                            setForm((f) => ({
                                                ...f,
                                                location: v === "none" ? "" : v,
                                            }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Opcional" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">Sin ubicación</SelectItem>
                                            {filteredLocations.map((u) => (
                                                <SelectItem key={u.id} value={u.name}>
                                                    {u.name}
                                                    {u.site_name ? ` (${u.site_name})` : ""}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </TabsContent>

                        {editUser && (
                            <TabsContent value="permissions">
                                <UserPermissionOverrides userId={editUser.id} />
                            </TabsContent>
                        )}
                    </Tabs>

                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDialogOpen(false)} disabled={formLoading}>
                            Cancelar
                        </Button>
                        <Button onClick={submitForm} disabled={formLoading}>
                            {formLoading && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                            {editUser ? "Guardar cambios" : "Crear usuario"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={viewOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setViewOpen(false);
                        setViewUser(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-[520px] max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="text-xl flex items-center gap-2">
                            <Eye className="h-5 w-5 text-primary" />
                            Detalle de usuario
                        </DialogTitle>
                    </DialogHeader>
                    {viewUser && (
                        <div className="space-y-4 text-sm">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-bold text-lg">{viewUser.name}</p>
                                    <p className="text-muted-foreground font-mono text-xs">
                                        #{viewUser.employee_number}
                                    </p>
                                </div>
                                <StatusBadge
                                    status={viewUser.status}
                                    isBlacklisted={viewUser.is_blacklisted}
                                />
                            </div>
                            <p>
                                <span className="text-muted-foreground">Email: </span>
                                {viewUser.email || "—"}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Campaña: </span>
                                {viewUser.campaign || "—"}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Área: </span>
                                {viewUser.area || "—"}
                            </p>
                            <DialogFooter className="pt-4">
                                <Button
                                    type="button"
                                    variant="default"
                                    onClick={() => {
                                        setViewOpen(false);
                                        openEdit(viewUser);
                                    }}
                                >
                                    Ir a editar
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={deleteOpen} onOpenChange={(o) => !deleteLoading && setDeleteOpen(o)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-destructive">
                            <AlertTriangle className="h-5 w-5" />
                            Confirmar baja técnica
                        </DialogTitle>
                        <DialogDescription>
                            Se eliminarán {deleteIds.length} usuario(s). Podrás restaurarlos desde la
                            papelera.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label>Motivo (mín. 5 caracteres)</Label>
                        <Textarea
                            value={deleteReason}
                            onChange={(e) => setDeleteReason(e.target.value)}
                            placeholder="Motivo de la baja…"
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleteOpen(false)} disabled={deleteLoading}>
                            Cancelar
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmDelete}
                            disabled={deleteLoading || deleteReason.trim().length < 5}
                        >
                            {deleteLoading && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                            Confirmar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
