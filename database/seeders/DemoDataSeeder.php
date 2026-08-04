<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Campaign;
use App\Models\Incident;
use App\Models\IncidentSeverity;
use App\Models\IncidentStatus;
use App\Models\IncidentType;
use App\Models\Position;
use App\Models\Priority;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketState;
use App\Models\TicketType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    /**
     * Datos de prueba: catálogos + 300+ usuarios + tickets + incidencias.
     * Ejecutar: php artisan db:seed --class=DemoDataSeeder
     * (Recomendado después de migrate:fresh para base limpia)
     */
    public function run(): void
    {
        // RBAC v2 (Fase 6): mismo razonamiento que FullDemoSeeder::run().
        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        }

        $this->command->info('Iniciando datos de prueba (Faker + 300+ registros)...');

        \DB::transaction(function () {
            $this->seedCatalogs();
            $this->seedTicketCatalogs();
            $this->seedIncidentCatalogs();
            $users = $this->seedUsers();
            $this->seedTickets($users);
            $this->seedIncidents($users);
        });

        $this->command->info('DemoDataSeeder finalizado.');
    }

    private function seedCatalogs(): void
    {
        $this->command->info('  → Catálogos base (campañas, áreas, puestos, sedes, ubicaciones)...');

        $campaigns = ['Soporte Técnico', 'Ventas Globales', 'Atención a Clientes', 'Cobranza', 'Retención', 'Seguros', 'Operaciones'];
        foreach ($campaigns as $name) {
            Campaign::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        $areas = ['Soporte', 'Infraestructura', 'Telecomunicaciones', 'Sistemas / TI', 'Aplicaciones', 'Redes', 'Seguridad', 'Recursos Humanos', 'Operaciones', 'Calidad'];
        foreach ($areas as $name) {
            Area::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        $positions = ['Usuario Final', 'Soporte N1', 'Soporte N2', 'Infraestructura', 'Supervisor', 'Analista Apps', 'Analista Redes', 'Agente Telefónico', 'Team Leader', 'Gerente'];
        foreach ($positions as $name) {
            Position::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        $sedes = [
            ['name' => 'Sede Física A', 'code' => 'SFA', 'type' => 'physical'],
            ['name' => 'Sede Física B', 'code' => 'SFB', 'type' => 'physical'],
            ['name' => 'Remoto', 'code' => 'REMOTO', 'type' => 'virtual'],
        ];
        foreach ($sedes as $s) {
            Site::firstOrCreate(
                ['name' => $s['name']],
                ['code' => $s['code'], 'type' => $s['type'], 'is_active' => true]
            );
        }

        $sfa = Site::where('code', 'SFA')->first();
        $sfb = Site::where('code', 'SFB')->first();
        if ($sfa) {
            foreach (['Piso 1', 'Piso 2', 'Piso 3'] as $name) {
                Location::firstOrCreate(
                    ['site_id' => $sfa->id, 'name' => $name],
                    ['is_active' => true]
                );
            }
        }
        if ($sfb) {
            foreach (['Edificio Norte', 'Edificio Sur'] as $name) {
                Location::firstOrCreate(
                    ['site_id' => $sfb->id, 'name' => $name],
                    ['is_active' => true]
                );
            }
        }
    }

    private function seedTicketCatalogs(): void
    {
        $this->command->info('  → Catálogos de tickets (prioridades, estados, tipos)...');

        foreach ([['name' => 'Crítica', 'level' => 1], ['name' => 'Alta', 'level' => 2], ['name' => 'Media', 'level' => 3], ['name' => 'Baja', 'level' => 4]] as $p) {
            Priority::firstOrCreate(['name' => $p['name']], ['level' => $p['level'], 'is_active' => true]);
        }

        foreach (['Abierto', 'En progreso', 'En espera', 'Resuelto', 'Cerrado', 'Cancelado'] as $name) {
            TicketState::firstOrCreate(
                ['name' => $name],
                ['code' => Str::slug($name, '_'), 'is_active' => true, 'is_final' => in_array($name, ['Cerrado', 'Cancelado'])]
            );
        }

        $types = [
            'Falla de red' => ['Infraestructura', 'Telecomunicaciones', 'Redes'],
            'Falla de equipo' => ['Soporte'],
            'Acceso a sistema' => ['Sistemas / TI', 'Soporte', 'Aplicaciones'],
            'Solicitud de software' => ['Sistemas / TI', 'Aplicaciones'],
            'Incidente de seguridad' => ['Seguridad'],
            'VPN / Acceso remoto' => ['Redes', 'Seguridad'],
        ];
        foreach ($types as $typeName => $areaNames) {
            $type = TicketType::firstOrCreate(
                ['name' => $typeName],
                ['code' => Str::slug($typeName, '_'), 'is_active' => true]
            );
            $areaIds = Area::whereIn('name', $areaNames)->pluck('id')->all();
            $type->areas()->sync($areaIds);
        }
    }

    private function seedIncidentCatalogs(): void
    {
        $this->command->info('  → Catálogos de incidencias (tipos, severidades, estados)...');

        foreach (['Falla de servicio', 'Seguridad', 'Hardware', 'Software', 'Acceso', 'Otro'] as $name) {
            IncidentType::firstOrCreate(
                ['name' => $name],
                ['code' => Str::slug($name, '_'), 'is_active' => true]
            );
        }

        foreach ([['name' => 'Crítica', 'code' => 'P1', 'level' => 1], ['name' => 'Alta', 'code' => 'P2', 'level' => 2], ['name' => 'Media', 'code' => 'P3', 'level' => 3], ['name' => 'Baja', 'code' => 'P4', 'level' => 4]] as $s) {
            IncidentSeverity::firstOrCreate(
                ['name' => $s['name']],
                ['code' => $s['code'], 'level' => $s['level'], 'is_active' => true]
            );
        }

        foreach (['Reportado', 'En investigación', 'En progreso', 'Resuelto', 'Cerrado'] as $name) {
            IncidentStatus::firstOrCreate(
                ['name' => $name],
                ['code' => Str::slug($name, '_'), 'is_active' => true, 'is_final' => $name === 'Cerrado']
            );
        }
    }

    private function seedUsers(): array
    {
        $this->command->info('  → Creando 320 usuarios (Faker)...');

        $count = User::count();
        $toCreate = max(0, 320 - $count);
        if ($toCreate > 0) {
            User::factory($toCreate)->create();
        }
        $users = User::all();
        if ($users->isEmpty()) {
            $this->command->warn('No hay usuarios en la base. Crea al menos uno (ej. AdminUserSeeder) antes.');
            return [];
        }

        $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $adminUser = User::where('email', 'admin@helpdesk.local')->orWhere('employee_number', 'ADMIN001')->first();
            if ($adminUser) {
                $adminUser->syncRoles(['admin']);
            } else {
                $first = $users->first();
                if (!$first->hasRole('admin')) {
                    $first->syncRoles(['admin']);
                }
            }
        }

        return $users->all();
    }

    private function seedTickets(array $users): void
    {
        if (empty($users)) {
            return;
        }
        $this->command->info('  → Creando ~220 tickets de ejemplo...');

        $areas = Area::all();
        $sedes = Site::all();
        $priorities = Priority::all();
        $states = TicketState::all();
        $types = TicketType::all();

        $subjects = [
            'Equipo no enciende', 'Pantalla en negro', 'Sin acceso a red', 'Impresora no responde',
            'Solicitud de acceso a carpeta', 'Error al iniciar sesión', 'VPN desconectada',
            'Software no instalado', 'Actualización pendiente', 'Correo no llega',
            'Internet lento', 'Teclado falla', 'Sistema bloqueado', 'Permisos insuficientes',
        ];

        $existingTickets = Ticket::count();
        $toCreate = max(0, 220 - $existingTickets);

        for ($i = 0; $i < $toCreate; $i++) {
            $requester = $users[array_rand($users)];
            $area = $areas->random();
            $sede = $sedes->random();
            $ubicacionesSede = Location::where('site_id', $sede->id)->get();
            $ubicacion = $ubicacionesSede->isNotEmpty() ? $ubicacionesSede->random() : null;

            Ticket::create([
                'subject' => $subjects[array_rand($subjects)] . ' #' . fake()->numberBetween(1, 999),
                'description' => fake()->optional(0.85)->paragraphs(2, true),
                'area_origin_id' => $area->id,
                'area_current_id' => $area->id,
                'site_id' => $sede->id,
                'location_id' => $ubicacion?->id,
                'requester_id' => $requester->id,
                'requester_position_id' => $requester->position_id,
                'ticket_type_id' => $types->random()->id,
                'priority_id' => $priorities->random()->id,
                'ticket_state_id' => $states->random()->id,
                'assigned_user_id' => fake()->boolean(0.4) ? $users[array_rand($users)]->id : null,
                'assigned_at' => fake()->boolean(0.4) ? fake()->dateTimeBetween('-2 weeks') : null,
                'resolved_at' => fake()->boolean(0.25) ? fake()->dateTimeBetween('-1 week') : null,
            ]);
        }
    }

    private function seedIncidents(array $users): void
    {
        if (empty($users)) {
            return;
        }
        $this->command->info('  → Creando ~90 incidencias de ejemplo...');

        $areas = Area::all();
        $sedes = Site::all();
        $severities = IncidentSeverity::all();
        $statuses = IncidentStatus::all();
        $types = IncidentType::all();

        $subjects = [
            'Caída de servidor', 'Intento de acceso no autorizado', 'Falla en disco',
            'Corte de conectividad', 'Malware detectado', 'Sobrecarga de sistema',
            'Error en aplicación', 'Pérdida de datos parcial', 'Interrupción de servicio',
        ];

        $existing = Incident::count();
        $toCreate = max(0, 90 - $existing);

        for ($i = 0; $i < $toCreate; $i++) {
            $reporter = $users[array_rand($users)];
            $area = $areas->random();
            $sede = $sedes->random();
            $occurred = fake()->dateTimeBetween('-3 months', '-1 day');
            $status = $statuses->random();

            Incident::create([
                'subject' => $subjects[array_rand($subjects)] . ' - ' . fake()->city(),
                'description' => fake()->optional(0.8)->paragraphs(2, true),
                'occurred_at' => $occurred,
                'enabled_at' => $occurred,
                'reporter_id' => $reporter->id,
                'involved_user_id' => fake()->boolean(0.3) ? $users[array_rand($users)]->id : null,
                'assigned_user_id' => fake()->boolean(0.5) ? $users[array_rand($users)]->id : null,
                'area_id' => $area->id,
                'site_id' => $sede->id,
                'incident_type_id' => $types->random()->id,
                'incident_severity_id' => $severities->random()->id,
                'incident_status_id' => $status->id,
                'closed_at' => $status->is_final ? fake()->dateTimeBetween($occurred, 'now') : null,
            ]);
        }
    }
}
