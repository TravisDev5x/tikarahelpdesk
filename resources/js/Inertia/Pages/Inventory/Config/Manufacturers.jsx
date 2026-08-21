import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import CatalogPage from "@/Inertia/components/CatalogPage";
import CatalogDialog from "@/Inertia/components/CatalogDialog";
import useCatalog from "@/Inertia/hooks/useCatalog";

export default function Manufacturers() {
    const { manufacturers } = usePage().props;

    const catalog = useCatalog("/api/inv-manufacturers", () => router.reload({ only: ["manufacturers"] }));

    const columns = [
        { key: "id", label: "ID", width: "w-[80px]" },
        { key: "name", label: "Nombre" },
        {
            key: "is_active",
            label: "Estado",
            width: "w-[160px]",
            activeLabel: "Activa",
            inactiveLabel: "Inactiva",
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
            placeholder: "Ej. Dell, HP, Lenovo",
            help: "Mínimo 2 caracteres.",
        },
        {
            key: "is_active",
            label: "Activo",
            type: "switch",
            switchDescription: "Controla si aparece al registrar un activo.",
            defaultValue: true,
        },
    ];

    return (
        <>
            <CatalogPage
                title="Fabricantes de inventario"
                description="Marcas/fabricantes de los activos registrados."
                columns={columns}
                data={manufacturers ?? []}
                onAdd={catalog.openCreate}
                onEdit={catalog.openEdit}
                onDelete={catalog.handleDelete}
                onToggle={catalog.handleToggle}
                loading={catalog.loading}
                addLabel="Crear fabricante"
                emptyMessage="No hay fabricantes registrados."
            />

            <CatalogDialog
                key={catalog.editTarget?.id ?? "create"}
                open={catalog.dialogOpen}
                onClose={catalog.closeDialog}
                title={catalog.editTarget ? "Editar fabricante" : "Nuevo fabricante"}
                fields={fields}
                initialValues={catalog.editTarget ?? { is_active: true }}
                onSubmit={catalog.handleSubmit}
                loading={catalog.loading}
                errors={catalog.dialogErrors}
                submitLabel={catalog.editTarget ? "Actualizar" : "Crear"}
            />
        </>
    );
}

Manufacturers.layout = (page) => <AuthenticatedLayout title="Fabricantes de inventario">{page}</AuthenticatedLayout>;
