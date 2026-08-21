<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvDocument;
use App\Models\InvStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditoría de Inventario, fase 2.2 (documentos y evidencias). Mismo
 * criterio de disco privado + acceso autenticado que
 * InvAssetImageAccessTest.php (fase 1) -- disk 'local' desde el alta,
 * nunca 'public'.
 */
class InvAssetDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
        Storage::fake('local');
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

    public function test_uploading_a_document_persists_it_on_the_private_disk(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/documents", [
            'file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
            'type' => 'invoice',
        ]);

        $response->assertCreated();
        $this->assertSame('local', $response->json('disk'));
        $this->assertSame('invoice', $response->json('type'));
        $this->assertDatabaseHas('inv_documents', [
            'documentable_type' => InvAsset::class, 'documentable_id' => $asset->id, 'type' => 'invoice',
        ]);
        Storage::disk('local')->assertExists($response->json('path'));
    }

    public function test_rejects_a_document_type_outside_the_fixed_list(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/documents", [
            'file' => UploadedFile::fake()->create('cosa.pdf', 10, 'application/pdf'),
            'type' => 'not-a-real-type',
        ]);

        $response->assertStatus(422);
    }

    public function test_owner_tenant_can_download_the_document(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();
        $upload = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/documents", [
            'file' => UploadedFile::fake()->create('acta.pdf', 20, 'application/pdf'),
            'type' => 'acta_entrega',
        ]);

        $response = $this->actingAs($admin, 'web')->get("/api/inv-assets/{$asset->id}/documents/{$upload->json('id')}");

        $response->assertOk();
    }

    public function test_a_user_from_another_tenant_cannot_download_the_document(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();
        Storage::disk('local')->put("inv-assets/{$asset->id}/documents/acta.pdf", 'contenido-fake');
        $document = InvDocument::create([
            'documentable_type' => InvAsset::class, 'documentable_id' => $asset->id,
            'client_id' => $asset->client_id, 'type' => 'acta_entrega',
            'path' => "inv-assets/{$asset->id}/documents/acta.pdf", 'disk' => 'local',
            'original_name' => 'acta.pdf', 'uploaded_by' => $admin->id,
        ]);

        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $intruder = $this->clientUser($otherClient->id, $otherSite);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $intruder->givePermissionTo('inventory.manage_assets');

        $response = $this->actingAs($intruder, 'web')->get("/api/inv-assets/{$asset->id}/documents/{$document->id}");

        $response->assertForbidden();
    }

    public function test_deleting_a_document_removes_it_from_disk_and_database(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();
        $upload = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/documents", [
            'file' => UploadedFile::fake()->create('acta.pdf', 5, 'application/pdf'),
            'type' => 'other',
        ]);
        $path = $upload->json('path');

        $this->actingAs($admin, 'web')->deleteJson("/api/inv-assets/{$asset->id}/documents/{$upload->json('id')}")->assertNoContent();

        $this->assertDatabaseMissing('inv_documents', ['id' => $upload->json('id')]);
        Storage::disk('local')->assertMissing($path);
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
