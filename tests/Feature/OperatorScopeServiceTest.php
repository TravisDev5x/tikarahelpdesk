<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\OperatorScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperatorScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    private OperatorScopeService $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = app(OperatorScopeService::class);
        Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
            ['slug' => 'super_admin']
        );
        Permission::firstOrCreate(['name' => 'clients.view_all', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.manage_all', 'guard_name' => 'web']);

        // RBAC v2 (Fase 6): este archivo llama assignRole()/givePermissionTo()
        // directo (sin pasar por HTTP/ApplyPgsqlTenantRls) sobre usuarios sin
        // client_id propio -- mismo centinela de plataforma que usa el hook
        // para ese caso.
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
    }

    public function test_operator_only_sees_own_clients(): void
    {
        $operatorA = $this->createUser(['is_operator' => true]);
        $operatorB = $this->createUser(['is_operator' => true, 'email' => 'op-b@test.local']);

        $clientA = Client::create(['name' => 'Cliente A', 'operator_user_id' => $operatorA->id, 'is_active' => true]);
        Client::create(['name' => 'Cliente B', 'operator_user_id' => $operatorB->id, 'is_active' => true]);

        $operatorA->givePermissionTo('clients.view_all');

        $ids = collect($this->scope->clientsForCatalog($operatorA))->pluck('id');

        $this->assertTrue($ids->contains($clientA->id));
        $this->assertSame(1, $ids->count());
    }

    public function test_manage_all_staff_sees_operator_clients_not_whole_platform(): void
    {
        $operatorA = $this->createUser(['is_operator' => true]);
        $operatorB = $this->createUser(['is_operator' => true, 'email' => 'op2@test.local']);

        Client::create(['name' => 'A1', 'operator_user_id' => $operatorA->id, 'is_active' => true]);
        Client::create(['name' => 'B1', 'operator_user_id' => $operatorB->id, 'is_active' => true]);

        $siteId = DB::table('sites')->insertGetId([
            'name' => 'Sede staff',
            'code' => 'STF',
            'type' => 'physical',
            'is_active' => true,
            'client_id' => Client::where('operator_user_id', $operatorA->id)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminStaff = $this->createUser([
            'email' => 'admin-staff@test.local',
            'site_id' => $siteId,
        ]);
        $adminStaff->givePermissionTo('tickets.manage_all');

        $this->assertSame(1, count($this->scope->clientsForCatalog($adminStaff)));
        $this->assertFalse($this->scope->bypassesOperatorScope($adminStaff));
        $this->assertTrue($this->scope->hasMspWideAccess($adminStaff));
    }

    public function test_legacy_admin_without_operator_link_sees_all_clients(): void
    {
        Client::create(['name' => 'Legacy Corp', 'is_active' => true]);
        Client::create(['name' => 'Other Corp', 'is_active' => true]);

        $legacyAdmin = $this->createUser(['email' => 'legacy-admin@test.local']);
        $legacyAdmin->givePermissionTo('tickets.manage_all');

        config([
            'tenancy.strict_client_portal' => true,
            'tenancy.legacy_msp_wide_access' => true,
        ]);

        $this->assertTrue($this->scope->usesLegacyMspWideAccess($legacyAdmin));
        $this->assertGreaterThanOrEqual(2, count($this->scope->clientsForCatalog($legacyAdmin, false)));
    }

    public function test_legacy_msp_wide_access_disabled_by_default(): void
    {
        Client::create(['name' => 'Legacy Corp', 'is_active' => true]);

        $legacyAdmin = $this->createUser(['email' => 'legacy-admin-off@test.local']);
        $legacyAdmin->givePermissionTo('tickets.manage_all');

        config([
            'tenancy.strict_client_portal' => true,
            'tenancy.legacy_msp_wide_access' => false,
        ]);

        $this->assertFalse($this->scope->usesLegacyMspWideAccess($legacyAdmin));
        $this->assertSame(0, count($this->scope->clientsForCatalog($legacyAdmin, false)));
    }

    public function test_promote_legacy_operators_command(): void
    {
        Client::create(['name' => 'Orphan Corp', 'is_active' => true]);

        $legacyAdmin = $this->createUser(['email' => 'promote-me@test.local']);
        $legacyAdmin->givePermissionTo('tickets.manage_all');

        $this->artisan('tenant:promote-legacy-operators')
            ->assertSuccessful()
            ->expectsOutputToContain('promote-me@test.local');

        $this->assertFalse($legacyAdmin->fresh()->is_operator);

        $this->artisan('tenant:promote-legacy-operators', [
            '--apply' => true,
            '--assign-orphan-clients' => true,
        ])->assertSuccessful();

        $legacyAdmin->refresh();
        $this->assertTrue($legacyAdmin->is_operator);
        $this->assertSame($legacyAdmin->id, (int) Client::where('name', 'Orphan Corp')->value('operator_user_id'));
    }

    public function test_super_admin_sees_all_clients(): void
    {
        $operatorA = $this->createUser(['is_operator' => true]);
        $operatorB = $this->createUser(['is_operator' => true, 'email' => 'op3@test.local']);

        Client::create(['name' => 'A1', 'operator_user_id' => $operatorA->id, 'is_active' => true]);
        Client::create(['name' => 'B1', 'operator_user_id' => $operatorB->id, 'is_active' => true]);

        $super = $this->createUser(['email' => 'super@test.local']);
        $super->assignRole('super_admin');

        $names = collect($this->scope->clientsForCatalog($super, false))->pluck('name');
        $this->assertTrue($names->contains('A1'));
        $this->assertTrue($names->contains('B1'));
        $this->assertTrue($this->scope->bypassesOperatorScope($super));
    }

    private function createUser(array $overrides = []): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId([
            'name' => 'Area '.uniqid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $positionId = DB::table('positions')->insertGetId([
            'name' => 'Puesto '.uniqid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = DB::table('sites')->insertGetId([
            'name' => 'Sede '.uniqid(),
            'code' => 'S'.random_int(1000, 9999),
            'type' => 'physical',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::create(array_merge([
            'first_name' => 'Test',
            'paternal_last_name' => 'User',
            'email' => 'user-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId,
            'position_id' => $positionId,
            'site_id' => $siteId,
            'status' => 'active',
            'is_operator' => false,
        ], $overrides));
    }
}
