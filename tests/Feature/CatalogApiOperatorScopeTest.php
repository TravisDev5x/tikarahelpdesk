<?php

namespace Tests\Feature;

use App\Models\TicketState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogApiOperatorScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'catalogs.manage', 'guard_name' => 'web']);
    }

    public function test_ticket_states_index_excludes_other_operator_rows(): void
    {
        if (! \Schema::hasColumn('ticket_states', 'operator_user_id')) {
            $this->markTestSkipped('Migración catálogos por operador no aplicada.');
        }

        $operatorA = $this->bareUser(['is_operator' => true]);
        $operatorB = $this->bareUser(['is_operator' => true, 'email' => 'op-b-catalog@test.local']);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $operatorA->givePermissionTo('catalogs.manage');

        TicketState::create([
            'name' => 'Global state',
            'code' => 'global',
            'is_active' => true,
            'is_final' => false,
            'operator_user_id' => null,
        ]);
        TicketState::create([
            'name' => 'Own state',
            'code' => 'own',
            'is_active' => true,
            'is_final' => false,
            'operator_user_id' => $operatorA->id,
        ]);
        TicketState::create([
            'name' => 'Foreign state',
            'code' => 'foreign',
            'is_active' => true,
            'is_final' => false,
            'operator_user_id' => $operatorB->id,
        ]);

        $response = $this->actingAs($operatorA, 'web')->getJson('/api/ticket-states');

        $response->assertOk();
        $codes = collect($response->json())->pluck('code');
        $this->assertTrue($codes->contains('global'));
        $this->assertTrue($codes->contains('own'));
        $this->assertFalse($codes->contains('foreign'));
    }

    public function test_update_foreign_ticket_state_returns_403(): void
    {
        if (! \Schema::hasColumn('ticket_states', 'operator_user_id')) {
            $this->markTestSkipped('Migración catálogos por operador no aplicada.');
        }

        $operatorA = $this->bareUser(['is_operator' => true]);
        $operatorB = $this->bareUser(['is_operator' => true, 'email' => 'op-c-catalog@test.local']);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $operatorA->givePermissionTo('catalogs.manage');

        $foreign = TicketState::create([
            'name' => 'Ajeno',
            'code' => 'ajeno',
            'is_active' => true,
            'is_final' => false,
            'operator_user_id' => $operatorB->id,
        ]);

        $response = $this->actingAs($operatorA, 'web')->putJson("/api/ticket-states/{$foreign->id}", [
            'name' => 'Hackeado',
            'code' => 'hack',
            'is_active' => true,
            'is_final' => false,
        ]);

        $response->assertForbidden();
    }

    private function bareUser(array $overrides = []): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $siteId = DB::table('sites')->insertGetId([
            'name' => 'S'.uniqid(), 'code' => 'X'.uniqid(), 'type' => 'physical',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::create(array_merge([
            'first_name' => 'T', 'paternal_last_name' => 'U',
            'email' => uniqid().'@t.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => $siteId, 'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }
}
