<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvComponent;
use App\Models\InvStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Port de HelpdeskECD2026 a Tikara, fase 4 (componentes + despiece).
 */
class InventoryComponentScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
    }

    private function baseFixtures(): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $asset = InvAsset::create([
            'internal_tag' => 'TAG-'.uniqid(), 'name' => 'Laptop',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ]);

        return compact('client', 'site', 'admin', 'asset');
    }

    public function test_assign_loose_component_to_asset(): void
    {
        if (! \Schema::hasTable('inv_components')) {
            $this->markTestSkipped('Migración de inv_components no aplicada.');
        }

        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->baseFixtures();

        $component = InvComponent::create(['name' => 'RAM 16GB', 'client_id' => $client->id]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-components/{$component->id}/assign", [
            'asset_id' => $asset->id,
        ]);

        $response->assertCreated();
        $this->assertSame($asset->id, $component->fresh()->asset_id);
        $this->assertDatabaseHas('inv_component_movements', [
            'component_id' => $component->id, 'type' => 'ASIGNAR', 'asset_id' => $asset->id,
        ]);
    }

    public function test_unassign_clears_asset_and_sets_origin(): void
    {
        if (! \Schema::hasTable('inv_components')) {
            $this->markTestSkipped('Migración de inv_components no aplicada.');
        }

        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->baseFixtures();

        $component = InvComponent::create([
            'name' => 'RAM 16GB', 'client_id' => $client->id, 'asset_id' => $asset->id,
        ]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-components/{$component->id}/unassign");

        $response->assertCreated();
        $fresh = $component->fresh();
        $this->assertNull($fresh->asset_id);
        $this->assertSame($asset->id, $fresh->origin_asset_id);
        $this->assertDatabaseHas('inv_component_movements', [
            'component_id' => $component->id, 'type' => 'RETIRAR',
        ]);
    }

    public function test_retire_component_requires_reason(): void
    {
        if (! \Schema::hasTable('inv_components')) {
            $this->markTestSkipped('Migración de inv_components no aplicada.');
        }

        ['client' => $client, 'admin' => $admin] = $this->baseFixtures();
        $component = InvComponent::create(['name' => 'RAM 16GB', 'client_id' => $client->id]);

        $missing = $this->actingAs($admin, 'web')->postJson("/api/inv-components/{$component->id}/retire", []);
        $missing->assertStatus(422);

        $ok = $this->actingAs($admin, 'web')->postJson("/api/inv-components/{$component->id}/retire", [
            'reason' => 'Dañada',
        ]);
        $ok->assertCreated();
        $this->assertDatabaseHas('inv_component_movements', [
            'component_id' => $component->id, 'type' => 'BAJA', 'reason' => 'Dañada',
        ]);
    }

    public function test_disassemble_extracts_selected_components_only(): void
    {
        if (! \Schema::hasTable('inv_components')) {
            $this->markTestSkipped('Migración de inv_components no aplicada.');
        }

        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->baseFixtures();

        $ram = InvComponent::create(['name' => 'RAM 16GB', 'client_id' => $client->id, 'asset_id' => $asset->id]);
        $disk = InvComponent::create(['name' => 'SSD 512GB', 'client_id' => $client->id, 'asset_id' => $asset->id]);
        $kept = InvComponent::create(['name' => 'Teclado', 'client_id' => $client->id, 'asset_id' => $asset->id]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/disassemble", [
            'component_ids' => [$ram->id, $disk->id],
        ]);

        $response->assertCreated();
        $this->assertNull($ram->fresh()->asset_id);
        $this->assertSame($asset->id, $ram->fresh()->origin_asset_id);
        $this->assertNull($disk->fresh()->asset_id);
        $this->assertSame($asset->id, $kept->fresh()->asset_id, 'El componente no seleccionado no debe moverse.');
        $this->assertDatabaseHas('inv_component_movements', ['component_id' => $ram->id, 'type' => 'EXTRACCION']);
        $this->assertDatabaseHas('inv_component_movements', ['component_id' => $disk->id, 'type' => 'EXTRACCION']);
    }

    public function test_cannot_disassemble_component_from_another_asset(): void
    {
        if (! \Schema::hasTable('inv_components')) {
            $this->markTestSkipped('Migración de inv_components no aplicada.');
        }

        ['client' => $client, 'admin' => $admin, 'asset' => $assetA, 'site' => $site] = $this->baseFixtures();
        $category = InvCategory::first();
        $status = InvStatus::first();
        $assetB = InvAsset::create([
            'internal_tag' => 'TAG-B', 'name' => 'Laptop B',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ]);
        $foreignComponent = InvComponent::create(['name' => 'RAM ajena', 'client_id' => $client->id, 'asset_id' => $assetB->id]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$assetA->id}/disassemble", [
            'component_ids' => [$foreignComponent->id],
        ]);

        $response->assertStatus(422);
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
