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
 * Vista de asignación consolidada ("¿quién tiene qué?") -- pendiente
 * retomado del roadmap original de fase 7, aparte de la auditoría
 * ITAM/CMDB.
 */
class InventoryAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.view_assets', 'guard_name' => 'web']);
    }

    private function fixtures(): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.view_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $asset = fn (string $tag, array $overrides = []) => InvAsset::create(array_merge([
            'internal_tag' => $tag, 'name' => "Asset {$tag}",
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ], $overrides));

        return compact('client', 'site', 'admin', 'asset');
    }

    public function test_roster_groups_assets_by_user_with_count_and_total_value(): void
    {
        ['client' => $client, 'site' => $site, 'admin' => $admin, 'asset' => $asset] = $this->fixtures();
        $ana = $this->clientUser($client->id, $site);
        $luis = $this->clientUser($client->id, $site);

        $asset('LAP-1', ['current_user_id' => $ana->id, 'cost' => 1000]);
        $asset('LAP-2', ['current_user_id' => $ana->id, 'cost' => 500]);
        $asset('LAP-3', ['current_user_id' => $luis->id, 'cost' => 300]);
        $asset('LAP-UNASSIGNED'); // sin current_user_id -- no debe aparecer

        $response = $this->actingAs($admin, 'web')->get('/inventory/assignments');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Assignments', shouldExist: false)
            ->has('roster', 2)
            ->where('roster.0.user_id', $ana->id)
            ->where('roster.0.asset_count', 2)
            ->where('roster.0.total_value', 1500)
            ->has('roster.0.assets', 2)
            ->where('roster.1.user_id', $luis->id)
            ->where('roster.1.asset_count', 1)
        );
    }

    public function test_roster_is_scoped_by_tenant(): void
    {
        ['client' => $client, 'site' => $site, 'admin' => $admin, 'asset' => $asset] = $this->fixtures();
        $mine = $this->clientUser($client->id, $site);
        $asset('LAP-MINE', ['current_user_id' => $mine->id]);

        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $otherCategory = InvCategory::create(['name' => 'Otros', 'is_active' => true]);
        $otherStatus = InvStatus::create(['name' => 'Disponible2', 'assignable' => true, 'is_active' => true]);
        $otherUser = $this->clientUser($otherClient->id, $otherSite);
        InvAsset::create([
            'internal_tag' => 'LAP-OTHER', 'name' => 'Ajeno',
            'category_id' => $otherCategory->id, 'status_id' => $otherStatus->id,
            'site_id' => $otherSite, 'client_id' => $otherClient->id, 'current_user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($admin, 'web')->get('/inventory/assignments');

        $response->assertInertia(fn ($page) => $page
            ->has('roster', 1)
            ->where('roster.0.user_id', $mine->id)
        );
    }

    public function test_view_only_permission_is_enough_to_see_the_page(): void
    {
        ['admin' => $admin] = $this->fixtures();

        $this->actingAs($admin, 'web')->get('/inventory/assignments')->assertOk();
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
