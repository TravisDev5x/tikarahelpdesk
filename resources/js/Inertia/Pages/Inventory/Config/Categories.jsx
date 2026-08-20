import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import CatalogPage from "@/Inertia/components/CatalogPage";
import CatalogDialog from "@/Inertia/components/CatalogDialog";
import useCatalog from "@/Inertia/hooks/useCatalog";

const TYPE_OPTIONS = [
    { value: "HARDWARE", label: "Hardware" },
    { value: "SOFTWARE", label: "Software" },
    { value: "CONSUMIBLE", label: "Consumible" },
];

export default function Categories() {
    const { categories } = usePage().props;

    const catalog = useCatalog("/api/inv-categories", () => router.reload({ only: ["categories"] }));

    const columns = [
        { key: "id", label: "ID", width: "w-[80px]" },
        { key: "name", label: "Nombre" },
        {
            key: "type",
            label: "Tipo",
            width: "w-[140px]",
            render: (row) => TYPE_OPTIONS.find((o) => o.value === row.type)?.label ?? "—",
        },
        { key: "prefix", label: "Prefijo", width: "w-[120px]" },
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
            placeholder: "Ej. Laptops, Impresoras, Consumibles de red",
            help: "Mínimo 2 caracteres.",
        },
        {
            key: "type",
            label: "Tipo",
            type: "select",
            options: TYPE_OPTIONS,
            placeholder: "Seleccionar tipo…",
        },
        {
            key: "prefix",
            label: "Prefijo",
            type: "text",
            placeholder: "Ej. LAP, IMP",
            help: "Prefijo sugerido para el número de inventario (opcional).",
        },
        {
            key: "require_specs",
            label: "Requiere especificaciones",
            type: "switch",
            switchDescription: "Pide capturar specs técnicas al dar de alta un activo de esta categoría.",
            defaultValue: false,
        },
        {
            key: "is_active",
            label: "Activa",
            type: "switch",
            switchDescription: "Controla si aparece en los formularios de activos.",
            defaultValue: true,
        },
    ];

    return (
        <>
            <CatalogPage
                title="Categorías de inventario"
                description="Clasificación de activos (hardware, software, consumibles)."
                columns={columns}
                data={categories ?? []}
                onAdd={catalog.openCreate}
                onEdit={catalog.openEdit}
                onDelete={catalog.handleDelete}
                onToggle={catalog.handleToggle}
                loading={catalog.loading}
                addLabel="Crear categoría"
                emptyMessage="No hay categorías registradas."
            />

            <CatalogDialog
                key={catalog.editTarget?.id ?? "create"}
                open={catalog.dialogOpen}
                onClose={catalog.closeDialog}
                title={catalog.editTarget ? "Editar categoría" : "Nueva categoría"}
                fields={fields}
                initialValues={catalog.editTarget ?? { is_active: true, require_specs: false }}
                onSubmit={catalog.handleSubmit}
                loading={catalog.loading}
                errors={catalog.dialogErrors}
                submitLabel={catalog.editTarget ? "Actualizar" : "Crear"}
            />
        </>
    );
}

Categories.layout = (page) => <AuthenticatedLayout title="Categorías de inventario">{page}</AuthenticatedLayout>;
