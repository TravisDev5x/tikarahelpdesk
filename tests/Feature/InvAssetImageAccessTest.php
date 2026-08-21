<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvAssetImage;
use App\Models\InvCategory;
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
 * Auditoría de Inventario (fase 1, crítico): las fotos de un activo vivían
 * en disco 'public' (storage/app/public, symlinkeado y servido directo por
 * el webserver) -- cualquiera con la URL las veía sin sesión ni pertenecer
 * al tenant, sin pasar por ningún middleware de Laravel. Ahora se guardan
 * en 'local' (storage/app/private, sin symlink) y solo
 * InvAssetImageController::show() -- autenticado, mismo chequeo de tenant
 * que store()/destroy() -- las sirve.
 */
class InvAssetImageAccessTest extends TestCase
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

    public function test_uploaded_image_is_stored_on_the_private_disk_not_public(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();

        $response = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/images", [
            'images' => [UploadedFile::fake()->image('foto.jpg')],
        ]);

        $response->assertCreated();
        $this->assertSame('local', $response->json('0.disk'));
        Storage::disk('local')->assertExists($response->json('0.path'));
    }

    public function test_owner_tenant_can_view_the_image(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->assetFixture();

        $upload = $this->actingAs($admin, 'web')->postJson("/api/inv-assets/{$asset->id}/images", [
            'images' => [UploadedFile::fake()->image('foto.jpg')],
        ]);
        $imageId = $upload->json('0.id');

        $response = $this->actingAs($admin, 'web')->get("/api/inv-assets/{$asset->id}/images/{$imageId}");

        $response->assertOk();
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }

    public function test_a_user_from_another_tenant_cannot_view_the_image(): void
    {
        // Imagen creada directo (sin pasar por un actingAs() de upload
        // primero) -- el guard 'sanctum' cachea el usuario resuelto en la
        // primera request real y no se re-resuelve solo con un segundo
        // actingAs() de por medio (footgun documentado en CLAUDE.md); crear
        // el fixture sin HTTP de por medio lo evita de raíz.
        ['asset' => $asset] = $this->assetFixture();
        Storage::disk('local')->put("inv-assets/{$asset->id}/foto.jpg", 'contenido-fake');
        $image = InvAssetImage::create([
            'inv_asset_id' => $asset->id, 'path' => "inv-assets/{$asset->id}/foto.jpg", 'disk' => 'local',
        ]);

        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $intruder = $this->clientUser($otherClient->id, $otherSite);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $intruder->givePermissionTo('inventory.manage_assets');

        $response = $this->actingAs($intruder, 'web')->get("/api/inv-assets/{$asset->id}/images/{$image->id}");

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_cannot_view_the_image(): void
    {
        ['asset' => $asset] = $this->assetFixture();
        Storage::disk('local')->put("inv-assets/{$asset->id}/foto.jpg", 'contenido-fake');
        $image = InvAssetImage::create([
            'inv_asset_id' => $asset->id, 'path' => "inv-assets/{$asset->id}/foto.jpg", 'disk' => 'local',
        ]);

        $response = $this->getJson("/api/inv-assets/{$asset->id}/images/{$image->id}");

        $response->assertUnauthorized();
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
