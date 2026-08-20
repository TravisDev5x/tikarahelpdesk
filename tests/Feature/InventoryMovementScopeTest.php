<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Port de HelpdeskECD2026 a Tikara, fase 3 (ciclo de vida: asignar/
 * devolver/trasladar/dar de baja).
 */
class InventoryMovementScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
    }

    public function test_checkout_assigns_user_and_logs_movement(): void
    {
        if (! \Schema::hasTable('inv_movements')) {
            $this->markTestSkipped('Migración de inv_movements no aplicada.');
        }

        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $employee = $this->clientUser($client->id, $site);
        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $asset = InvAsset::create([
            'internal_tag' => 'TAG-1', 'name' => 'Laptop 1',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/checkout", [
            'user_id' => $employee->id,
        ]);

        $response->assertCreated();
        $this->assertSame($employee->id, $asset->fresh()->current_user_id);
        $this->assertDatabaseHas('inv_movements', [
            'asset_id' => $asset->id, 'type' => 'CHECKOUT', 'user_id' => $employee->id, 'admin_id' => $admin->id,
        ]);
    }

    public function test_checkout_rejected_when_status_not_assignable(): void
    {
        if (! \Schema::hasTable('inv_movements')) {
            $this->markTestSkipped('Migración de inv_movements no aplicada.');
        }

        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $employee = $this->clientUser($client->id, $site);
        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Baja', 'assignable' => false, 'is_active' => true]);
        $asset = InvAsset::create([
            'internal_tag' => 'TAG-2', 'name' => 'Laptop 2',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/checkout", [
            'user_id' => $employee->id,
        ]);

        $response->assertStatus(422);
        $this->assertNull($asset->fresh()->current_user_id);
    }

    public function test_checkin_clears_current_user(): void
    {
        if (! \Schema::hasTable('inv_movements')) {
            $this->markTestSkipped('Migración de inv_movements no aplicada.');
        }

        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $employee = $this->clientUser($client->id, $site);
        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $asset = InvAsset::create([
            'internal_tag' => 'TAG-3', 'name' => 'Laptop 3',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id, 'current_user_id' => $employee->id,
        ]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/checkin");

        $response->assertCreated();
        $this->assertNull($asset->fresh()->current_user_id);
        $this->assertDatabaseHas('inv_movements', [
            'asset_id' => $asset->id, 'type' => 'CHECKIN', 'user_id' => $employee->id,
        ]);
    }

    public function test_transfer_rejects_site_from_another_client(): void
    {
        if (! \Schema::hasTable('inv_movements')) {
            $this->markTestSkipped('Migración de inv_movements no aplicada.');
        }

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $siteA = $this->makeSite($clientA->id);
        $siteB = $this->makeSite($clientB->id);
        $admin = $this->clientUser($clientA->id, $siteA);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $asset = InvAsset::create([
            'internal_tag' => 'TAG-4', 'name' => 'Laptop 4',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $siteA, 'client_id' => $clientA->id,
        ]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/transfer", [
            'site_id' => $siteB,
        ]);

        $response->assertStatus(422);
        $this->assertSame($siteA, $asset->fresh()->site_id);
    }

    public function test_retire_requires_non_assignable_status_and_reason(): void
    {
        if (! \Schema::hasTable('inv_movements')) {
            $this->markTestSkipped('Migración de inv_movements no aplicada.');
        }

        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $available = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $retired = InvStatus::create(['name' => 'Dada de baja', 'assignable' => false, 'is_active' => true]);
        $employee = $this->clientUser($client->id, $site);
        $asset = InvAsset::create([
            'internal_tag' => 'TAG-5', 'name' => 'Laptop 5',
            'category_id' => $category->id, 'status_id' => $available->id,
            'site_id' => $site, 'client_id' => $client->id, 'current_user_id' => $employee->id,
        ]);

        // Rechaza si el estatus destino sigue siendo asignable.
        $bad = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/retire", [
            'status_id' => $available->id, 'reason' => 'Dañado',
        ]);
        $bad->assertStatus(422);

        // Con estatus no asignable + motivo, sí procede.
        $ok = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/retire", [
            'status_id' => $retired->id, 'reason' => 'Dañado sin reparación viable',
        ]);
        $ok->assertCreated();
        $fresh = $asset->fresh();
        $this->assertSame($retired->id, $fresh->status_id);
        $this->assertNull($fresh->current_user_id);
        $this->assertDatabaseHas('inv_movements', [
            'asset_id' => $asset->id, 'type' => 'BAJA', 'reason' => 'Dañado sin reparación viable',
        ]);
    }

    private function makeSite(int $clientId): int
    {
        $now = now();

        return DB::table('sites')->insertGetId([
            'client_id' => $clientId,
            'name' => 'S'.uniqid(),
            'code' => 'X'.uniqid(),
            'type' => 'physical',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function clientUser(int $clientId, int $siteId): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'T', 'paternal_last_name' => 'U',
            'email' => uniqid().'@t.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => $siteId,
            'client_id' => $clientId, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }
}
