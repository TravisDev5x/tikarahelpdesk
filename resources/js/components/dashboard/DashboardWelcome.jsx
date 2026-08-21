import { useEffect, useMemo, useState } from "react";
import { UserAvatar } from "@/components/user-avatar";

/**
 * Bloque de bienvenida común a los dashboards: avatar + Hola + saludo por
 * hora + reloj + día actual. Extraído de HomeDashboard.jsx (fase 1 de la
 * separación de dashboards) para que HomeSummary.jsx lo reuse también, sin
 * duplicar el reloj/saludo a mano.
 * Opcional: children (subtítulo/acciones bajo el reloj), actions (nodo a la derecha, ej. botones).
 */
export function DashboardWelcome({ user, children, actions }) {
    const [currentTime, setCurrentTime] = useState(() => new Date());
    useEffect(() => {
        const interval = setInterval(() => setCurrentTime(new Date()), 1000);
        return () => clearInterval(interval);
    }, []);
    const greeting = useMemo(() => {
        const h = currentTime.getHours();
        if (h >= 5 && h < 12) return "Buenos días";
        if (h >= 12 && h < 19) return "Buenas tardes";
        return "Buenas noches";
    }, [currentTime]);
    const clock = currentTime.toLocaleTimeString("es-ES", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    const dayLabel = currentTime.toLocaleDateString("es-ES", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

    return (
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div className="flex flex-wrap items-center gap-4">
                <UserAvatar
                    name={user?.name}
                    avatarUrl={user?.avatar_url}
                    avatarPath={user?.avatar_path}
                    size={48}
                    className="shrink-0"
                />
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold text-foreground">
                        Hola, {user?.name ?? "Usuario"}. {greeting}.
                    </h1>
                    <p className="text-sm text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0">
                        <span className="font-mono tabular-nums" aria-label="Hora actual">{clock}</span>
                        <span className="text-foreground/80">·</span>
                        <span>{dayLabel}</span>
                    </p>
                    {children}
                </div>
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2 shrink-0">{actions}</div>}
        </div>
    );
}
