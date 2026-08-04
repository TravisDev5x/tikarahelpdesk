import { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import CatalogPage from "@/Inertia/components/CatalogPage";
import RoleTemplateFormDialog from "@/Inertia/components/Roles/RoleTemplateFormDialog";
import useCatalog from "@/Inertia/hooks/useCatalog";
import { useAuth } from "@/context/AuthContext";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Pencil, Trash2 } from "lucide-react";

const ARCHETYPE_LABELS = {
    admin: "Administrador",
    supervisor: "Supervisor",
    agente: "Agente",
    solicitante: "Solicitante",
};

export default function Roles() {
    const { roles, authorizationObjects } = usePage().props;
    const { can } = useAuth();
    const canManage = can("roles.manage");

    const catalog = useCatalog("/api/roles", () => router.reload({ only: ["roles"] }));
    const [templateDialogOpen, setTemplateDialogOpen] = useState(false);
    const [editTarget, setEditTarget] = useState(null);

    const openCreate = () => {
        setEditTarget(null);
        setTemplateDialogOpen(true);
    };

    const openEdit = (row) => {
        setEditTarget(row);
        setTemplateDialogOpen(true);
    };

    const confirmDelete = (row) => {
        if (confirm(`¿Eliminar "${row.name}"? Esta acción no se puede deshacer.`)) {
            catalog.handleDelete(row);
        }
    };

    const columns = [
        { key: "name", label: "Nombre" },
        {
            key: "scope_archetype",
            label: "Alcance",
            width: "w-[140px]",
            render: (row) =>
                row.scope_archetype ? (
                    <Badge variant="outline" className="text-xs">
                        {ARCHETYPE_LABELS[row.scope_archetype] ?? row.scope_archetype}
                    </Badge>
                ) : (
                    <span className="text-xs text-muted-foreground">—</span>
                ),
        },
        {
            key: "guard_name",
            label: "Guard",
            width: "w-[120px]",
            render: (row) => (
                <Badge variant="secondary" className="font-mono text-xs">
                    {row.guard_name ?? "web"}
                </Badge>
            ),
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

    // Solo las plantillas (creadas con scope_archetype vía RoleTemplateController)
    // son editables por este formulario. Los roles legacy (team_id NULL, sin
    // scope_archetype) no pasaron por la matriz de objetos y no tienen forma
    // de reconstruirse en ella -- se quedan sin botón de editar.
    const customActions = canManage
        ? (row) => (
              <div className="flex items-center justify-end gap-1">
                  {row.scope_archetype && (
                      <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 gap-1"
                          onClick={() => openEdit(row)}
                      >
                          <Pencil className="h-3.5 w-3.5" />
                          Editar
                      </Button>
                  )}
                  <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-8 gap-1 text-destructive hover:text-destructive hover:bg-destructive/10"
                      onClick={() => confirmDelete(row)}
                  >
                      <Trash2 className="h-3.5 w-3.5" />
                      Eliminar
                  </Button>
              </div>
          )
        : undefined;

    return (
        <>
            <CatalogPage
                title="Roles"
                description="Administra las plantillas de rol de tu operador"
                columns={columns}
                data={roles ?? []}
                onAdd={canManage ? openCreate : undefined}
                customActions={customActions}
                loading={catalog.loading}
                addLabel="Nueva plantilla"
                emptyMessage="No hay roles registrados"
                canCreate={canManage}
            />

            <RoleTemplateFormDialog
                open={templateDialogOpen}
                onClose={() => setTemplateDialogOpen(false)}
                authorizationObjects={authorizationObjects}
                role={editTarget}
                onSaved={() => router.reload({ only: ["roles"] })}
            />
        </>
    );
}

Roles.layout = (page) => <AuthenticatedLayout title="Roles">{page}</AuthenticatedLayout>;
