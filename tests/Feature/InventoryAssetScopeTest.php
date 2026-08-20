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
 * Port de HelpdeskECD2026 a Tikara, fase 2 (registro de activos). A
 * diferencia de InventoryCatalogApiScopeTest (fase 1, operador-scoped),
 * inv_assets es dato operativo scoped por client_id -- mismo criterio que
 * tickets/incidents.
 */
class InventoryAssetScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
    }

    public function test_inv_assets_index_excludes_other_client_rows(): void
    {
        if (! \Schema::hasTable('inv_assets')) {
            $this->markTestSkipped('Migración de inv_assets no aplicada.');
        }

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $siteA = $this->makeSite($clientA->id);
        $siteB = $this->makeSite($clientB->id);

        $userA = $this->clientUser($clientA->id, $siteA);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $userA->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'is_active' => true]);

        InvAsset::create($this->assetPayload($clientA->id, $siteA, $category->id, $status->id, 'Activo A'));
        InvAsset::create($this->assetPayload($clientB->id, $siteB, $category->id, $status->id, 'Activo B'));

        $response = $this->actingAs($userA, 'web')->getJson('/api/inv-assets');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Activo A'));
        $this->assertFalse($names->contains('Activo B'));
    }

    public function test_update_foreign_client_asset_returns_403(): void
    {
        if (! \Schema::hasTable('inv_assets')) {
            $this->markTestSkipped('Migración de inv_assets no aplicada.');
        }

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $siteA = $this->makeSite($clientA->id);
        $siteB = $this->makeSite($clientB->id);

        $userA = $this->clientUser($clientA->id, $siteA);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $userA->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'is_active' => true]);

        $foreign = InvAsset::create($this->assetPayload($clientB->id, $siteB, $category->id, $status->id, 'Ajeno'));

        $response = $this->actingAs($userA, 'web')->putJson("/api/inv-assets/{$foreign->id}", [
            'internal_tag' => $foreign->internal_tag,
            'name' => 'Hackeado',
            'category_id' => $category->id,
            'status_id' => $status->id,
            'site_id' => $siteA,
        ]);

        $response->assertForbidden();
    }

    public function test_cannot_create_asset_on_foreign_site(): void
    {
        if (! \Schema::hasTable('inv_assets')) {
            $this->markTestSkipped('Migración de inv_assets no aplicada.');
        }

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $siteA = $this->makeSite($clientA->id);
        $siteB = $this->makeSite($clientB->id);

        $userA = $this->clientUser($clientA->id, $siteA);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $userA->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'is_active' => true]);

        $response = $this->actingAs($userA, 'web')->postJson('/api/inv-assets', [
            'internal_tag' => 'LAP-999',
            'name' => 'Intento cross-tenant',
            'category_id' => $category->id,
            'status_id' => $status->id,
            'site_id' => $siteB, // sede de OTRO cliente
        ]);

        $response->assertStatus(422);
    }

    private function assetPayload(int $clientId, int $siteId, int $categoryId, int $statusId, string $name): array
    {
        return [
            'internal_tag' => 'TAG-'.uniqid(),
            'name' => $name,
            'category_id' => $categoryId,
            'status_id' => $statusId,
            'site_id' => $siteId,
            'client_id' => $clientId,
        ];
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
