<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Campaign;
use App\Models\Position;
use App\Models\Priority;
use App\Models\Role;
use App\Models\Site;
use App\Models\TicketState;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed mínimo para ambiente real:
 * - Catálogos base (campañas, áreas, puestos, sedes, estados y prioridades).
 * - 4 roles: admin, soporte, usuario, consultor.
 * - 1 usuario administrador.
 * No genera usuarios demo masivos ni tickets de ejemplo.
 */
class FullDemoSeeder extends Seeder
{
    public function run(): void
    {
        // RBAC v2 (Fase 6): este seeder es previo a multi-tenancy -- no
        // crea ningún Client, siembra roles "planos" (admin/soporte/
        // usuario/consultor/super_admin) sin dueño de tenant. Sin un
        // team_id, cualquier assignRole()/syncRoles() de aquí en adelante
        // fallaría (model_has_roles.team_id es NOT NULL). Si el llamador ya
        // fijó un team_id (ej. un test que sí tiene un Client real), se
        // respeta -- solo cae al centinela de plataforma si nadie fijó
        // nada, no lo pisa incondicionalmente.
        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        }

        $this->command->info('FullDemoSeeder (mínimo): catálogos básicos, roles y usuario admin.');

        DB::transaction(function () {
            $this->seedCatalogs();
            $this->seedRolesAndPermissions();
            $this->seedAdminUser();
        });

        $this->command->info('FullDemoSeeder finalizado.');
    }

    private function seedCatalogs(): void
    {
        // Campaña general
        Campaign::firstOrCreate(
            ['name' => 'General'],
            ['is_active' => true]
        );

        // Áreas mínimas
        foreach (['Sistemas / TI', 'Soporte', 'Operaciones'] as $name) {
            Area::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        // Puestos mínimos
        foreach (['Usuario Final', 'Soporte', 'Supervisor'] as $name) {
            Position::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        // 3 sedes solicitadas
        $sedes = [
            ['name' => 'Tlalpan', 'code' => 'TLALPAN', 'type' => 'physical'],
            ['name' => 'Vallejo', 'code' => 'VALLEJO', 'type' => 'physical'],
            ['name' => 'Toledo',  'code' => 'TOLEDO',  'type' => 'physical'],
        ];

        foreach ($sedes as $s) {
            Site::firstOrCreate(
                ['name' => $s['name']],
                ['code' => $s['code'], 'type' => $s['type'], 'is_active' => true]
            );
        }

        // Prioridades de ticket
        $priorities = [
            ['name' => 'Crítica', 'level' => 1],
            ['name' => 'Alta',    'level' => 2],
            ['name' => 'Media',   'level' => 3],
            ['name' => 'Baja',    'level' => 4],
        ];
        foreach ($priorities as $p) {
            Priority::firstOrCreate(
                ['name' => $p['name']],
                ['level' => $p['level'], 'is_active' => true]
            );
        }

        // Estados de ticket
        $states = [
            ['name' => 'Abierto',     'code' => 'abierto',     'is_final' => false],
            ['name' => 'En progreso', 'code' => 'en_progreso', 'is_final' => false],
            ['name' => 'En espera',   'code' => 'en_espera',   'is_final' => false],
            ['name' => 'Resuelto',    'code' => 'resuelto',    'is_final' => false],
            ['name' => 'Cerrado',     'code' => 'cerrado',     'is_final' => true],
            ['name' => 'Cancelado',   'code' => 'cancelado',   'is_final' => true],
            ['name' => 'Rechazado',   'code' => 'rechazado',   'is_final' => true],
        ];

        foreach ($states as $s) {
            TicketState::firstOrCreate(
                ['name' => $s['name']],
                ['code' => $s['code'], 'is_active' => true, 'is_final' => $s['is_final']]
            );
        }

        // Tipos de ticket básicos
        $typeAreas = [
            'Falla de equipo'     => ['Soporte'],
            'Acceso a sistema'    => ['Sistemas / TI', 'Soporte'],
            'Solicitud de cambio' => ['Sistemas / TI', 'Operaciones'],
        ];

        foreach ($typeAreas as $typeName => $areaNames) {
            $type = TicketType::firstOrCreate(
                ['name' => $typeName],
                ['code' => Str::slug($typeName, '_'), 'is_active' => true]
            );

            $areaIds = Area::whereIn('name', $areaNames)->pluck('id')->all();
            $type->areas()->sync($areaIds);
        }
    }

    private function seedRolesAndPermissions(): void
    {
        // Permisos de helpdesk (no SIGUA; SIGUA va en SiguaPermissionsSeeder)
        $permissions = [
            'tickets.create',
            'tickets.view_own',
            'tickets.view_area',
            'tickets.filter_by_site',
            'tickets.assign',
            'tickets.comment',
            'tickets.change_status',
            'tickets.escalate',
            'tickets.manage_all',
            'users.manage',
            'roles.manage',
            'permissions.manage',
            'catalogs.manage',
            'inventory.manage_config',
            'inventory.manage_assets',
            'notifications.manage',
            'incidents.create',
            'incidents.view_own',
            'incidents.view_area',
            'incidents.manage_all',
        ];

        foreach (['web', 'sanctum'] as $guard) {
            foreach ($permissions as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $allWebPermissions = DB::table('permissions')
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        $soportePerms = [
            'tickets.view_area',
            'tickets.comment',
            'tickets.change_status',
            'tickets.assign',
            'tickets.filter_by_site',
            'tickets.escalate',
        ];

        $supervisorPerms = array_values(array_unique(array_merge(
            $soportePerms,
            [
                'tickets.manage_all',
                'tickets.filter_by_site',
                'incidents.view_area',
                'incidents.manage_all',
            ]
        )));

        $roles = [
            // Admin: todos los permisos del núcleo helpdesk
            'admin' => $allWebPermissions,
            // Gerente / supervisor: todos los tickets + quién atiende
            'gerente' => $supervisorPerms,
            'supervisor' => $supervisorPerms,
            // Soporte L1–L3: cola de solicitantes en su ámbito
            'soporte' => $soportePerms,
            'soporte_n1' => $soportePerms,
            'soporte_n2' => $soportePerms,
            'soporte_n3' => $soportePerms,
            // Usuario solicitante
            'usuario' => [
                'tickets.create',
                'tickets.view_own',
            ],
            // Consultor: lectura ampliada (mismo panel que soporte)
            'consultor' => [
                'tickets.view_area',
                'tickets.view_own',
            ],
        ];

        foreach ($roles as $roleName => $permNames) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['slug' => $roleName]
            );
            $role->syncPermissions($permNames);
        }
    }

    private function seedAdminUser(): void
    {
        $email = 'admin@helpdesk.local';
        $password = 'AdminHelpdesk2025!';
        $employeeNumber = 'ADMIN001';

        $campaign = Campaign::first();
        $area = Area::where('name', 'Sistemas / TI')->first() ?: Area::first();
        $position = Position::where('name', 'Supervisor')->first() ?: Position::first();
        $sede = Site::where('name', 'Tlalpan')->first() ?: Site::first();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Administrador',
                'paternal_last_name' => 'Sistema',
                'maternal_last_name' => null,
                'employee_number' => $employeeNumber,
                'phone' => null,
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_operator' => true,
                'onboarding_completed' => true,
                'campaign_id' => $campaign?->id,
                'area_id' => $area?->id,
                'position_id' => $position?->id,
                'site_id' => $sede?->id,
            ]
        );

        // RBAC v2 (Fase 6): 'super_admin' vive SIEMPRE en el team_id
        // centinela de plataforma (nunca en el de un tenant real, ver
        // migración 2026_07_15_000003) -- si este seeder corre dentro del
        // contexto de un tenant real (alguien ya fijó setPermissionsTeamId()
        // antes de invocarlo, ej. un test de coexistencia con
        // TenantRoleSeeder), asignarle 'super_admin' al admin demo de ESE
        // tenant sería un bug de producto real (acceso cross-tenant no
        // solicitado), no solo un problema técnico -- se omite a propósito.
        $user->syncRoles(['admin']);
        if (getPermissionsTeamId() === config('tenancy.super_admin_team_id')) {
            $user->assignRole('super_admin');
        }

        if ($this->command) {
            $this->command->newLine();
            $this->command->line('--- Usuario administrador ---');
            $this->command->line('Correo: ' . $email);
            $this->command->line('Número de empleado: ' . $employeeNumber);
            $this->command->line('Contraseña: ' . $password);
            $this->command->line('----------------------------');
            $this->command->newLine();
        }
    }
}
