import { CalendarClock, Flame, Repeat, UserX, Wrench } from "lucide-react";

/**
 * Metadata de las alertas de Inventario (título/ícono/hint) -- fuente
 * única entre Inventory/Monitor.jsx (fila por fila) y
 * dashboard/HomeSummary.jsx (solo conteo, fase 1 de la separación de
 * dashboards) para no reinventar los textos en dos lugares. Fase 5 de la
 * auditoría (Inteligencia): warrantyExpiring/repeatedTransfers se
 * enriquecieron (severidad graduada en vez de un corte binario) sin
 * cambiar key/countKey; problemAssets es la única entrada nueva.
 */
export const INVENTORY_ALERTS = [
    { key: "warrantyExpiring", countKey: "warranty_expiring", title: "Garantías por vencer", icon: CalendarClock, hint: "Vencidas o por vencer en 30 días" },
    { key: "unassigned", countKey: "unassigned", title: "Activos sin responsable", icon: UserX, hint: "Sin usuario asignado" },
    { key: "repeatedTransfers", countKey: "repeated_transfers", title: "Anomalías de traslados", icon: Repeat, hint: "Actividad fuera de lo normal" },
    { key: "staleMaintenances", countKey: "stale_maintenances", title: "Mantenimientos estancados", icon: Wrench, hint: "Abiertos hace más de 30 días" },
    { key: "problemAssets", countKey: "problem_assets", title: "Activos con muchos tickets", icon: Flame, hint: "3+ tickets en 90 días" },
];
