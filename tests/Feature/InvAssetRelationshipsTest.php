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
 * Auditoría de Inventario, fase 3.2 (CMDB -- relaciones entre activos,
 * Sección I). Laptop+dock+monitor como activos independientes vinculados
 * -- no confundir con inv_components (partes NO independientes).
 */
class InvAssetRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
    }

    private function fixtures(): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $asset = fn (string $tag) => InvAsset::create([
            'internal_tag' => $tag, 'name' => "Asset {$tag}",
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ]);
        $laptop = $asset('LAP-1');
        $dock = $asset('DOCK-1');

        return compact('client', 'site', 'admin', 'laptop', 'dock');
    }

    public function test_linking_two_assets_persists_the_relationship(): void
    {
        ['admin' => $admin, 'laptop' => $laptop, 'dock' => $dock] = $this->fixtures();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$laptop->id}/relationships", [
            'child_asset_id' => $dock->id,
            'relationship_type' => 'component_of',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('inv_asset_relationships', [
            'parent_asset_id' => $laptop->id, 'child_asset_id' => $dock->id, 'relationship_type' => 'component_of',
        ]);
    }

    public function test_cannot_relate_an_asset_to_itself(): void
    {
        ['admin' => $admin, 'laptop' => $laptop] = $this->fixtures();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$laptop->id}/relationships", [
            'child_asset_id' => $laptop->id,
            'relationship_type' => 'component_of',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_link_an_asset_from_another_tenant(): void
    {
        ['admin' => $admin, 'laptop' => $laptop] = $this->fixtures();

        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $category = InvCategory::first();
        $status = InvStatus::first();
        $foreignAsset = InvAsset::create([
            'internal_tag' => 'FOREIGN-1', 'name' => 'Ajeno',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $otherSite, 'client_id' => $otherClient->id,
        ]);

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$laptop->id}/relationships", [
            'child_asset_id' => $foreignAsset->id,
            'relationship_type' => 'component_of',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_link_the_same_pair_twice_in_either_direction(): void
    {
        ['admin' => $admin, 'laptop' => $laptop, 'dock' => $dock] = $this->fixtures();

        $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$laptop->id}/relationships", [
            'child_asset_id' => $dock->id, 'relationship_type' => 'component_of',
        ])->assertCreated();

        // Mismo par, dirección invertida -- también debe rechazarse.
        $reversed = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$dock->id}/relationships", [
            'child_asset_id' => $laptop->id, 'relationship_type' => 'component_of',
        ]);

        $reversed->assertStatus(422);
    }

    public function test_unlinking_removes_the_relationship(): void
    {
        ['admin' => $admin, 'laptop' => $laptop, 'dock' => $dock] = $this->fixtures();
        $created = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$laptop->id}/relationships", [
            'child_asset_id' => $dock->id, 'relationship_type' => 'component_of',
        ]);

        $this->actingAs($admin, 'web')
            ->deleteJson("/api/inv-assets/{$laptop->id}/relationships/{$created->json('id')}")
            ->assertNoContent();

        $this->assertDatabaseMissing('inv_asset_relationships', ['id' => $created->json('id')]);
    }

    public function test_show_includes_relationships_from_both_sides(): void
    {
        ['admin' => $admin, 'laptop' => $laptop, 'dock' => $dock] = $this->fixtures();
        $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$laptop->id}/relationships", [
            'child_asset_id' => $dock->id, 'relationship_type' => 'component_of',
        ])->assertCreated();

        $laptopShow = $this->actingAs($admin, 'web')->getJson("/api/inv-assets/{$laptop->id}");
        $laptopShow->assertOk();
        $this->assertSame($dock->id, $laptopShow->json('child_relationships.0.child_asset.id'));

        $dockShow = $this->actingAs($admin, 'web')->getJson("/api/inv-assets/{$dock->id}");
        $dockShow->assertOk();
        $this->assertSame($laptop->id, $dockShow->json('parent_relationships.0.parent_asset.id'));
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
