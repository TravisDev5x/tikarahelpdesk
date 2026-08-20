<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvStatus;
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
 * Port de HelpdeskECD2026 a Tikara, fase 6 (import masivo de activos).
 */
class InventoryAssetImportTest extends TestCase
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
    }

    private function baseFixtures(): array
    {
        $client = Client::factory()->create();
        $siteName = 'Sede de prueba '.uniqid();
        $site = $this->makeSite($client->id, $siteName);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        InvCategory::firstOrCreate(['name' => 'Laptops'], ['is_active' => true]);
        InvStatus::firstOrCreate(['name' => 'Disponible'], ['assignable' => true, 'is_active' => true]);

        return compact('client', 'site', 'siteName', 'admin');
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
        $path = tempnam(sys_get_temp_dir(), 'import_test_').'.xlsx';
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

    private function row(string $tag, string $site, string $category = 'Laptops', string $status = 'Disponible', string $serial = ''): array
    {
        return [$tag, "Laptop {$tag}", $category, $status, '', '', $serial, $site, '', '', '', '', '', '', '', ''];
    }

    public function test_valid_row_creates_asset(): void
    {
        ['admin' => $admin, 'siteName' => $siteName] = $this->baseFixtures();

        $path = $this->buildXlsx([$this->row('LAP-001', $siteName)]);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'web')->post('/api/inv-assets/import', ['file' => $file]);

        $response->assertCreated();
        $response->assertJson(['created' => 1, 'errors' => []]);
        $this->assertDatabaseHas('inv_assets', ['internal_tag' => 'LAP-001']);
    }

    public function test_row_with_unknown_category_reports_row_error(): void
    {
        ['admin' => $admin, 'siteName' => $siteName] = $this->baseFixtures();

        $path = $this->buildXlsx([$this->row('LAP-002', $siteName, 'Categoría Inexistente')]);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'web')->post('/api/inv-assets/import', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('created', 0);
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseMissing('inv_assets', ['internal_tag' => 'LAP-002']);
    }

    public function test_duplicate_internal_tag_within_same_file_reports_error_on_second_row(): void
    {
        ['admin' => $admin, 'siteName' => $siteName] = $this->baseFixtures();

        $path = $this->buildXlsx([
            $this->row('LAP-003', $siteName),
            $this->row('LAP-003', $siteName),
        ]);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'web')->post('/api/inv-assets/import', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('created', 1);
        $response->assertJsonCount(1, 'errors');
        $this->assertSame(1, InvAsset::where('internal_tag', 'LAP-003')->count());
    }

    public function test_site_from_another_tenant_is_rejected(): void
    {
        ['admin' => $admin] = $this->baseFixtures();
        $otherClient = Client::factory()->create();
        $this->makeSite($otherClient->id, 'Sede ajena');

        $path = $this->buildXlsx([$this->row('LAP-004', 'Sede ajena')]);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'web')->post('/api/inv-assets/import', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('created', 0);
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseMissing('inv_assets', ['internal_tag' => 'LAP-004']);
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
