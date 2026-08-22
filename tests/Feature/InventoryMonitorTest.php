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
 * Port de HelpdeskECD2026 a Tikara, fase 7.1 (dashboard de alertas).
 */
class InventoryMonitorTest extends TestCase
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

        $category = InvCategory::firstOrCreate(['name' => 'Laptops'], ['is_active' => true]);
        $status = InvStatus::firstOrCreate(['name' => 'Disponible'], ['assignable' => true, 'is_active' => true]);

        return compact('client', 'site', 'admin', 'category', 'status');
    }

    private function makeAsset(array $fixtures, string $tag, array $overrides = []): InvAsset
    {
        return InvAsset::create(array_merge([
            'internal_tag' => $tag, 'name' => "Asset {$tag}",
            'category_id' => $fixtures['category']->id, 'status_id' => $fixtures['status']->id,
            'site_id' => $fixtures['site'], 'client_id' => $fixtures['client']->id,
        ], $overrides));
    }

    public function test_monitor_reports_each_alert_and_ignores_healthy_assets(): void
    {
        $fx = $this->baseFixtures();

        $responsible = $this->clientUser($fx['client']->id, $fx['site']);
        $this->makeAsset($fx, 'HEALTHY-1', ['current_user_id' => $responsible->id, 'warranty_expiry' => now()->addYear()->toDateString()]);

        // Todas estas tienen responsable, para no contaminar la alerta "sin responsable".
        $this->makeAsset($fx, 'WARR-1', ['warranty_expiry' => now()->addDays(10)->toDateString(), 'current_user_id' => $responsible->id]);
        $this->makeAsset($fx, 'WARR-2', ['warranty_expiry' => now()->addDays(45)->toDateString(), 'current_user_id' => $responsible->id]); // fuera de rango (ventana de 30 días), no debe aparecer

        $this->makeAsset($fx, 'UNASSIGNED-1'); // current_user_id null por defecto -- la única que debe aparecer aquí

        $transferAsset = $this->makeAsset($fx, 'TRANSFER-1', ['current_user_id' => $responsible->id]);
        for ($i = 0; $i < 2; $i++) {
            InvMovement::create([
                'asset_id' => $transferAsset->id, 'type' => 'TRASLADO', 'admin_id' => $fx['admin']->id,
                'client_id' => $fx['client']->id, 'date' => now()->subHours(2 + $i),
            ]);
        }

        $maintenanceAsset = $this->makeAsset($fx, 'MAINT-1', ['current_user_id' => $responsible->id]);
        InvMaintenance::create([
            'asset_id' => $maintenanceAsset->id, 'title' => 'Diagnóstico pendiente',
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => null,
            'logged_by' => $fx['admin']->id, 'client_id' => $fx['client']->id,
        ]);

        // Ruido de otro tenant con la misma condición -- no debe filtrarse.
        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $this->makeAsset(
            ['category' => $fx['category'], 'status' => $fx['status'], 'site' => $otherSite, 'client' => $otherClient],
            'OTHER-WARR', ['warranty_expiry' => now()->addDays(5)->toDateString()]
        );

        $response = $this->actingAs($fx['admin'], 'web')->get('/inventory/monitor');

        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Monitor', shouldExist: false)
            ->has('warrantyExpiring', 1)
            ->where('warrantyExpiring.0.internal_tag', 'WARR-1')
            ->has('unassigned', 1)
            ->where('unassigned.0.internal_tag', 'UNASSIGNED-1')
            ->has('repeatedTransfers', 1)
            ->where('repeatedTransfers.0.internal_tag', 'TRANSFER-1')
            ->where('repeatedTransfers.0.transfers_24h', 2)
            ->has('staleMaintenances', 1)
            ->where('staleMaintenances.0.title', 'Diagnóstico pendiente')
        );
    }

    public function test_monitor_includes_report_blocks_scoped_by_tenant(): void
    {
        $fx = $this->baseFixtures();
        $monitors = InvCategory::firstOrCreate(['name' => 'Monitores'], ['is_active' => true]);
        $siteB = $this->makeSite($fx['client']->id);
        $user1 = $this->clientUser($fx['client']->id, $fx['site']);
        $user2 = $this->clientUser($fx['client']->id, $fx['site']);

        $this->makeAsset($fx, 'RPT-1', ['current_user_id' => $user1->id, 'cost' => 1000]);
        $this->makeAsset($fx, 'RPT-2', ['current_user_id' => $user1->id, 'cost' => 500]);
        $this->makeAsset($fx, 'RPT-3', ['current_user_id' => $user2->id, 'cost' => 250]);
        $this->makeAsset($fx, 'RPT-4', ['category_id' => $monitors->id, 'site_id' => $siteB, 'current_user_id' => $user1->id, 'cost' => 300]);

        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $this->makeAsset(
            ['category' => $fx['category'], 'status' => $fx['status'], 'site' => $otherSite, 'client' => $otherClient],
            'RPT-OTHER'
        );

        $response = $this->actingAs($fx['admin'], 'web')->get('/inventory/monitor');

        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Monitor', shouldExist: false)
            ->has('byCategory', 2)
            ->where('byCategory.0.label', 'Laptops')
            ->where('byCategory.0.value', 3)
            ->where('byCategory.1.label', 'Monitores')
            ->where('byCategory.1.value', 1)
            ->has('bySite', 2)
            ->has('topAssignees', 2)
            ->where('topAssignees.0.value', 3)
            ->where('topAssignees.0.user_id', $user1->id)
            ->where('totalValue', 2050)
            ->has('costByCategory', 2)
            ->where('costByCategory.0.label', 'Laptops')
            ->where('costByCategory.0.value', 1750)
            ->where('costByCategory.1.label', 'Monitores')
            ->where('costByCategory.1.value', 300)
            ->has('monthlyTrend', 6)
            ->where('monthlyTrend.5.value', 4)
        );
    }

    /** Wallboard standalone: mismos props que /inventory/monitor, mismo gate de permiso. */
    public function test_wallboard_renders_the_same_data_as_the_monitor_page(): void
    {
        $fx = $this->baseFixtures();
        $this->makeAsset($fx, 'WARR-1', ['warranty_expiry' => now()->addDays(10)->toDateString()]);

        $response = $this->actingAs($fx['admin'], 'web')->get('/inventory/wallboard');

        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Wallboard', shouldExist: false)
            ->has('warrantyExpiring', 1)
            ->where('warrantyExpiring.0.internal_tag', 'WARR-1')
        );
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
