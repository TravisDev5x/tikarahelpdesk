import { useEffect, useState } from "react";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Loader2, Plus, X } from "lucide-react";

export default function UserPermissionOverrides({ userId }) {
    const [breakdown, setBreakdown] = useState(null);
    const [allPermissions, setAllPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [addValue, setAddValue] = useState("");
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState(null);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        Promise.all([
            axios.get(`/api/users/${userId}/permissions`),
            axios.get("/api/permissions"),
        ])
            .then(([permsRes, allRes]) => {
                if (cancelled) return;
                setBreakdown(permsRes.data);
                setAllPermissions((allRes.data ?? []).filter((p) => p.guard_name === "web"));
            })
            .catch((err) => {
                if (cancelled) return;
                if (handleAuthError(err)) return;
                notify.error(getApiErrorMessage(err, "No se pudieron cargar los permisos"));
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [userId]);

    const addOverride = async () => {
        if (!addValue) return;
        setAdding(true);
        try {
            const { data } = await axios.post(`/api/users/${userId}/permission-overrides`, {
                permission: addValue,
            });
            setBreakdown(data);
            setAddValue("");
            notify.success(`Override "${addValue}" agregado`);
        } catch (err) {
            if (handleAuthError(err)) return;
            notify.error(getApiErrorMessage(err, "Error al agregar el override"));
        } finally {
            setAdding(false);
        }
    };

    const removeOverride = async (permission) => {
        setRemoving(permission);
        try {
            const { data } = await axios.delete(
                `/api/users/${userId}/permission-overrides/${permission}`
            );
            setBreakdown(data);
            notify.success(`Override "${permission}" quitado`);
        } catch (err) {
            if (handleAuthError(err)) return;
            notify.error(getApiErrorMessage(err, "Error al quitar el override"));
        } finally {
            setRemoving(null);
        }
    };

    if (loading) {
        return (
            <div className="space-y-3 pt-2">
                <Skeleton className="h-4 w-40" />
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-8 w-full" />
            </div>
        );
    }

    const viaRoles = breakdown?.via_roles ?? [];
    const direct = breakdown?.direct ?? [];
    const alreadyGranted = new Set([...viaRoles, ...direct]);
    const addableOptions = allPermissions.filter((p) => !alreadyGranted.has(p.name));

    return (
        <div className="space-y-6 pt-2">
            <div className="space-y-2">
                <h4 className="text-xs font-bold uppercase tracking-widest text-primary/70">
                    Heredado de rol{viaRoles.length !== 1 ? "es" : ""}
                    {breakdown?.roles?.length ? ` (${breakdown.roles.join(", ")})` : ""}
                </h4>
                <div className="flex flex-wrap gap-1.5">
                    {viaRoles.length ? (
                        viaRoles.map((name) => (
                            <Badge key={name} variant="secondary" className="font-mono text-xs">
                                {name}
                            </Badge>
                        ))
                    ) : (
                        <p className="text-xs text-muted-foreground">Sin permisos por rol</p>
                    )}
                </div>
            </div>

            <div className="space-y-2">
                <h4 className="text-xs font-bold uppercase tracking-widest text-primary/70">
                    Overrides directos
                </h4>
                <div className="space-y-1.5">
                    {direct.length ? (
                        direct.map((name) => (
                            <div
                                key={name}
                                className="flex items-center justify-between gap-2 rounded-lg border border-muted px-3 py-1.5"
                            >
                                <span className="font-mono text-xs">{name}</span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-6 w-6 text-destructive"
                                    disabled={removing === name}
                                    onClick={() => removeOverride(name)}
                                >
                                    {removing === name ? (
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                    ) : (
                                        <X className="h-3.5 w-3.5" />
                                    )}
                                </Button>
                            </div>
                        ))
                    ) : (
                        <p className="text-xs text-muted-foreground">Sin overrides directos</p>
                    )}
                </div>
            </div>

            <div className="space-y-1.5">
                <h4 className="text-xs font-bold uppercase tracking-widest text-primary/70">
                    Agregar override
                </h4>
                <div className="flex gap-2">
                    <Select value={addValue} onValueChange={setAddValue} disabled={adding}>
                        <SelectTrigger className="flex-1">
                            <SelectValue placeholder="Selecciona un permiso" />
                        </SelectTrigger>
                        <SelectContent>
                            {addableOptions.map((p) => (
                                <SelectItem key={p.id} value={p.name}>
                                    {p.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button type="button" onClick={addOverride} disabled={!addValue || adding}>
                        {adding ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Plus className="h-4 w-4" />
                        )}
                    </Button>
                </div>
            </div>
        </div>
    );
}
