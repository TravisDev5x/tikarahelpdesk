<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditoría de rendimiento 2026-08-10: TicketAnalyticsController calculaba
 * $cacheKey y hacía Cache::put() al final, pero nunca leía el cache antes de
 * recalcular -- se escribía y nunca se usaba. Medido con DB::listen() contra
 * datos reales: 24 queries en frío, 17 en una repetición INMEDIATA con los
 * mismos filtros (debieron ser ~0). Este test reproduce esa medición como
 * regresión.
 */
class TicketAnalyticsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeating_the_same_request_hits_the_cache_instead_of_recomputing(): void
    {
        [$user] = $this->makeTenantWithTickets(6);

        $queriesFirst = $this->countQueriesDuring(
            fn () => $this->actingAs($user, 'web')->getJson('/api/tickets/analytics')->assertOk()
        );

        $queriesSecond = $this->countQueriesDuring(
            fn () => $this->actingAs($user, 'web')->getJson('/api/tickets/analytics')->assertOk()
        );

        $this->assertGreaterThan(10, $queriesFirst, 'La primera llamada (cache frío) debía correr la agregación completa.');
        // No comparamos contra un número fijo -- ambas llamadas pagan el
        // mismo overhead de auth/middleware (sesión, permisos, RLS), así
        // que el número absoluto de la 2a nunca baja a 0. Lo que prueba que
        // el cache SÍ se está usando es que caiga drásticamente frente a la
        // 1a, no un piso arbitrario.
        $this->assertLessThan(
            (int) ($queriesFirst / 2),
            $queriesSecond,
            "La segunda llamada con los MISMOS filtros debía servirse del cache (tuvo {$queriesFirst} la 1a, {$queriesSecond} la 2a) -- no recalcular las ~15-24 queries de agregación."
        );
    }

    public function test_changing_filters_still_recomputes_instead_of_reusing_a_stale_cache_entry(): void
    {
        [$user, $client] = $this->makeTenantWithTickets(4);

        $this->actingAs($user, 'web')->getJson('/api/tickets/analytics')->assertOk();

        $queriesDifferentFilter = $this->countQueriesDuring(
            fn () => $this->actingAs($user, 'web')
                ->getJson('/api/tickets/analytics?created_by=me')
                ->assertOk()
        );

        $this->assertGreaterThan(10, $queriesDifferentFilter, 'Un filtro distinto es una cache key distinta -- debe recalcular, no reusar el cache de otro filtro.');
    }

    /** @return array{0: User, 1: Client} */
    private function makeTenantWithTickets(int $count): array
    {
        $operator = $this->makeUser('operator-'.uniqid().'@test.local');
        $client = Client::create(['name' => 'Tenant Analytics Cache', 'operator_user_id' => $operator->id, 'is_active' => true]);

        // 'admin' a secas colisiona con el role global (team_id NULL) que
        // ya siembra la migración base -- Role::create() (override de
        // Spatie) valida unicidad de (name, guard_name) sin filtrar por
        // team_id. Mismo motivo por el que los tests hermanos de este
        // archivo (TicketAnalyticsTenantIsolationTest,
        // ResolbebDashboardSiteFilterTest) usan un nombre que no colisiona.
        setPermissionsTeamId($client->id);
        Permission::firstOrCreate(['name' => 'tickets.manage_all', 'guard_name' => 'web']);
        $adminRole = Role::create([
            'name' => 'admin-analytics-cache-test', 'slug' => 'admin-analytics-cache-test', 'guard_name' => 'web',
            'team_id' => $client->id, 'scope_archetype' => 'admin',
        ]);
        $adminRole->givePermissionTo(['tickets.manage_all']);

        $admin = $this->makeUser('admin-'.uniqid().'@test.local', $client->id);
        $admin->assignRole($adminRole);

        $requester = $this->makeUser('requester-'.uniqid().'@test.local', $client->id);
        $catalog = $this->makeCatalog();
        $site = DB::table('sites')->insertGetId([
            'name' => 'Sede-'.uniqid(), 'client_id' => $client->id, 'type' => 'physical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < $count; $i++) {
            Ticket::create([
                'subject' => "Ticket {$i}",
                'folio' => 'CACHE-'.uniqid(),
                'area_origin_id' => $catalog['area_id'],
                'area_current_id' => $catalog['area_id'],
                'site_id' => $site,
                'client_id' => $client->id,
                'requester_id' => $requester->id,
                'ticket_type_id' => $catalog['ticket_type_id'],
                'priority_id' => $catalog['priority_id'],
                'ticket_state_id' => $catalog['ticket_state_id'],
            ]);
        }

        return [$admin, $client];
    }

    private function countQueriesDuring(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $count;
    }

    private function makeUser(string $email, ?int $clientId = null): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'client_id' => $clientId,
            'status' => 'active', 'onboarding_completed' => true,
        ]);
    }

    private function makeCatalog(): array
    {
        $now = now();

        return [
            'area_id' => DB::table('areas')->insertGetId(['name' => 'Area'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'priority_id' => DB::table('priorities')->insertGetId(['name' => 'Prio'.uniqid(), 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_state_id' => DB::table('ticket_states')->insertGetId(['name' => 'Estado'.uniqid(), 'code' => 'st'.uniqid(), 'is_active' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_type_id' => DB::table('ticket_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
        ];
    }
}
