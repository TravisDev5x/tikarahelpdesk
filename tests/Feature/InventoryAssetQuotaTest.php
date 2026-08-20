<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cuota de activos por plan (previo a fase 7 del port de Inventario).
 */
class InventoryAssetQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = [
        'Etiqueta interna', 'Nombre', 'Categoría', 'Estatus', 'Etiqueta',
        'Condición', 'Serie', 'Sede', 'Ubicación', 'Costo',
        'Fecha de compra', 'Vencimiento de garantía', 'Proveedor',
        'Número de factura', 'Especificaciones', 'Notas',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
        InvCategory::firstOrCreate(['name' => 'Laptops'], ['is_active' => true]);
        InvStatus::firstOrCreate(['name' => 'Disponible'], ['assignable' => true, 'is_active' => true]);
    }

    private function fixtures(int $maxAssets): array
    {
        $plan = Plan::create([
            'name' => 'Quota Test', 'slug' => 'quota-test-'.uniqid(),
            'type' => 'inhouse', 'price_monthly' => 0, 'price_yearly' => 0,
            'max_assets' => $maxAssets,
        ]);

        $client = Client::factory()->create(['plan_id' => $plan->id]);
        $siteName = 'Sede de prueba '.uniqid();
        $siteId = $this->makeSite($client->id, $siteName);
        $admin = $this->clientUser($client->id, $siteId);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        return compact('client', 'siteId', 'siteName', 'admin');
    }

    public function test_manual_create_is_rejected_once_quota_is_reached(): void
    {
        ['admin' => $admin, 'siteId' => $siteId] = $this->fixtures(2);
        $category = InvCategory::first();
        $status = InvStatus::first();

        $payload = fn (string $tag) => [
            'internal_tag' => $tag, 'name' => "Laptop {$tag}",
            'category_id' => $category->id, 'status_id' => $status->id, 'site_id' => $siteId,
        ];

        $this->actingAs($admin, 'web')->postJson('/api/inv-assets', $payload('Q-001'))->assertCreated();
        $this->actingAs($admin, 'web')->postJson('/api/inv-assets', $payload('Q-002'))->assertCreated();

        $third = $this->actingAs($admin, 'web')->postJson('/api/inv-assets', $payload('Q-003'));
        $third->assertStatus(422);
        $this->assertDatabaseMissing('inv_assets', ['internal_tag' => 'Q-003']);
    }

    public function test_client_without_plan_has_no_limit(): void
    {
        $client = Client::factory()->create(['plan_id' => null]);
        $siteId = $this->makeSite($client->id, 'Sede sin plan '.uniqid());
        $admin = $this->clientUser($client->id, $siteId);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::first();
        $status = InvStatus::first();

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($admin, 'web')->postJson('/api/inv-assets', [
                'internal_tag' => "NL-{$i}", 'name' => "Laptop {$i}",
                'category_id' => $category->id, 'status_id' => $status->id, 'site_id' => $siteId,
            ])->assertCreated();
        }

        $this->assertSame(5, InvAsset::where('client_id', $client->id)->count());
    }

    public function test_import_stops_creating_once_quota_is_reached_without_discarding_earlier_rows(): void
    {
        ['admin' => $admin, 'siteName' => $siteName] = $this->fixtures(2);

        $rows = [
            ['Q-IMP-1', 'Laptop 1', 'Laptops', 'Disponible', '', '', '', $siteName, '', '', '', '', '', '', '', ''],
            ['Q-IMP-2', 'Laptop 2', 'Laptops', 'Disponible', '', '', '', $siteName, '', '', '', '', '', '', '', ''],
            ['Q-IMP-3', 'Laptop 3', 'Laptops', 'Disponible', '', '', '', $siteName, '', '', '', '', '', '', '', ''],
        ];
        $path = $this->buildXlsx($rows);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'web')->post('/api/inv-assets/import', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('created', 2);
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseHas('inv_assets', ['internal_tag' => 'Q-IMP-1']);
        $this->assertDatabaseHas('inv_assets', ['internal_tag' => 'Q-IMP-2']);
        $this->assertDatabaseMissing('inv_assets', ['internal_tag' => 'Q-IMP-3']);
    }

    private function buildXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach (self::HEADINGS as $col => $heading) {
            $sheet->setCellValue($this->colLetter($col + 1).'1', $heading);
        }
        foreach ($rows as $r => $row) {
            foreach (self::HEADINGS as $col => $heading) {
                $sheet->setCellValue($this->colLetter($col + 1).($r + 2), $row[$col] ?? '');
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'quota_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function colLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex > 0) {
            $colIndex--;
            $letter = chr(65 + ($colIndex % 26)).$letter;
            $colIndex = (int) floor($colIndex / 26);
        }

        return $letter ?: 'A';
    }

    private function makeSite(int $clientId, string $name): int
    {
        $now = now();

        return DB::table('sites')->insertGetId([
            'client_id' => $clientId,
            'name' => $name,
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
