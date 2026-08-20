import { useEffect, useState } from "react";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
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
import { Loader2, Save } from "lucide-react";

const SCOPE_ARCHETYPES = [
    {
        value: "admin",
        label: "Administrador",
        description: "Ve y gestiona todo, sin restricción de site.",
    },
    {
        value: "supervisor",
        label: "Supervisor",
        description: "Ve y gestiona tickets/incidencias de todos sus sites.",
    },
    {
        value: "agente",
        label: "Agente",
        description: "Ve tickets sin asignar o asignados a sí mismo, dentro de sus sites.",
    },
    {
        value: "solicitante",
        label: "Solicitante",
        description: "Solo ve y gestiona sus propios tickets/incidencias.",
    },
];

const LEVEL_LABELS = {
    full: "Full",
    edit: "Editar (sin eliminar)",
    read: "Solo lectura",
    none: "Ninguno",
};

function buildInitialLevels(authorizationObjects, role) {
    const rolePermissionNames = new Set((role?.permissions ?? []).map((p) => p.name));
    const levels = {};
    (authorizationObjects ?? []).forEach((category) => {
        (category.children ?? []).forEach((child) => {
            if (child.full_permission && rolePermissionNames.has(child.full_permission)) {
                levels[child.key] = "full";
            } else if (child.edit_permission && rolePermissionNames.has(child.edit_permission)) {
                levels[child.key] = "edit";
            } else if (child.read_permission && rolePermissionNames.has(child.read_permission)) {
                levels[child.key] = "read";
            } else {
                levels[child.key] = "none";
            }
        });
    });
    return levels;
}

export default function RoleTemplateFormDialog({ open, onClose, authorizationObjects, role = null, onSaved }) {
    const isEdit = Boolean(role);
    const [name, setName] = useState("");
    const [scopeArchetype, setScopeArchetype] = useState("");
    const [levels, setLevels] = useState(() => buildInitialLevels(authorizationObjects, role));
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        if (open) {
            setName(role?.name ?? "");
            setScopeArchetype(role?.scope_archetype ?? "");
            setLevels(buildInitialLevels(authorizationObjects, role));
            setErrors({});
        }
    }, [open, authorizationObjects, role]);

    const setLevel = (key, level) => {
        setLevels((prev) => ({ ...prev, [key]: level }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});
        try {
            const payload = {
                name: name.trim(),
                scope_archetype: scopeArchetype,
                objects: Object.entries(levels).map(([key, level]) => ({ key, level })),
            };
            if (isEdit) {
                await axios.put(`/api/role-templates/${role.id}`, payload);
            } else {
                await axios.post("/api/role-templates", payload);
            }
            notify.success(`Plantilla "${name.trim()}" ${isEdit ? "actualizada" : "creada"}`);
            onSaved?.();
            onClose();
        } catch (err) {
            if (handleAuthError(err)) return;
            if (err.response?.status === 422) {
                const fieldErrors = err.response.data.errors ?? {};
                setErrors(fieldErrors);
                // name/scope_archetype ya se muestran inline; cualquier otro
                // error (ej. un permiso del catálogo que no existe) no tiene
                // dónde renderizarse en la matriz -- avisar por toast para
                // no dejarlo silencioso.
                const hasOnlyKnownFields = Object.keys(fieldErrors).every(
                    (k) => k === "name" || k === "scope_archetype"
                );
                if (!hasOnlyKnownFields) {
                    notify.error(getApiErrorMessage(err, "Error al guardar la plantilla"));
                }
            } else {
                notify.error(getApiErrorMessage(err, "Error al guardar la plantilla"));
            }
        } finally {
            setLoading(false);
        }
    };

    const selectedArchetype = SCOPE_ARCHETYPES.find((a) => a.value === scopeArchetype);
    const canSubmit = name.trim().length >= 3 && scopeArchetype.length > 0;

    return (
        <Dialog open={open} onOpenChange={(o) => !loading && !o && onClose()}>
            <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{isEdit ? "Editar plantilla de rol" : "Nueva plantilla de rol"}</DialogTitle>
                    <DialogDescription>
                        Nombre libre + alcance + permisos por objeto. Se crea como rol de tu operador.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="space-y-1.5">
                        <Label htmlFor="template-name">Nombre</Label>
                        <Input
                            id="template-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="Ej. Soporte N1, Auditor de tickets"
                            disabled={loading}
                        />
                        {errors.name?.[0] && (
                            <p className="text-xs text-destructive">{errors.name[0]}</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="template-archetype">Alcance</Label>
                        <Select
                            value={scopeArchetype}
                            onValueChange={setScopeArchetype}
                            disabled={loading}
                        >
                            <SelectTrigger id="template-archetype">
                                <SelectValue placeholder="Selecciona un alcance" />
                            </SelectTrigger>
                            <SelectContent>
                                {SCOPE_ARCHETYPES.map((a) => (
                                    <SelectItem key={a.value} value={a.value}>
                                        {a.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {selectedArchetype && (
                            <p className="text-xs text-muted-foreground">
                                {selectedArchetype.description}
                            </p>
                        )}
                        {errors.scope_archetype?.[0] && (
                            <p className="text-xs text-destructive">{errors.scope_archetype[0]}</p>
                        )}
                    </div>

                    <div className="space-y-8">
                        {(authorizationObjects ?? []).map((category) => (
                            <div key={category.key} className="space-y-3">
                                <div className="flex items-center gap-2">
                                    <h4 className="text-xs font-bold uppercase tracking-widest text-primary/70">
                                        {category.label}
                                    </h4>
                                    <Separator className="flex-1" />
                                </div>
                                <div className="space-y-2">
                                    {(category.children ?? []).map((child) => (
                                        <div
                                            key={child.key}
                                            className="flex items-center justify-between gap-3 rounded-lg border border-muted p-3"
                                        >
                                            <div>
                                                <div className="text-sm font-medium">{child.label}</div>
                                                <div className="font-mono text-[10px] text-muted-foreground">
                                                    {child.full_permission}
                                                </div>
                                            </div>
                                            <Select
                                                value={levels[child.key] ?? "none"}
                                                onValueChange={(v) => setLevel(child.key, v)}
                                                disabled={loading}
                                            >
                                                <SelectTrigger className="w-[140px] shrink-0">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">{LEVEL_LABELS.none}</SelectItem>
                                                    {child.read_permission && (
                                                        <SelectItem value="read">{LEVEL_LABELS.read}</SelectItem>
                                                    )}
                                                    {child.edit_permission && (
                                                        <SelectItem value="edit">{LEVEL_LABELS.edit}</SelectItem>
                                                    )}
                                                    <SelectItem value="full">{LEVEL_LABELS.full}</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose} disabled={loading}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={!canSubmit || loading}>
                            {loading ? (
                                <Loader2 className="h-4 w-4 animate-spin mr-2" />
                            ) : (
                                <Save className="h-4 w-4 mr-2" />
                            )}
                            {isEdit ? "Guardar cambios" : "Crear plantilla"}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
