import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import CatalogPage from "@/Inertia/components/CatalogPage";
import CatalogDialog from "@/Inertia/components/CatalogDialog";
import useCatalog from "@/Inertia/hooks/useCatalog";

export default function MaintenanceModalities() {
    const { maintenanceModalities } = usePage().props;

    const catalog = useCatalog("/api/inv-maintenance-modalities", () =>
        router.reload({ only: ["maintenanceModalities"] })
    );

    const columns = [
        { key: "id", label: "ID", width: "w-[80px]" },
        { key: "code", label: "Código", width: "w-[140px]" },
        { key: "name", label: "Nombre" },
        { key: "sort_order", label: "Orden", width: "w-[100px]" },
        {
            key: "is_active",
            label: "Estado",
            width: "w-[160px]",
            activeLabel: "Activa",
            inactiveLabel: "Inactiva",
        },
    ];

    const fields = [
        {
            key: "name",
            label: "Nombre",
            type: "text",
            required: true,
            placeholder: "Ej. En sitio, En taller, Remoto",
            help: "Mínimo 2 caracteres.",
        },
        {
            key: "code",
            label: "Código",
            type: "text",
            placeholder: "Ej. SITIO, TALLER",
            help: "Opcional, único.",
        },
        {
            key: "sort_order",
            label: "Orden",
            type: "number",
            min: 0,
            defaultValue: 0,
        },
        {
            key: "is_active",
            label: "Activa",
            type: "switch",
            switchDescription: "Controla si aparece al registrar un mantenimiento.",
            defaultValue: true,
        },
    ];

    return (
        <>
            <CatalogPage
                title="Modalidades de mantenimiento"
                description="Cómo se realiza un mantenimiento (en sitio, en taller, remoto…)."
                columns={columns}
                data={maintenanceModalities ?? []}
                onAdd={catalog.openCreate}
                onEdit={catalog.openEdit}
                onDelete={catalog.handleDelete}
                onToggle={catalog.handleToggle}
                loading={catalog.loading}
                addLabel="Crear modalidad"
                emptyMessage="No hay modalidades registradas."
            />

            <CatalogDialog
                key={catalog.editTarget?.id ?? "create"}
                open={catalog.dialogOpen}
                onClose={catalog.closeDialog}
                title={catalog.editTarget ? "Editar modalidad" : "Nueva modalidad"}
                fields={fields}
                initialValues={catalog.editTarget ?? { is_active: true, sort_order: 0 }}
                onSubmit={catalog.handleSubmit}
                loading={catalog.loading}
                errors={catalog.dialogErrors}
                submitLabel={catalog.editTarget ? "Actualizar" : "Crear"}
            />
        </>
    );
}

MaintenanceModalities.layout = (page) => (
    <AuthenticatedLayout title="Modalidades de mantenimiento">{page}</AuthenticatedLayout>
);
