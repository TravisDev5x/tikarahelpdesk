<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvMaintenance;
use App\Models\InvMovement;
use App\Models\InvStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Resumen ligero de Inventario para el panel general de Inicio (fase 1 de
 * la separación de dashboards, 2026-08-20) -- GET /api/inv-assets/monitor/summary,
 * InvMonitorAlertsService::counts(). Mismas 4 alertas que
 * InventoryMonitorTest pero solo conteo, más total_assets.
 */
class InvMonitorSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['inventory.view_assets', 'inventory.edit_assets', 'inventory.manage_assets'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_summary_counts_match_the_4_alerts_and_are_scoped_by_tenant(): void
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::firstOrCreate(['name' => 'Laptops'], ['is_active' => true]);
        $status = InvStatus::firstOrCreate(['name' => 'Disponible'], ['assignable' => true, 'is_active' => true]);
        $responsible = $this->clientUser($client->id, $site);

        $asset = fn (string $tag, array $overrides = []) => InvAsset::create(array_merge([
            'internal_tag' => $tag, 'name' => "Asset {$tag}",
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ], $overrides));

        $asset('WARR-1', ['warranty_expiry' => now()->addDays(10)->toDateString(), 'current_user_id' => $responsible->id]);
        $asset('WARR-2', ['warranty_expiry' => now()->addDays(45)->toDateString(), 'current_user_id' => $responsible->id]); // fuera de rango (ventana de 30 días)
        $asset('UNASSIGNED-1'); // current_user_id null -- cuenta

        $transferAsset = $asset('TRANSFER-1', ['current_user_id' => $responsible->id]);
        for ($i = 0; $i < 2; $i++) {
            InvMovement::create([
                'asset_id' => $transferAsset->id, 'type' => 'TRASLADO', 'admin_id' => $admin->id,
                'client_id' => $client->id, 'date' => now()->subHours(2 + $i),
            ]);
        }

        $maintenanceAsset = $asset('MAINT-1', ['current_user_id' => $responsible->id]);
        InvMaintenance::create([
            'asset_id' => $maintenanceAsset->id, 'title' => 'Diagnóstico pendiente',
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => null,
            'logged_by' => $admin->id, 'client_id' => $client->id,
        ]);

        // Ruido de otro tenant -- no debe contarse en ningún bucket.
        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        InvAsset::create([
            'internal_tag' => 'OTHER-WARR', 'name' => 'Otro tenant',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $otherSite, 'client_id' => $otherClient->id,
            'warranty_expiry' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($admin, 'web')->getJson('/api/inv-assets/monitor/summary');

        $response->assertOk()->assertExactJson([
            'warranty_expiring' => 1,
            'unassigned' => 1,
            'repeated_transfers' => 1,
            'stale_maintenances' => 1,
            'problem_assets' => 0,
            'total_assets' => 5,
            'by_status' => [
                ['label' => 'Disponible', 'value' => 5],
            ],
        ]);
    }

    public function test_view_only_permission_is_enough_to_read_the_summary(): void
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $viewer = $this->clientUser($client->id, $site);
        setPermissionsTeamId($client->id);
        $viewer->givePermissionTo('inventory.view_assets');

        $this->actingAs($viewer, 'web')->getJson('/api/inv-assets/monitor/summary')->assertOk();
    }

    public function test_user_without_any_inventory_permission_is_forbidden(): void
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);

        // Ocupa el id=1 y cierra la ventana de arranque de
        // EnsurePermissionOrAdmin (rol vacío) -- mismo patrón que
        // InventoryGranularPermissionsTest::fixtures().
        $decoy = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $emptyRole = \App\Models\Role::query()->create([
            'team_id' => config('tenancy.super_admin_team_id'),
            'name' => 'decoy-'.uniqid(), 'guard_name' => 'web', 'slug' => 'decoy-'.uniqid(),
        ]);
        $decoy->assignRole($emptyRole);

        $nobody = $this->clientUser($client->id, $site);

        $this->actingAs($nobody, 'web')->getJson('/api/inv-assets/monitor/summary')->assertForbidden();
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
