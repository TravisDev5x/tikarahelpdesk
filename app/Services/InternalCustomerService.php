<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenancy\PgsqlRowLevelSecurity;

/**
 * Customer implícito (Fase 2 del sprint maestro, jerarquía Client (tenant) ->
 * Customer (empresa soportada) -> Site): SIEMPRE existe un Customer con
 * is_internal=true que representa a la propia empresa del tenant. En
 * modalidad IT Internal es su único customer; en MSP/Hybrid convive con los
 * customers externos. Un solo modelo de datos para las 3 modalidades, sin
 * ramas de código.
 *
 * Reemplaza el diseño "Opción B" (clients.is_internal, ver
 * 2026_07_11_000009_revert_clients_is_internal): ahora el flag vive en
 * customers.is_internal, no en clients.
 *
 * Fase 7 (2026-08-09): ensureForClient() es el punto de entrada general,
 * enganchado desde Client::booted() para que CUALQUIER Client nuevo (sin
 * importar quién ni por qué camino lo crea) reciba su Customer implícito,
 * no solo el flujo legacy de operador. ensureFor(User) se conserva sin
 * cambios -- lo sigue usando BackfillInternalCustomers para retrofit de
 * operadores is_operator preexistentes -- y ahora delega en
 * ensureForClient() para no duplicar la creación del Customer.
 */
class InternalCustomerService
{
    /**
     * Garantiza el customer interno de un Client ya existente, Y su Site por
     * defecto (Fase 7.7, 2026-08-10 -- sin esto, un tenant Internal llegaba
     * a 7.7 del onboarding con cero sites, dejando ese paso como un no-op).
     * Idempotente en ambos: si el Customer ya existe no se toca, y si ya
     * tiene algún Site bajo él tampoco se crea uno nuevo -- así que
     * reinvocar este método sobre un Client viejo (creado antes de este
     * cambio) rellena el Site que le faltaba sin duplicar nada, sin
     * necesitar un comando de backfill aparte.
     */
    public function ensureForClient(Client $client): Customer
    {
        // customers/sites tienen RLS (2026_07_11_000007): quien crea un
        // Client nuevo casi nunca tiene todavía, en la sesión de BD actual,
        // el client_id que este Customer/Site necesitan para pasar la
        // policy (caso típico: el Client se está creando recién en este
        // mismo request). Bypass acotado solo a este bloque, con
        // restauración del valor previo -- ver PgsqlRowLevelSecurity::withBypass().
        return PgsqlRowLevelSecurity::withBypass(function () use ($client) {
            $customer = Customer::where('client_id', $client->id)
                ->where('is_internal', true)
                ->first();

            if (! $customer) {
                $customer = Customer::create([
                    'client_id' => $client->id,
                    'name' => $client->name,
                    'is_internal' => true,
                ]);
            }

            if (! Site::where('customer_id', $customer->id)->exists()) {
                // "Oficina principal": nombre genérico de SEDE, distinto del
                // nombre del tenant (que ya identifica a la EMPRESA vía
                // $customer->name) -- un Site es una ubicación física, no la
                // empresa misma. Evita además que este default choque por
                // accidente con sites.unique(client_id, name) si más
                // adelante alguien nombra una sede externa igual que su
                // propia empresa.
                Site::create([
                    'client_id' => $client->id,
                    'customer_id' => $customer->id,
                    'name' => 'Oficina principal',
                    'address' => $client->address,
                    'type' => 'physical',
                    'is_active' => true,
                ]);
            }

            return $customer;
        });
    }

    /**
     * Garantiza el customer interno del operador -- crea el Client si el
     * operador todavía no tiene uno propio. Solo usado hoy por
     * BackfillInternalCustomers (retrofit de operadores legacy); el flujo
     * de onboarding nuevo crea el Client explícitamente y llama
     * ensureForClient() vía el hook de Client::booted().
     */
    public function ensureFor(User $operator, ?string $businessName = null): Customer
    {
        $existing = Customer::where('is_internal', true)
            ->whereHas('client', fn ($q) => $q->where('operator_user_id', $operator->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        $name = $businessName
            ?: $operator->operatorProfile?->business_name
            ?: trim($operator->first_name.' '.$operator->paternal_last_name);

        $client = Client::create([
            'operator_user_id' => $operator->id,
            'name' => $name,
            'is_active' => true,
        ]);

        return $this->ensureForClient($client);
    }
}
