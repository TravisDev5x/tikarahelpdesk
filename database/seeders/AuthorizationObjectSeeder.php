<?php

namespace Database\Seeders;

use App\Models\AuthorizationObject;
use Illuminate\Database\Seeder;

/**
 * RBAC v2 (Fase 6), Paso 4: puebla el catálogo de objetos de autorización
 * a partir de la lista de permisos ya confirmada en la auditoría (Core,
 * Tickets, Incidents, Clients, Company, Platform) -- no inventa permisos
 * nuevos, cada full_permission/read_permission referencia un nombre que ya
 * existe en la tabla `permissions`.
 *
 * Variante de solo lectura (read_permission) -- solo estos 4 objetos la
 * tienen realmente en el catálogo actual, confirmado leyendo los permisos
 * existentes, no inventado:
 *   - Tickets.Ver:   view_area (full) / view_own (read)
 *   - Incidents.Ver: view_area (full) / view_own (read)
 *   - Clients.Ver:   view_all (full)  / view (read)
 *   - Company:       edit (full)      / view (read) -- el único par
 *     genuinamente "editar vs. solo ver" del catálogo; los otros 3 son
 *     "alcance amplio vs. alcance propio" (view_area/view_own), no
 *     "escribir vs. leer" en sentido estricto -- se documentan igual como
 *     full/read porque es la distinción más cercana que ya existe, pero
 *     no son idénticos conceptualmente. Reportado, no forzado a encajar
 *     en silencio.
 *
 * Todo lo demás (users.manage, roles.manage, permissions.manage,
 * catalogs.manage, notifications.manage, tickets.create/assign/reassign/
 * comment/change_status/escalate/manage_all/filter_by_site,
 * incidents.create/manage_all, clients.create/edit/delete,
 * platform.view_internals) NO tiene variante de solo lectura en el
 * catálogo actual -- son acciones atómicas, no pares ver/editar. Ninguna
 * se inventó aquí.
 */
class AuthorizationObjectSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedModule('tickets', 'Tickets', [
            ['key' => 'tickets.view', 'label' => 'Ver', 'full_permission' => 'tickets.view_area', 'read_permission' => 'tickets.view_own'],
            ['key' => 'tickets.manage_all', 'label' => 'Gestionar todo (sin restricción de site)', 'full_permission' => 'tickets.manage_all'],
            ['key' => 'tickets.create', 'label' => 'Crear', 'full_permission' => 'tickets.create'],
            ['key' => 'tickets.assign', 'label' => 'Asignar (tomar sin dueño)', 'full_permission' => 'tickets.assign'],
            ['key' => 'tickets.reassign', 'label' => 'Reasignar (mover ya asignado)', 'full_permission' => 'tickets.reassign'],
            ['key' => 'tickets.comment', 'label' => 'Comentar', 'full_permission' => 'tickets.comment'],
            ['key' => 'tickets.change_status', 'label' => 'Cambiar estado', 'full_permission' => 'tickets.change_status'],
            ['key' => 'tickets.escalate', 'label' => 'Escalar de área', 'full_permission' => 'tickets.escalate'],
            ['key' => 'tickets.filter_by_site', 'label' => 'Filtrar por site', 'full_permission' => 'tickets.filter_by_site'],
        ]);

        $this->seedModule('incidents', 'Incidencias', [
            ['key' => 'incidents.view', 'label' => 'Ver', 'full_permission' => 'incidents.view_area', 'read_permission' => 'incidents.view_own'],
            ['key' => 'incidents.manage_all', 'label' => 'Gestionar todo', 'full_permission' => 'incidents.manage_all'],
            ['key' => 'incidents.create', 'label' => 'Crear', 'full_permission' => 'incidents.create'],
        ]);

        $this->seedModule('clients', 'Clientes', [
            ['key' => 'clients.view', 'label' => 'Ver', 'full_permission' => 'clients.view_all', 'read_permission' => 'clients.view'],
            ['key' => 'clients.create', 'label' => 'Crear', 'full_permission' => 'clients.create'],
            ['key' => 'clients.edit', 'label' => 'Editar', 'full_permission' => 'clients.edit'],
            ['key' => 'clients.delete', 'label' => 'Eliminar', 'full_permission' => 'clients.delete'],
        ]);

        $this->seedModule('company', 'Mi empresa', [
            ['key' => 'company.manage', 'label' => 'Ver / Editar', 'full_permission' => 'company.edit', 'read_permission' => 'company.view'],
        ]);

        $this->seedModule('core', 'Administración', [
            ['key' => 'core.users', 'label' => 'Usuarios', 'full_permission' => 'users.manage'],
            ['key' => 'core.roles', 'label' => 'Roles', 'full_permission' => 'roles.manage'],
            ['key' => 'core.permissions', 'label' => 'Permisos', 'full_permission' => 'permissions.manage'],
            ['key' => 'core.catalogs', 'label' => 'Catálogos', 'full_permission' => 'catalogs.manage'],
            ['key' => 'core.notifications', 'label' => 'Notificaciones', 'full_permission' => 'notifications.manage'],
        ]);

        $this->seedModule('platform', 'Plataforma', [
            ['key' => 'platform.view_internals', 'label' => 'Ver datos internos de tenants', 'full_permission' => 'platform.view_internals'],
        ]);
    }

    private function seedModule(string $key, string $label, array $children): void
    {
        $parent = AuthorizationObject::updateOrCreate(
            ['key' => $key],
            ['label' => $label, 'parent_id' => null, 'full_permission' => null, 'read_permission' => null]
        );

        foreach ($children as $i => $child) {
            AuthorizationObject::updateOrCreate(
                ['key' => $child['key']],
                [
                    'label' => $child['label'],
                    'parent_id' => $parent->id,
                    'full_permission' => $child['full_permission'],
                    'read_permission' => $child['read_permission'] ?? null,
                    'sort_order' => $i,
                ]
            );
        }
    }
}
