<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Campaign;
use App\Models\Area;
use App\Models\Position;
use App\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Crea un usuario con rol administrador para acceso al panel.
     * Ejecutar: php artisan db:seed --class=AdminUserSeeder
     */
    public function run(): void
    {
        // RBAC v2 (Fase 6): seeder plano sin concepto de Client/tenant --
        // ver el mismo razonamiento en FullDemoSeeder::run().
        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        }

        $email = 'admin@helpdesk.local';
        $password = 'AdminHelpdesk2025!';
        $employeeNumber = 'ADMIN001';

        $user = User::where('email', $email)->orWhere('employee_number', $employeeNumber)->first();
        if ($user) {
            $user->update(['password' => Hash::make($password)]);
            $user->syncRoles(['admin']);
            $this->command->info("Usuario admin ya existía. Contraseña actualizada.");
            $this->printCredentials($email, $password, $employeeNumber);
            return;
        }

        $sede = Site::where('code', 'REMOTO')->orWhere('name', 'Remoto')->first();
        if (!$sede) {
            $sede = Site::first();
        }
        if (!$sede) {
            $this->command->error('No hay ninguna sede. Ejecuta las migraciones primero.');
            return;
        }

        $campaign = Campaign::first();
        $area = Area::first();
        $position = Position::first();

        $user = User::create([
            'employee_number' => $employeeNumber,
            'first_name' => 'Administrador',
            'paternal_last_name' => 'Sistema',
            'maternal_last_name' => null,
            'email' => $email,
            'phone' => null,
            'password' => Hash::make($password),
            'status' => 'active',
            'email_verified_at' => now(),
            'site_id' => $sede->id,
            'campaign_id' => $campaign?->id,
            'area_id' => $area?->id,
            'position_id' => $position?->id,
        ]);

        $user->syncRoles(['admin']);

        $this->command->info('Usuario administrador creado correctamente.');
        $this->printCredentials($email, $password, $employeeNumber);
    }

    private function printCredentials(string $email, string $password, string $employeeNumber): void
    {
        $this->command->newLine();
        $this->command->line('--- Credenciales de administrador ---');
        $this->command->line('Correo o número de empleado: ' . $email . ' (o ' . $employeeNumber . ')');
        $this->command->line('Contraseña: ' . $password);
        $this->command->line('--------------------------------------');
        $this->command->newLine();
    }
}
