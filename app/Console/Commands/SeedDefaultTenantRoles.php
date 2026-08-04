<?php

namespace App\Console\Commands;

use App\Models\Client;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Console\Command;

/**
 * RBAC v2 (Fase 6), Paso 2: punto de entrada real para sembrar las 4
 * plantillas por defecto (admin/supervisor/agente/solicitante) de un
 * tenant específico, dentro de su team_id. Reemplaza a la invocación
 * global de TenantRoleSeeder (que ya no tiene sentido bajo teams -- ver
 * migración 2026_07_15_000001).
 *
 * Idempotente: correrlo dos veces para el mismo tenant no duplica nada
 * (TenantRoleSeeder ya hace lookup explícito por team_id antes de crear).
 *
 * Es exactamente lo que Fase 7 (onboarding) va a invocar al completar el
 * alta de un tenant nuevo -- deliberadamente NO conectado a onboarding
 * todavía (no existe TenantOnboardingController), queda standalone y
 * probado por su cuenta.
 */
class SeedDefaultTenantRoles extends Command
{
    protected $signature = 'tenants:seed-default-roles {portal_slug : portal_slug del client a sembrar}';

    protected $description = 'Crea/actualiza las 4 plantillas por defecto de RBAC v2 para un tenant, dentro de su team_id';

    public function handle(): int
    {
        $slug = (string) $this->argument('portal_slug');

        $client = Client::where('portal_slug', $slug)->first();
        if (! $client) {
            $this->error("No existe ningún client con portal_slug '{$slug}'.");

            return self::FAILURE;
        }

        setPermissionsTeamId($client->id);

        try {
            (new TenantRoleSeeder)->run();
        } finally {
            setPermissionsTeamId(null);
        }

        $this->info("Listo: {$client->name} (portal_slug={$slug}, team_id={$client->id}).");

        return self::SUCCESS;
    }
}
