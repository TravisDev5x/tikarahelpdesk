<?php

namespace Tests\Feature;

use App\Models\InvCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Port de HelpdeskECD2026 a Tikara, fase 1 (catálogos de Inventario).
 * Mismo caso que CatalogApiOperatorScopeTest, adaptado a inv_categories /
 * inventory.manage_config.
 */
class InventoryCatalogApiScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_config', 'guard_name' => 'web']);
    }

    public function test_inv_categories_index_excludes_other_operator_rows(): void
    {
        if (! \Schema::hasColumn('inv_categories', 'operator_user_id')) {
            $this->markTestSkipped('Migración de catálogos de inventario no aplicada.');
        }

        $operatorA = $this->bareUser(['is_operator' => true]);
        $operatorB = $this->bareUser(['is_operator' => true, 'email' => 'op-b-inv@test.local']);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $operatorA->givePermissionTo('inventory.manage_config');

        InvCategory::create(['name' => 'Global category', 'is_active' => true, 'operator_user_id' => null]);
        InvCategory::create(['name' => 'Own category', 'is_active' => true, 'operator_user_id' => $operatorA->id]);
        InvCategory::create(['name' => 'Foreign category', 'is_active' => true, 'operator_user_id' => $operatorB->id]);

        $response = $this->actingAs($operatorA, 'web')->getJson('/api/inv-categories');

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Global category'));
        $this->assertTrue($names->contains('Own category'));
        $this->assertFalse($names->contains('Foreign category'));
    }

    public function test_update_foreign_inv_category_returns_403(): void
    {
        if (! \Schema::hasColumn('inv_categories', 'operator_user_id')) {
            $this->markTestSkipped('Migración de catálogos de inventario no aplicada.');
        }

        $operatorA = $this->bareUser(['is_operator' => true]);
        $operatorB = $this->bareUser(['is_operator' => true, 'email' => 'op-c-inv@test.local']);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $operatorA->givePermissionTo('inventory.manage_config');

        $foreign = InvCategory::create(['name' => 'Ajena', 'is_active' => true, 'operator_user_id' => $operatorB->id]);

        $response = $this->actingAs($operatorA, 'web')->putJson("/api/inv-categories/{$foreign->id}", [
            'name' => 'Hackeada',
            'is_active' => true,
        ]);

        $response->assertForbidden();
    }

    public function test_inventory_config_pages_render_for_admin(): void
    {
        $admin = $this->bareUser(['is_operator' => true, 'onboarding_completed' => true]);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_config');

        foreach ([
            '/inventory/categories',
            '/inventory/statuses',
            '/inventory/labels',
            '/inventory/maintenance-origins',
            '/inventory/maintenance-modalities',
        ] as $path) {
            $response = $this->actingAs($admin, 'web')->get($path);
            $response->assertOk();
        }
    }

    public function test_inventory_config_pages_forbidden_without_permission(): void
    {
        // EnsurePermissionOrAdmin (alias 'perm') deja pasar al primer
        // usuario del sistema y a cualquiera mientras no exista ninguna
        // asignación de rol (ventana de arranque) -- para probar el 403 de
        // verdad hace falta un usuario que NO sea el primero y que además
        // tenga un rol (sin el permiso) asignado.
        $this->bareUser(['onboarding_completed' => true]); // ocupa el id=1, activa el bypass de "primer usuario" para el siguiente
        $user = $this->bareUser(['onboarding_completed' => true]);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $roleName = 'sin-permiso-'.uniqid();
        $role = \App\Models\Role::query()->create([
            'team_id' => config('tenancy.super_admin_team_id'),
            'name' => $roleName,
            'guard_name' => 'web',
            'slug' => \Illuminate\Support\Str::slug($roleName),
        ]);
        $user->assignRole($role);

        $response = $this->actingAs($user, 'web')->get('/inventory/categories');

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
