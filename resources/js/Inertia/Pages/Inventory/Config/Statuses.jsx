import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import CatalogPage from "@/Inertia/components/CatalogPage";
import CatalogDialog from "@/Inertia/components/CatalogDialog";
import useCatalog from "@/Inertia/hooks/useCatalog";

export default function Statuses() {
    const { statuses } = usePage().props;

    const catalog = useCatalog("/api/inv-statuses", () => router.reload({ only: ["statuses"] }));

    const columns = [
        { key: "id", label: "ID", width: "w-[80px]" },
        { key: "name", label: "Nombre" },
        {
            key: "assignable",
            label: "Asignable",
            width: "w-[120px]",
            render: (row) => (row.assignable ? "Sí" : "No"),
        },
        {
            key: "is_active",
            label: "Estado",
            width: "w-[160px]",
            activeLabel: "Activo",
            inactiveLabel: "Inactivo",
        },
        {
            key: "created_at",
            label: "Creado",
            width: "w-[180px]",
            render: (row) =>
                row.created_at
                    ? new Date(row.created_at).toLocaleDateString("es-ES")
                    : "—",
        },
    ];

    const fields = [
        {
            key: "name",
            label: "Nombre",
            type: "text",
            required: true,
            placeholder: "Ej. Disponible, Asignado, Baja",
            help: "Mínimo 2 caracteres.",
        },
        {
            key: "badge_class",
            label: "Clase de badge",
            type: "text",
            placeholder: "Ej. success, warning, secondary",
            help: "Opcional — variante visual del badge en las listas de activos.",
        },
        {
            key: "assignable",
            label: "Asignable",
            type: "switch",
            switchDescription: "Un activo con este estatus puede asignarse a un usuario.",
            defaultValue: true,
        },
        {
            key: "is_active",
            label: "Activo",
            type: "switch",
            switchDescription: "Controla si aparece en los formularios de activos.",
            defaultValue: true,
        },
    ];

    return (
        <>
            <CatalogPage
                title="Estatus de inventario"
                description="Ciclo de vida de un activo (disponible, asignado, mantenimiento, baja…)."
                columns={columns}
                data={statuses ?? []}
                onAdd={catalog.openCreate}
                onEdit={catalog.openEdit}
                onDelete={catalog.handleDelete}
                onToggle={catalog.handleToggle}
                loading={catalog.loading}
                addLabel="Crear estatus"
                emptyMessage="No hay estatus registrados."
            />

            <CatalogDialog
                key={catalog.editTarget?.id ?? "create"}
                open={catalog.dialogOpen}
                onClose={catalog.closeDialog}
                title={catalog.editTarget ? "Editar estatus" : "Nuevo estatus"}
                fields={fields}
                initialValues={catalog.editTarget ?? { is_active: true, assignable: true }}
                onSubmit={catalog.handleSubmit}
                loading={catalog.loading}
                errors={catalog.dialogErrors}
                submitLabel={catalog.editTarget ? "Actualizar" : "Crear"}
            />
        </>
    );
}

Statuses.layout = (page) => <AuthenticatedLayout title="Estatus de inventario">{page}</AuthenticatedLayout>;
