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
 * Auditoría de Inventario, fase 2.3 (garantías como entidad propia,
 * opcional -- no reemplaza inv_assets.warranty_expiry).
 */
class InvWarrantiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
    }

    private function assetFixture(): array
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

    public function test_adding_a_warranty_persists_it(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/warranties", [
            'provider' => 'Dell ProSupport',
            'warranty_number' => 'DL-12345',
            'ends_at' => now()->addYear()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('inv_warranties', [
            'asset_id' => $asset->id, 'provider' => 'Dell ProSupport', 'warranty_number' => 'DL-12345',
        ]);
    }

    public function test_ends_at_is_required(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/warranties", [
            'provider' => 'Dell ProSupport',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_user_from_another_tenant_cannot_add_a_warranty(): void
    {
        ['asset' => $asset] = $this->assetFixture();

        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $intruder = $this->clientUser($otherClient->id, $otherSite);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $intruder->givePermissionTo('inventory.manage_assets');

        $response = $this->actingAs($intruder, 'web')->postJson("/api/inv-assets/{$asset->id}/warranties", [
            'provider' => 'Intruso', 'ends_at' => now()->addYear()->toDateString(),
        ]);

        $response->assertForbidden();
    }

    public function test_deleting_a_warranty_removes_it(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();
        $created = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/warranties", [
            'provider' => 'Dell ProSupport', 'ends_at' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($admin, 'web')
            ->deleteJson("/api/inv-assets/{$asset->id}/warranties/{$created->json('id')}")
            ->assertNoContent();

        $this->assertDatabaseMissing('inv_warranties', ['id' => $created->json('id')]);
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
