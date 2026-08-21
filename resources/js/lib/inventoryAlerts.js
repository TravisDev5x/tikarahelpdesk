import { CalendarClock, Repeat, UserX, Wrench } from "lucide-react";

/**
 * Metadata de las 4 alertas de Inventario (título/ícono/hint) -- fuente
 * única entre Inventory/Monitor.jsx (fila por fila) y
 * dashboard/HomeSummary.jsx (solo conteo, fase 1 de la separación de
 * dashboards) para no reinventar los textos en dos lugares.
 */
export const INVENTORY_ALERTS = [
    { key: "warrantyExpiring", countKey: "warranty_expiring", title: "Garantías por vencer", icon: CalendarClock, hint: "En los próximos 15 días" },
    { key: "unassigned", countKey: "unassigned", title: "Activos sin responsable", icon: UserX, hint: "Sin usuario asignado" },
    { key: "repeatedTransfers", countKey: "repeated_transfers", title: "Traslados repetidos", icon: Repeat, hint: "2+ en las últimas 24h" },
    { key: "staleMaintenances", countKey: "stale_maintenances", title: "Mantenimientos estancados", icon: Wrench, hint: "Abiertos hace más de 30 días" },
];
