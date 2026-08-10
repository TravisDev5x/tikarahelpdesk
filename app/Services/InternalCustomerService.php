<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Customer;
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
     * Garantiza el customer interno de un Client ya existente. Idempotente:
     * si ya tiene uno (is_internal=true), lo devuelve sin tocar nada.
     */
    public function ensureForClient(Client $client): Customer
    {
        $existing = Customer::where('client_id', $client->id)
            ->where('is_internal', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        // customers tiene RLS (2026_07_11_000007): quien crea un Client
        // nuevo casi nunca tiene todavía, en la sesión de BD actual, el
        // client_id que este mismo Customer necesita para pasar la policy
        // (es el caso típico: un Client se está creando recién en este mismo
        // request). Bypass acotado solo a este insert, con restauración del
        // valor previo -- ver PgsqlRowLevelSecurity::withBypass().
        return PgsqlRowLevelSecurity::withBypass(fn () => Customer::create([
            'client_id' => $client->id,
            'name' => $client->name,
            'is_internal' => true,
        ]));
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
