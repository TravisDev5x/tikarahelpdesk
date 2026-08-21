import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import MonitorContent from "@/Inertia/components/Inventory/MonitorContent";

export default function Monitor() {
    return <MonitorContent isStandalone={false} />;
}

Monitor.layout = (page) => <AuthenticatedLayout title="Alertas y reportes de inventario">{page}</AuthenticatedLayout>;
