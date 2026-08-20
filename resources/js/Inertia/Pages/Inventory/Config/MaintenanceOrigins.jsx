import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import CatalogPage from "@/Inertia/components/CatalogPage";
import CatalogDialog from "@/Inertia/components/CatalogDialog";
import useCatalog from "@/Inertia/hooks/useCatalog";

export default function MaintenanceOrigins() {
    const { maintenanceOrigins } = usePage().props;

    const catalog = useCatalog("/api/inv-maintenance-origins", () =>
        router.reload({ only: ["maintenanceOrigins"] })
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
            activeLabel: "Activo",
            inactiveLabel: "Inactivo",
        },
    ];

    const fields = [
        {
            key: "name",
            label: "Nombre",
            type: "text",
            required: true,
            placeholder: "Ej. Garantía de fábrica, Proveedor externo",
            help: "Mínimo 2 caracteres.",
        },
        {
            key: "code",
            label: "Código",
            type: "text",
            placeholder: "Ej. GARANTIA, EXTERNO",
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
            label: "Activo",
            type: "switch",
            switchDescription: "Controla si aparece al registrar un mantenimiento.",
            defaultValue: true,
        },
    ];

    return (
        <>
            <CatalogPage
                title="Orígenes de mantenimiento"
                description="De dónde viene un mantenimiento (garantía, proveedor externo, interno…)."
                columns={columns}
                data={maintenanceOrigins ?? []}
                onAdd={catalog.openCreate}
                onEdit={catalog.openEdit}
                onDelete={catalog.handleDelete}
                onToggle={catalog.handleToggle}
                loading={catalog.loading}
                addLabel="Crear origen"
                emptyMessage="No hay orígenes registrados."
            />

            <CatalogDialog
                key={catalog.editTarget?.id ?? "create"}
                open={catalog.dialogOpen}
                onClose={catalog.closeDialog}
                title={catalog.editTarget ? "Editar origen" : "Nuevo origen"}
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

MaintenanceOrigins.layout = (page) => (
    <AuthenticatedLayout title="Orígenes de mantenimiento">{page}</AuthenticatedLayout>
);
