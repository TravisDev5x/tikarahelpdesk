import { useEffect, useState } from "react";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Skeleton } from "@/components/ui/skeleton";
import { Loader2, MapPin } from "lucide-react";

/**
 * Panel de asignación de site_user fuera de onboarding (auditoría
 * 2026-08-11, deuda documentada desde Fase 7.7 en docs/PENDING.md): antes
 * solo se podía asignar sites a staff desde /onboarding/teams, y solo
 * mientras ese wizard seguía abierto. Mismo patrón que
 * UserPermissionOverrides.jsx (fetch propio, GET/POST a
 * /api/users/{id}/sites, gateado en backend por sites.assign_staff).
 */
export default function UserSiteAssignment({ userId }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        axios
            .get(`/api/users/${userId}/sites`)
            .then(({ data: res }) => {
                if (cancelled) return;
                setData(res);
                setSelected((res.assigned ?? []).map((s) => s.id));
            })
            .catch((err) => {
                if (cancelled) return;
                if (handleAuthError(err)) return;
                notify.error(getApiErrorMessage(err, "No se pudieron cargar las sedes"));
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [userId]);

    const toggleSite = (siteId) => {
        setSelected((prev) =>
            prev.includes(siteId) ? prev.filter((id) => id !== siteId) : [...prev, siteId]
        );
    };

    const save = async () => {
        setSaving(true);
        try {
            const { data: res } = await axios.post(`/api/users/${userId}/sites`, {
                site_ids: selected,
            });
            setData((prev) => ({ ...prev, assigned: res.assigned }));
            notify.success("Sedes actualizadas");
        } catch (err) {
            if (handleAuthError(err)) return;
            notify.error(getApiErrorMessage(err, "Error al guardar las sedes"));
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="space-y-2 pt-2">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-8 w-full" />
            </div>
        );
    }

    const available = data?.available ?? [];
    const assignedIds = (data?.assigned ?? []).map((s) => s.id).slice().sort();
    const selectedSorted = [...selected].sort();
    const hasPendingChanges = JSON.stringify(selectedSorted) !== JSON.stringify(assignedIds);

    if (available.length === 0) {
        return (
            <p className="text-xs text-muted-foreground pt-2">
                Este tenant todavía no tiene sedes registradas.
            </p>
        );
    }

    return (
        <div className="space-y-3 pt-2">
            <div className="grid gap-1.5 sm:grid-cols-2">
                {available.map((site) => (
                    <label
                        key={site.id}
                        className="flex items-center gap-2 rounded-md border px-2 py-1.5 text-sm cursor-pointer"
                    >
                        <Checkbox
                            checked={selected.includes(site.id)}
                            onCheckedChange={() => toggleSite(site.id)}
                            disabled={saving}
                        />
                        <MapPin className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                        <span className="truncate">{site.name}</span>
                    </label>
                ))}
            </div>
            <Button type="button" size="sm" onClick={save} disabled={saving || !hasPendingChanges}>
                {saving && <Loader2 className="h-3.5 w-3.5 mr-2 animate-spin" />}
                Guardar sedes
            </Button>
        </div>
    );
}
