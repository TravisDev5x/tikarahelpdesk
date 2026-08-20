<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'type' => 'inhouse',
                'description' => 'Para empresas que gestionan su soporte interno',
                'price_monthly' => 199,
                'price_yearly' => 1990,
                'max_clients' => null,
                'max_users' => 50,
                'max_agents' => 3,
                'max_assets' => 100,
                'trial_days' => 14,
                'highlighted' => false,
                'is_active' => true,
                'is_public' => true,
                'features' => [
                    'Gestión de tickets internos',
                    'Portal para empleados',
                    'SLA básico',
                    'Hasta 3 agentes',
                    'Reportes básicos',
                    'Soporte por email',
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'type' => 'inhouse',
                'description' => 'Para empresas medianas con equipo IT propio',
                'price_monthly' => 499,
                'price_yearly' => 4990,
                'max_clients' => null,
                'max_users' => 200,
                'max_agents' => 10,
                'max_assets' => 500,
                'trial_days' => 14,
                'highlighted' => true,
                'is_active' => true,
                'is_public' => true,
                'features' => [
                    'Todo en Basic',
                    'SLA configurable',
                    'Base de conocimiento',
                    'Gestión de activos',
                    'Reportes avanzados',
                    'Integraciones',
                    'Soporte prioritario',
                ],
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'type' => 'msp',
                'description' => 'Para equipos MSP que empiezan a escalar',
                'price_monthly' => 299,
                'price_yearly' => 2990,
                'max_clients' => 3,
                'max_users' => 10,
                'max_agents' => 2,
                'max_assets' => 50,
                'trial_days' => 14,
                'highlighted' => false,
                'is_active' => true,
                'is_public' => true,
                'features' => [
                    'Hasta 3 clientes',
                    'Portal por cliente',
                    'Gestión de tickets',
                    'SLA básico',
                    'Despacho de técnicos',
                    'Soporte por email',
                ],
            ],
            [
                'name' => 'Growth',
                'slug' => 'growth',
                'type' => 'msp',
                'description' => 'Para empresas MSP en crecimiento',
                'price_monthly' => 799,
                'price_yearly' => 7990,
                'max_clients' => 15,
                'max_users' => 50,
                'max_agents' => 8,
                'max_assets' => 250,
                'trial_days' => 14,
                'highlighted' => true,
                'is_active' => true,
                'is_public' => true,
                'features' => [
                    'Todo en Starter',
                    'Hasta 15 clientes',
                    'Multi-sede',
                    'SLA configurable',
                    'Base de conocimiento',
                    'Reportes avanzados',
                    'Soporte prioritario',
                ],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'type' => 'both',
                'description' => 'Para operaciones grandes, MSP o In-House',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'max_clients' => null,
                'max_users' => null,
                'max_agents' => null,
                'max_assets' => null,
                'trial_days' => 0,
                'highlighted' => false,
                'is_active' => true,
                'is_public' => true,
                'features' => [
                    'Todo en Growth y Pro',
                    'Clientes ilimitados',
                    'Usuarios ilimitados',
                    'White-label',
                    'LDAP / Active Directory',
                    'API completa',
                    'SLA personalizado',
                    'Soporte dedicado',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
