<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvMovement;
use App\Models\InvStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Port de HelpdeskECD2026 a Tikara, fase 7.2 (exports).
 */
class InventoryExportsTest extends TestCase
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

    private function makeAsset(array $fx, string $tag, array $overrides = []): InvAsset
    {
        return InvAsset::create(array_merge([
            'internal_tag' => $tag, 'name' => "Asset {$tag}",
            'category_id' => $fx['category']->id, 'status_id' => $fx['status']->id,
            'site_id' => $fx['site'], 'client_id' => $fx['client']->id,
        ], $overrides));
    }

    public function test_asset_export_respects_filters_and_tenant_scope(): void
    {
        $fx = $this->baseFixtures();
        $otherStatus = InvStatus::create(['name' => 'De baja', 'assignable' => false, 'is_active' => true]);

        $this->makeAsset($fx, 'MATCH-1'); // status "Disponible"
        $this->makeAsset($fx, 'NOMATCH-1', ['status_id' => $otherStatus->id]);

        $other = $this->baseFixtures();
        $this->makeAsset($other, 'OTHER-MATCH');

        $response = $this->actingAs($fx['admin'], 'web')->get('/api/inv-assets/export?status_id='.$fx['status']->id);
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getSheetByName('Todos los activos')->toArray();
        @unlink($path);

        $tags = array_column(array_slice($rows, 1), 1); // columna B = Etiqueta interna
        $this->assertContains('MATCH-1', $tags);
        $this->assertNotContains('NOMATCH-1', $tags);
        $this->assertNotContains('OTHER-MATCH', $tags);
    }

    public function test_movement_export_is_valid_csv_with_expected_columns_and_tenant_scope(): void
    {
        $fx = $this->baseFixtures();
        $asset = $this->makeAsset($fx, 'MOV-1');
        InvMovement::create([
            'asset_id' => $asset->id, 'type' => 'CHECKOUT', 'admin_id' => $fx['admin']->id,
            'client_id' => $fx['client']->id, 'date' => now(),
        ]);

        $other = $this->baseFixtures();
        $otherAsset = $this->makeAsset($other, 'MOV-OTHER');
        InvMovement::create([
            'asset_id' => $otherAsset->id, 'type' => 'CHECKOUT', 'admin_id' => $other['admin']->id,
            'client_id' => $other['client']->id, 'date' => now(),
        ]);

        $response = $this->actingAs($fx['admin'], 'web')->get('/api/inv-movements/export');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $lines = array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv)));
        $header = str_getcsv(array_shift($lines));

        $this->assertSame([
            'ID', 'Fecha', 'Tipo', 'Activo ID', 'Etiqueta del activo', 'Nombre del activo',
            'Usuario nuevo', 'Usuario anterior', 'Registrado por', 'Motivo', 'Notas', 'Lote UUID',
        ], $header);

        $this->assertStringContainsString('MOV-1', $csv);
        $this->assertStringNotContainsString('MOV-OTHER', $csv);
    }

    public function test_monitor_export_has_correct_summary_counts(): void
    {
        $fx = $this->baseFixtures();
        $this->makeAsset($fx, 'WARR-EXP', ['warranty_expiry' => now()->addDays(5)->toDateString()]);
        $this->makeAsset($fx, 'NO-RESP');

        $response = $this->actingAs($fx['admin'], 'web')->get('/api/inv-assets/monitor/export');
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $summary = $spreadsheet->getSheetByName('Resumen')->toArray();
        $warrantySheet = $spreadsheet->getSheetByName('Renovaciones')->toArray();
        @unlink($path);

        $this->assertSame('Renovaciones (vencidas o por vencer en 30 días)', $summary[1][0]);
        $this->assertEquals(1, $summary[1][1]);
        $this->assertSame('Activos sin responsable', $summary[2][0]);
        $this->assertEquals(2, $summary[2][1]); // WARR-EXP y NO-RESP, ninguna tiene current_user_id
        $this->assertSame('WARR-EXP', $warrantySheet[1][1]);
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
