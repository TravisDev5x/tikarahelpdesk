<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Da de alta el rol nuevo (Fase 3) a cada usuario que hoy solo tiene el rol
 * legacy equivalente -- ADITIVO: nunca quita el rol legacy, solo asigna el
 * nuevo además. Retirar los roles legacy es un paso aparte, deliberadamente
 * fuera de este comando (mismo espíritu que los demás backfills del
 * sprint: nada destructivo sin confirmación explícita).
 *
 * admin y supervisor no aparecen en el mapa: son el mismo nombre de rol en
 * ambos esquemas (TenantRoleSeeder normaliza sus permisos in-place, no hay
 * nada que migrar). super_admin (rol de plataforma) y visitante (nunca
 * existió como rol real) tampoco se tocan aquí.
 *
 * RBAC v2 (Fase 6): ahora requiere portal_slug -- User::role($legacyRole) y
 * assignRole($newRole) están scoped por team_id (spatie/laravel-permission
 * teams), así que "migrar legacy roles" sin decir de qué tenant ya no tiene
 * sentido. Hallazgo nuevo durante Fase 6, fuera de los 9 puntos originales
 * del Paso 0 -- reportado explícitamente, no una redecisión en silencio.
 * Mismo patrón que tenants:seed-default-roles (Paso 2).
 */
class MigrateLegacyRoles extends Command
{
    protected $signature = 'roles:migrate-legacy {portal_slug} {--dry-run : Solo reporta, no escribe nada}';

    protected $description = 'Asigna (aditivo) el rol nuevo de Fase 3 a usuarios del tenant que solo tienen el legacy equivalente';

    private const MAP = [
        'gerente' => 'supervisor',
        'soporte' => 'agente',
        'soporte_n1' => 'agente',
        'soporte_n2' => 'agente',
        'soporte_n3' => 'agente',
        'usuario' => 'solicitante',
        'consultor' => 'agente',
    ];

    public function handle(): int
    {
        $slug = (string) $this->argument('portal_slug');
        $client = Client::where('portal_slug', $slug)->first();
        if (! $client) {
            $this->error("No existe ningún client con portal_slug '{$slug}'.");

            return self::FAILURE;
        }

        setPermissionsTeamId($client->id);

        $dryRun = (bool) $this->option('dry-run');
        $totalAssigned = 0;

        foreach (self::MAP as $legacyRole => $newRole) {
            $users = User::role($legacyRole)->get();

            if ($users->isEmpty()) {
                continue;
            }

            $toMigrate = $users->reject(fn (User $u) => $u->hasRole($newRole));

            if ($toMigrate->isEmpty()) {
                continue;
            }

            $this->info(($dryRun ? '[dry-run] ' : '')."{$legacyRole} -> {$newRole}: {$toMigrate->count()} usuario(s)");

            foreach ($toMigrate as $user) {
                $this->line("  #{$user->id} ({$user->email})");

                if (! $dryRun) {
                    $user->assignRole($newRole);
                }
            }

            $totalAssigned += $toMigrate->count();
        }

        if ($totalAssigned === 0) {
            $this->info('Nada que migrar -- todos los usuarios con rol legacy ya tienen su rol nuevo equivalente.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry-run: {$totalAssigned} asignación(es) pendiente(s). Vuelve a correr sin --dry-run para aplicar.");
        } else {
            $this->info("Listo: {$totalAssigned} usuario(s) recibieron su rol nuevo (el legacy NO se quitó).");
        }

        return self::SUCCESS;
    }
}
