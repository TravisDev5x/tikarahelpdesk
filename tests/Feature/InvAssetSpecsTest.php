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
 * Auditoría de Inventario, fase 2.1 (ITAM -- ficha técnica estructurada).
 * inv_asset_specs (EAV) + App\Support\Inventory\AssetSpecSchema, en vez del
 * viejo inv_assets.specs = {notes: "texto libre"}.
 */
class InvAssetSpecsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
    }

    private function fixtures(string $categoryType = 'HARDWARE'): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'type' => $categoryType, 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);

        return compact('client', 'site', 'admin', 'category', 'status');
    }

    public function test_creating_an_asset_persists_valid_specs_for_its_category_type(): void
    {
        $fx = $this->fixtures('HARDWARE');

        $response = $this->actingAs($fx['admin'], 'web')->postJson('/api/inv-assets', [
            'internal_tag' => 'LAP-001', 'name' => 'Laptop Dell',
            'category_id' => $fx['category']->id, 'status_id' => $fx['status']->id, 'site_id' => $fx['site'],
            'specs' => [
                ['key' => 'cpu', 'value' => 'Intel i7-1265U'],
                ['key' => 'ram', 'value' => '16GB'],
            ],
        ]);

        $response->assertCreated();
        $assetId = $response->json('id');

        $this->assertDatabaseHas('inv_asset_specs', ['asset_id' => $assetId, 'key' => 'cpu', 'value' => 'Intel i7-1265U']);
        $this->assertDatabaseHas('inv_asset_specs', ['asset_id' => $assetId, 'key' => 'ram', 'value' => '16GB']);
    }

    public function test_keys_outside_the_category_type_schema_are_silently_discarded(): void
    {
        // CONSUMIBLE no tiene ningún campo en AssetSpecSchema -- cualquier
        // key que llegue debe descartarse, no rechazarse con 422.
        $fx = $this->fixtures('CONSUMIBLE');

        $response = $this->actingAs($fx['admin'], 'web')->postJson('/api/inv-assets', [
            'internal_tag' => 'CONS-001', 'name' => 'Cable HDMI',
            'category_id' => $fx['category']->id, 'status_id' => $fx['status']->id, 'site_id' => $fx['site'],
            'specs' => [['key' => 'cpu', 'value' => 'no debería guardarse']],
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('inv_asset_specs', ['asset_id' => $response->json('id'), 'key' => 'cpu']);
    }

    public function test_updating_an_asset_removes_specs_that_are_no_longer_sent(): void
    {
        $fx = $this->fixtures('HARDWARE');
        $asset = InvAsset::create([
            'internal_tag' => 'LAP-002', 'name' => 'Laptop HP',
            'category_id' => $fx['category']->id, 'status_id' => $fx['status']->id,
            'site_id' => $fx['site'], 'client_id' => $fx['client']->id,
        ]);
        $asset->specs()->create(['client_id' => $fx['client']->id, 'key' => 'cpu', 'value' => 'i5']);
        $asset->specs()->create(['client_id' => $fx['client']->id, 'key' => 'ram', 'value' => '8GB']);

        $response = $this->actingAs($fx['admin'], 'web')->putJson("/api/inv-assets/{$asset->id}", [
            'internal_tag' => $asset->internal_tag, 'name' => $asset->name,
            'category_id' => $fx['category']->id, 'status_id' => $fx['status']->id, 'site_id' => $fx['site'],
            'specs' => [['key' => 'ram', 'value' => '16GB']],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('inv_asset_specs', ['asset_id' => $asset->id, 'key' => 'cpu']);
        $this->assertDatabaseHas('inv_asset_specs', ['asset_id' => $asset->id, 'key' => 'ram', 'value' => '16GB']);
    }

    public function test_show_loads_specs_and_they_are_isolated_by_tenant(): void
    {
        $fxA = $this->fixtures('HARDWARE');
        $assetA = InvAsset::create([
            'internal_tag' => 'LAP-A', 'name' => 'Laptop A',
            'category_id' => $fxA['category']->id, 'status_id' => $fxA['status']->id,
            'site_id' => $fxA['site'], 'client_id' => $fxA['client']->id,
        ]);
        $assetA->specs()->create(['client_id' => $fxA['client']->id, 'key' => 'hostname', 'value' => 'PC-A']);

        $fxB = $this->fixtures('HARDWARE');

        $response = $this->actingAs($fxA['admin'], 'web')->getJson("/api/inv-assets/{$assetA->id}");
        $response->assertOk();
        $this->assertSame('hostname', $response->json('specs.0.key'));

        // Guard 'sanctum' cachea el usuario resuelto en la request anterior
        // -- un segundo actingAs() no basta por sí solo (footgun documentado
        // en CLAUDE.md), hay que forzar a que se re-resuelva.
        $this->app['auth']->forgetGuards();
        $foreign = $this->actingAs($fxB['admin'], 'web')->getJson("/api/inv-assets/{$assetA->id}");
        $foreign->assertForbidden();
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
