<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los 4 roles del sprint maestro (Fase 3): admin, supervisor, agente,
 * solicitante. Coexisten a propósito con los roles legacy que sigue
 * creando FullDemoSeeder (admin, super_admin, gerente, supervisor, soporte,
 * soporte_n1/n2/n3, usuario, consultor) -- el corte real (reasignar
 * usuarios y retirar los legacy) es un paso aparte y explícito, ver
 * App\Console\Commands\MigrateLegacyRoles.
 *
 * RBAC v2 (Fase 7, 2026-08-09): se agrega "Encargado TI" como 5ta plantilla
 * por defecto -- mismo permiso base que agente (puede trabajar los tickets
 * que resulten) más tickets.review_pending, para revisar la cola de
 * correos no reconocidos (docs/PENDING_TICKET_REVIEW.md) sin necesitar
 * tickets.manage_all completo. A diferencia de los 4 originales (nombres
 * código en minúscula, referenciados en varios lugares del código), esta
 * es una plantilla "sugerida" más -- su nombre es libre igual que
 * cualquier plantilla creada a mano vía RoleTemplateController, no hay
 * lógica que dependa de su name exacto.
 *
 * RBAC v2 (Fase 6, Paso 2): DEJÓ de crear roles GLOBALES una sola vez.
 * Ahora crea plantillas DENTRO del team_id vigente (spatie/laravel-permission
 * teams = clients.id) -- requiere que el llamador ya haya fijado el
 * contexto con setPermissionsTeamId($clientId) antes de invocar run(); no
 * lo resuelve él mismo. El punto de entrada real para operadores es
 * App\Console\Commands\SeedDefaultTenantRoles
 * (`tenants:seed-default-roles {portal_slug}`), que resuelve el tenant y
 * fija el contexto antes de invocar este seeder -- es exactamente lo que
 * Fase 7 (onboarding) va a invocar más adelante al crear un tenant nuevo.
 *
 * Mapeo acordado tras la auditoría del sprint (2026-07-12):
 *   admin                              -> admin
 *   super_admin                        -> (fuera del RBAC de tenant, es de plataforma)
 *   gerente, supervisor                -> supervisor
 *   soporte, soporte_n1/n2/n3          -> agente
 *   usuario                            -> solicitante
 *   consultor                          -> agente (colapsa, sin variante de solo lectura)
 *   visitante (nunca seedeado)         -> diferido a Fase 4 ("solicitante sin site")
 *
 * Los permisos base son los mismos que ya usan los roles legacy
 * equivalentes (FullDemoSeeder::seedRolesAndPermissions) -- Fase 3 es un
 * renombrado/consolidación de roles, no un rediseño del modelo de permisos.
 * Adiciones reales desde entonces: tickets.reassign (antes no existía; la
 * acción "reassigned" de TicketController usa tickets.assign, ver Fase 5),
 * inventory.manage_config e inventory.manage_assets (módulo Inventario,
 * port desde HelpdeskECD2026 fases 1 y 2 -- solo admin las recibe hoy, vía
 * $allWebPermissions); inventory.view_assets/inventory.edit_assets (fase
 * 7.4, permisos granulares de Activos -- ninguna plantilla las recibe por
 * defecto, son opt-in vía RoleTemplateFormDialog u overrides directos).
 */
class TenantRoleSeeder extends Seeder
{
    public function run(): void
    {
        $teamId = getPermissionsTeamId();
        if ($teamId === null) {
            throw new \RuntimeException(
                'TenantRoleSeeder requiere team_id -- llama setPermissionsTeamId($clientId) '
                .'antes de invocarlo (o usa el comando tenants:seed-default-roles).'
            );
        }

        $this->command?->info("TenantRoleSeeder: admin, supervisor, agente, solicitante, Encargado TI (team_id={$teamId}).");

        DB::transaction(function () use ($teamId) {
            $this->seedPermissions();
            $this->seedRoles($teamId);
        });

        $this->command?->info('TenantRoleSeeder finalizado.');
    }

    private function seedPermissions(): void
    {
        // Los permisos NO están team-scoped -- catálogo global (ver migración
        // 2026_07_15_000001_add_team_id_to_permission_tables.php: el stub
        // oficial de Spatie tampoco le agrega team_id a `permissions`).
        foreach (['web', 'sanctum'] as $guard) {
            DB::table('permissions')->insertOrIgnore([
                'name' => 'tickets.reassign',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Inventario (port desde HelpdeskECD2026, fase 1 -- catálogos base).
            DB::table('permissions')->insertOrIgnore([
                'name' => 'inventory.manage_config',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Inventario fase 2 -- registro de activos (client_id-scoped, no
            // operador-compartido como manage_config).
            DB::table('permissions')->insertOrIgnore([
                'name' => 'inventory.manage_assets',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Inventario fase 7.4 -- permisos granulares de Activos: ver /
            // editar (sin eliminar). manage_assets de arriba queda como el
            // nivel completo (incluye eliminar).
            DB::table('permissions')->insertOrIgnore([
                'name' => 'inventory.view_assets',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('permissions')->insertOrIgnore([
                'name' => 'inventory.edit_assets',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedRoles(int|string $teamId): void
    {
        $allWebPermissions = DB::table('permissions')
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        $agentePerms = [
            'tickets.view_area',
            'tickets.comment',
            'tickets.change_status',
            'tickets.assign',
            'tickets.filter_by_site',
            'tickets.escalate',
        ];

        $supervisorPerms = array_values(array_unique(array_merge(
            $agentePerms,
            [
                // NO tickets.manage_all: bajo Fase 4 (scoping por site vía
                // TicketPolicy::siteScopeType), esa permission significa "ve
                // TODO sin restricción de site" -- es lo que distingue a
                // admin. Dársela a supervisor lo colapsaría al bucket de
                // admin en vez de "sus sites" (bug real que se detectó
                // escribiendo los tests de Fase 4, ver TicketSiteScopingTest).
                'tickets.reassign',
                'incidents.view_area',
                'incidents.manage_all',
                // Panel de asignación de site_user fuera de onboarding
                // (auditoría 2026-08-11): un supervisor gestiona el roster
                // de staff de SUS sites de forma natural -- mismo criterio
                // que ya lo distingue de agente/solicitante en el resto de
                // este seeder. agente/solicitante/Encargado TI NO lo
                // reciben por defecto: ninguno tiene hoy ninguna otra
                // capacidad administrativa/de staffing, y Encargado TI es
                // específicamente para revisar la cola de correos, no para
                // gestionar personal.
                'sites.assign_staff',
            ]
        )));

        // Fase 7: mismo alcance que agente (scope_archetype 'agente' --
        // puede ver/trabajar el site del ticket que resulte de aprobar una
        // solicitud) más la habilidad de revisar la cola. No usa
        // tickets.manage_all a propósito, mismo motivo que supervisor.
        $encargadoTiPerms = array_values(array_unique(array_merge(
            $agentePerms,
            ['tickets.review_pending']
        )));

        $roles = [
            // Admin: todos los permisos (igual que el admin legacy).
            'admin' => ['perms' => $allWebPermissions, 'archetype' => 'admin'],
            // Supervisor: colapsa gerente+supervisor legacy. Único rol de
            // tenant con tickets.reassign en esta fase -- Fase 5 decide si
            // agente también lo necesita cuando implemente la acción real.
            'supervisor' => ['perms' => $supervisorPerms, 'archetype' => 'supervisor'],
            // Agente: colapsa soporte/soporte_n1/n2/n3 + consultor legacy
            // (consultor no tiene variante de solo lectura separada).
            'agente' => ['perms' => $agentePerms, 'archetype' => 'agente'],
            // Solicitante: igual que usuario legacy.
            'solicitante' => ['perms' => ['tickets.create', 'tickets.view_own'], 'archetype' => 'solicitante'],
            // Encargado TI (Fase 7): revisa la cola de correos no reconocidos.
            'Encargado TI' => ['perms' => $encargadoTiPerms, 'archetype' => 'agente'],
        ];

        foreach ($roles as $roleName => $def) {
            // Lookup explícito por team_id -- no usar Role::firstOrCreate()
            // con el nombre solo, eso es justo la asunción de unicidad
            // global que RBAC v2 elimina (ver auditoría Paso 0).
            $role = Role::where('team_id', $teamId)
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if (! $role) {
                // Role::create() (override de Spatie) rechaza esto con
                // RoleAlreadyExists si existe una fila "global" preexistente
                // (team_id NULL) con el mismo nombre -- el caso real aquí:
                // la migración base sembró 'admin'/'super_admin' sin
                // team_id antes de que existiera RBAC v2. Role::query()->create()
                // evita ese chequeo (Spatie lo expone así a propósito) y
                // usa directamente nuestro lookup explícito de arriba, que
                // sí es team-aware.
                $role = Role::query()->create([
                    'team_id' => $teamId,
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'slug' => \Illuminate\Support\Str::slug($roleName),
                    'scope_archetype' => $def['archetype'],
                ]);
            } elseif ($role->scope_archetype !== $def['archetype']) {
                $role->update(['scope_archetype' => $def['archetype']]);
            }

            $role->syncPermissions($def['perms']);
        }
    }
}
