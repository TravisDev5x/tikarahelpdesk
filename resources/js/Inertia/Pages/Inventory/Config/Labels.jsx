import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import CatalogPage from "@/Inertia/components/CatalogPage";
import CatalogDialog from "@/Inertia/components/CatalogDialog";
import useCatalog from "@/Inertia/hooks/useCatalog";

export default function Labels() {
    const { labels } = usePage().props;

    const catalog = useCatalog("/api/inv-labels", () => router.reload({ only: ["labels"] }));

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
            placeholder: "Ej. Etiqueta blanca QR, Etiqueta metálica",
            help: "Mínimo 2 caracteres.",
        },
        {
            key: "is_active",
            label: "Activa",
            type: "switch",
            switchDescription: "Controla si aparece al etiquetar un activo.",
            defaultValue: true,
        },
    ];

    return (
        <>
            <CatalogPage
                title="Etiquetas de inventario"
                description="Tipos de etiqueta física usados para marcar activos."
                columns={columns}
                data={labels ?? []}
                onAdd={catalog.openCreate}
                onEdit={catalog.openEdit}
                onDelete={catalog.handleDelete}
                onToggle={catalog.handleToggle}
                loading={catalog.loading}
                addLabel="Crear etiqueta"
                emptyMessage="No hay etiquetas registradas."
            />

            <CatalogDialog
                key={catalog.editTarget?.id ?? "create"}
                open={catalog.dialogOpen}
                onClose={catalog.closeDialog}
                title={catalog.editTarget ? "Editar etiqueta" : "Nueva etiqueta"}
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

Labels.layout = (page) => <AuthenticatedLayout title="Etiquetas de inventario">{page}</AuthenticatedLayout>;
