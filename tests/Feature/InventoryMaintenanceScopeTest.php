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
 * Port de HelpdeskECD2026 a Tikara, fase 5 (mantenimientos).
 */
class InventoryMaintenanceScopeTest extends TestCase
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

    public function test_opening_a_maintenance_creates_record_and_movement(): void
    {
        if (! \Schema::hasTable('inv_maintenances')) {
            $this->markTestSkipped('Migración de inv_maintenances no aplicada.');
        }

        ['admin' => $admin, 'asset' => $asset] = $this->baseFixtures();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/maintenances", [
            'title' => 'Cambio de disco duro',
            'start_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $maintenanceId = $response->json('id');

        $this->assertDatabaseHas('inv_maintenances', [
            'id' => $maintenanceId, 'asset_id' => $asset->id, 'title' => 'Cambio de disco duro', 'end_date' => null,
        ]);
        $this->assertTrue(
            InvMovement::where('asset_id', $asset->id)->where('type', 'MAINTENANCE')->exists(),
            'Abrir un mantenimiento debe generar un InvMovement tipo MAINTENANCE.'
        );
    }

    public function test_closing_a_maintenance_does_not_create_a_new_movement(): void
    {
        if (! \Schema::hasTable('inv_maintenances')) {
            $this->markTestSkipped('Migración de inv_maintenances no aplicada.');
        }

        ['admin' => $admin, 'asset' => $asset] = $this->baseFixtures();

        $maintenance = InvMaintenance::create([
            'asset_id' => $asset->id, 'title' => 'Limpieza interna',
            'start_date' => now()->subDays(2)->toDateString(),
            'logged_by' => $admin->id, 'client_id' => $asset->client_id,
        ]);

        $movementsBefore = InvMovement::count();

        $response = $this->actingAs($admin, 'web')->putJson("/api/inv-maintenances/{$maintenance->id}", [
            'title' => $maintenance->title,
            'start_date' => $maintenance->start_date->toDateString(),
            'end_date' => now()->toDateString(),
            'solution' => 'Se limpió el equipo',
        ]);

        $response->assertOk();
        $this->assertSame($movementsBefore, InvMovement::count(), 'Cerrar un mantenimiento no debe duplicar un movimiento.');
        $this->assertNotNull($maintenance->fresh()->end_date);
    }

    public function test_maintenance_isolation_across_tenants(): void
    {
        if (! \Schema::hasTable('inv_maintenances')) {
            $this->markTestSkipped('Migración de inv_maintenances no aplicada.');
        }

        ['admin' => $adminA, 'asset' => $assetA] = $this->baseFixtures();
        ['admin' => $adminB] = $this->baseFixtures();

        $maintenance = InvMaintenance::create([
            'asset_id' => $assetA->id, 'title' => 'Mantenimiento de A',
            'start_date' => now()->toDateString(),
            'logged_by' => $adminA->id, 'client_id' => $assetA->client_id,
        ]);

        $response = $this->actingAs($adminB, 'web')->getJson("/api/inv-maintenances/{$maintenance->id}");

        $response->assertStatus(403);
    }

    /** Auditoría de Inventario (fase 1, crítico): destroy() debe ser un soft delete, no borrar la fila para siempre. */
    public function test_deleting_a_maintenance_soft_deletes_it(): void
    {
        if (! \Schema::hasTable('inv_maintenances')) {
            $this->markTestSkipped('Migración de inv_maintenances no aplicada.');
        }

        ['admin' => $admin, 'asset' => $asset] = $this->baseFixtures();

        $maintenance = InvMaintenance::create([
            'asset_id' => $asset->id, 'title' => 'Diagnóstico',
            'start_date' => now()->toDateString(),
            'logged_by' => $admin->id, 'client_id' => $asset->client_id,
        ]);

        $this->actingAs($admin, 'web')->deleteJson("/api/inv-maintenances/{$maintenance->id}")->assertNoContent();

        $this->assertSoftDeleted('inv_maintenances', ['id' => $maintenance->id]);
    }

    /** Auditoría de Inventario (fase 1, crítico): antes quedaba client_id null en audit_logs para mantenimientos. */
    public function test_maintenance_audit_log_resolves_client_id(): void
    {
        if (! \Schema::hasTable('inv_maintenances')) {
            $this->markTestSkipped('Migración de inv_maintenances no aplicada.');
        }

        ['admin' => $admin, 'asset' => $asset] = $this->baseFixtures();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/maintenances", [
            'title' => 'Cambio de teclado',
            'start_date' => now()->toDateString(),
        ]);
        $maintenanceId = $response->json('id');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => (new InvMaintenance)->getMorphClass(),
            'auditable_id' => $maintenanceId,
            'action' => 'created',
            'client_id' => $asset->client_id,
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
