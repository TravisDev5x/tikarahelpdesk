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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeder con Faker: 1 admin con todos los permisos + al menos 50 usuarios,
 * 50 tickets y 50 incidencias de ejemplo.
 *
 * Compatible con el esquema actual (users, tickets, incidents, catálogos).
 * Ejecutar después de migraciones y seeders base, o usar:
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=FakerFullSeeder
 *
 * O integrar en DatabaseSeeder para tener todo en un solo comando.
 */
class FakerFullSeeder extends Seeder
{
    private const MIN_USERS = 50;
    private const MIN_TICKETS = 50;
    private const MIN_INCIDENTS = 50;

    private const ADMIN_EMAIL = 'admin@helpdesk.local';
    private const ADMIN_PASSWORD = 'AdminHelpdesk2025!';
    private const ADMIN_EMPLOYEE_NUMBER = 'ADMIN001';

    public function run(): void
    {
        // RBAC v2 (Fase 6): mismo razonamiento que FullDemoSeeder::run().
        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        }

        $this->command->info('FakerFullSeeder: admin con todos los permisos + 50+ usuarios, tickets e incidencias (Faker).');

        DB::transaction(function () {
            $this->ensureIncidentCatalogs();
            $this->ensureLocations();
            $this->seedAdminWithAllPermissions();
            $users = $this->seedUsers();
            $this->seedTickets($users);
            $this->seedIncidents($users);
        });

        $this->command->info('FakerFullSeeder finalizado.');
        $this->printAdminCredentials();
    }

    /**
     * Asegura catálogos de incidencias (tipos, severidades, estados) por si no existen.
     */
    private function ensureIncidentCatalogs(): void
    {
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

    /**
     * Crea ubicaciones por sede para que usuarios y tickets puedan asignarlas.
     */
    private function ensureLocations(): void
    {
        $sedes = Site::where('type', 'physical')->get();
        $names = ['Piso 1', 'Piso 2', 'Piso 3', 'Edificio A', 'Edificio B', 'Sala 101', 'Sala 102', 'Área abierta'];
        foreach ($sedes as $sede) {
            foreach (array_slice($names, 0, 3) as $name) {
                Location::firstOrCreate(
                    ['site_id' => $sede->id, 'name' => $sede->name . ' - ' . $name],
                    ['is_active' => true]
                );
            }
        }
    }

    /**
     * Crea o actualiza el usuario admin y asigna TODOS los permisos al rol admin.
     */
    private function seedAdminWithAllPermissions(): void
    {
        $campaign = Campaign::first();
        $area = Area::first();
        $position = Position::first();
        $sede = Site::first();

        if (!$campaign || !$area || !$position || !$sede) {
            $this->command->warn('Faltan catálogos base. Ejecuta antes: php artisan db:seed --class=FullDemoSeeder');
            return;
        }

        $user = User::where('email', self::ADMIN_EMAIL)
            ->orWhere('employee_number', self::ADMIN_EMPLOYEE_NUMBER)
            ->first();

        if (!$user) {
            $user = User::create([
                'employee_number' => self::ADMIN_EMPLOYEE_NUMBER,
                'first_name' => 'Administrador',
                'paternal_last_name' => 'Sistema',
                'maternal_last_name' => null,
                'email' => self::ADMIN_EMAIL,
                'phone' => null,
                'password' => Hash::make(self::ADMIN_PASSWORD),
                'status' => 'active',
                'email_verified_at' => now(),
                'site_id' => $sede->id,
                'campaign_id' => $campaign->id,
                'area_id' => $area->id,
                'position_id' => $position->id,
                'location_id' => Location::inRandomOrder()->first()?->id,
            ]);
        } else {
            $user->update(['password' => Hash::make(self::ADMIN_PASSWORD)]);
        }

        $user->syncRoles(['admin', 'super_admin']);

        // Asignar TODOS los permisos al rol admin (helpdesk + SIGUA + asistencias y cualquier otro)
        $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $allPerms = DB::table('permissions')->where('guard_name', 'web')->pluck('name')->all();
            $adminRole->syncPermissions($allPerms);
        }
    }

    private function seedUsers(): array
    {
        $this->command->info('  → Creando al menos ' . self::MIN_USERS . ' usuarios (Faker)...');

        $existing = User::count();
        $toCreate = max(0, self::MIN_USERS - $existing);

        if ($toCreate > 0) {
            User::factory($toCreate)->create();
        }

        $users = User::all();
        if ($users->isEmpty()) {
            $this->command->warn('No hay usuarios. Ejecuta antes los seeders base.');
            return [];
        }

        return $users->all();
    }

    private function seedTickets(array $users): void
    {
        if (empty($users)) {
            return;
        }

        $this->command->info('  → Creando al menos ' . self::MIN_TICKETS . ' tickets (Faker)...');

        $areas = Area::all();
        $sedes = Site::all();
        $priorities = Priority::all();
        $states = TicketState::all();
        $types = TicketType::all();

        if ($areas->isEmpty() || $sedes->isEmpty() || $priorities->isEmpty() || $states->isEmpty() || $types->isEmpty()) {
            $this->command->warn('Faltan catálogos de tickets.');
            return;
        }

        $subjects = [
            'Equipo no enciende', 'Pantalla en negro', 'Sin acceso a red', 'Impresora no responde',
            'Solicitud de acceso a carpeta', 'Error al iniciar sesión', 'VPN desconectada',
            'Software no instalado', 'Actualización pendiente', 'Correo no llega',
            'Internet lento', 'Teclado falla', 'Sistema bloqueado', 'Permisos insuficientes',
            'Mouse no responde', 'Falla de disco', 'Solicitud de equipo nuevo', 'Acceso a aplicación',
        ];

        $existing = Ticket::count();
        $toCreate = max(0, self::MIN_TICKETS - $existing);

        for ($i = 0; $i < $toCreate; $i++) {
            $requester = $users[array_rand($users)];
            $area = $areas->random();
            $sede = $sedes->random();
            $ubicaciones = Location::where('site_id', $sede->id)->get();
            $ubicacion = $ubicaciones->isNotEmpty() ? $ubicaciones->random() : null;

            Ticket::create([
                'subject' => fake()->randomElement($subjects) . ' #' . fake()->numberBetween(1, 9999),
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
                'due_at' => fake()->boolean(0.3) ? fake()->dateTimeBetween('now', '+2 weeks') : null,
            ]);
        }
    }

    private function seedIncidents(array $users): void
    {
        if (empty($users)) {
            return;
        }

        $this->command->info('  → Creando al menos ' . self::MIN_INCIDENTS . ' incidencias (Faker)...');

        $areas = Area::all();
        $sedes = Site::all();
        $severities = IncidentSeverity::all();
        $statuses = IncidentStatus::all();
        $types = IncidentType::all();

        if ($areas->isEmpty() || $sedes->isEmpty() || $severities->isEmpty() || $statuses->isEmpty() || $types->isEmpty()) {
            $this->command->warn('Faltan catálogos de incidencias.');
            return;
        }

        $subjects = [
            'Caída de servidor', 'Intento de acceso no autorizado', 'Falla en disco',
            'Corte de conectividad', 'Malware detectado', 'Sobrecarga de sistema',
            'Error en aplicación', 'Pérdida de datos parcial', 'Interrupción de servicio',
            'Falla de red en área', 'Acceso no autorizado a carpeta', 'Ransomware detectado',
        ];

        $existing = Incident::count();
        $toCreate = max(0, self::MIN_INCIDENTS - $existing);

        for ($i = 0; $i < $toCreate; $i++) {
            $reporter = $users[array_rand($users)];
            $area = $areas->random();
            $sede = $sedes->random();
            $occurred = fake()->dateTimeBetween('-3 months', '-1 day');
            $status = $statuses->random();

            Incident::create([
                'subject' => fake()->randomElement($subjects) . ' - ' . fake()->city(),
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

    private function printAdminCredentials(): void
    {
        $this->command->newLine();
        $this->command->line('--- Usuario administrador (todos los permisos) ---');
        $this->command->line('Correo o número de empleado: ' . self::ADMIN_EMAIL . ' (o ' . self::ADMIN_EMPLOYEE_NUMBER . ')');
        $this->command->line('Contraseña: ' . self::ADMIN_PASSWORD);
        $this->command->line('--------------------------------------');
        $this->command->newLine();
    }
}
