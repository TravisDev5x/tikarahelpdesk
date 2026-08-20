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
 * Permisos granulares de Inventario (fase 7.4, retomada tras pausa --
 * ver docs/INVENTORY_ROADMAP.md). 3 niveles reales sobre Activos:
 *   - inventory.view_assets: solo lectura.
 *   - inventory.edit_assets: todo lo operativo (crear/editar/ciclo de
 *     vida/import/fotos), SIN eliminar.
 *   - inventory.manage_assets (ya existía): todo lo anterior + eliminar.
 * Middleware perm:a|b|c en routes/api.php -- cada endpoint acepta
 * cualquier nivel igual o superior al que le corresponde.
 */
class InventoryGranularPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['inventory.view_assets', 'inventory.edit_assets', 'inventory.manage_assets'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_view_only_user_can_list_and_show_but_not_mutate(): void
    {
        [$client, $site, $category, $status] = $this->fixtures();
        $asset = InvAsset::create($this->assetPayload($client->id, $site, $category->id, $status->id, 'Original'));

        // team_id = $client->id (NO el centinela de super_admin) -- este es
        // un usuario de tenant normal, y ApplyPgsqlTenantRls reescribe el
        // team_id de permisos vigente a TenantClientResolver::resolve($user)
        // en cada request real (no es super_admin, no hace bypass). Si el
        // permiso se otorga bajo otro team_id, el chequeo de la request
        // real nunca lo ve -- 403 aunque givePermissionTo() haya "servido".
        $viewer = $this->clientUser($client->id, $site);
        setPermissionsTeamId($client->id);
        $viewer->givePermissionTo('inventory.view_assets');

        $this->actingAs($viewer, 'web')->getJson('/api/inv-assets')->assertOk();
        $this->actingAs($viewer, 'web')->getJson("/api/inv-assets/{$asset->id}")->assertOk();

        $this->actingAs($viewer, 'web')->postJson('/api/inv-assets', [
            'internal_tag' => 'TAG-'.uniqid(), 'name' => 'Nuevo',
            'category_id' => $category->id, 'status_id' => $status->id, 'site_id' => $site,
        ])->assertForbidden();

        $this->actingAs($viewer, 'web')->putJson("/api/inv-assets/{$asset->id}", [
            'internal_tag' => $asset->internal_tag, 'name' => 'Hackeado',
            'category_id' => $category->id, 'status_id' => $status->id, 'site_id' => $site,
        ])->assertForbidden();

        $this->actingAs($viewer, 'web')->deleteJson("/api/inv-assets/{$asset->id}")->assertForbidden();

        $this->assertDatabaseHas('inv_assets', ['id' => $asset->id, 'name' => 'Original']);
    }

    public function test_edit_only_user_can_create_and_update_but_not_delete(): void
    {
        [$client, $site, $category, $status] = $this->fixtures();
        $asset = InvAsset::create($this->assetPayload($client->id, $site, $category->id, $status->id, 'Original'));

        $editor = $this->clientUser($client->id, $site);
        setPermissionsTeamId($client->id);
        $editor->givePermissionTo('inventory.edit_assets');

        $this->actingAs($editor, 'web')->postJson('/api/inv-assets', [
            'internal_tag' => 'TAG-'.uniqid(), 'name' => 'Creado por editor',
            'category_id' => $category->id, 'status_id' => $status->id, 'site_id' => $site,
        ])->assertCreated();

        $this->actingAs($editor, 'web')->putJson("/api/inv-assets/{$asset->id}", [
            'internal_tag' => $asset->internal_tag, 'name' => 'Editado',
            'category_id' => $category->id, 'status_id' => $status->id, 'site_id' => $site,
        ])->assertOk();

        $this->actingAs($editor, 'web')->deleteJson("/api/inv-assets/{$asset->id}")->assertForbidden();
        $this->assertDatabaseHas('inv_assets', ['id' => $asset->id]);
    }

    public function test_manage_user_can_delete(): void
    {
        [$client, $site, $category, $status] = $this->fixtures();
        $asset = InvAsset::create($this->assetPayload($client->id, $site, $category->id, $status->id, 'Original'));

        $manager = $this->clientUser($client->id, $site);
        setPermissionsTeamId($client->id);
        $manager->givePermissionTo('inventory.manage_assets');

        $this->actingAs($manager, 'web')->deleteJson("/api/inv-assets/{$asset->id}")->assertNoContent();
        $this->assertSoftDeleted('inv_assets', ['id' => $asset->id]);
    }

    public function test_view_only_user_can_open_the_assets_page(): void
    {
        [$client, $site] = $this->fixtures();

        $viewer = $this->clientUser($client->id, $site);
        setPermissionsTeamId($client->id);
        $viewer->givePermissionTo('inventory.view_assets');

        $this->actingAs($viewer, 'web')->get('/inventory/assets')->assertOk();
    }

    public function test_user_without_any_inventory_permission_is_forbidden(): void
    {
        // fixtures() ya ocupa el id=1 y cierra la ventana de arranque de
        // EnsurePermissionOrAdmin (rol vacío ya asignado a otro usuario) --
        // este usuario, sin rol ni permiso propio, debe quedar en 403 real.
        [$client, $site] = $this->fixtures();
        $nobody = $this->clientUser($client->id, $site);

        $this->actingAs($nobody, 'web')->getJson('/api/inv-assets')->assertForbidden();
        $this->actingAs($nobody, 'web')->get('/inventory/assets')->assertForbidden();
    }

    /** @return array{0: Client, 1: int, 2: InvCategory, 3: InvStatus} */
    private function fixtures(): array
    {
        if (! \Schema::hasTable('inv_assets')) {
            $this->markTestSkipped('Migración de inv_assets no aplicada.');
        }

        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        // Ocupa el id=1 -- EnsurePermissionOrAdmin deja pasar al primer
        // usuario del sistema sin importar sus permisos reales (ventana de
        // arranque), lo que taparía cualquier 403 esperado más abajo.
        // También le asigna un rol vacío: mientras model_has_roles esté
        // completamente vacía, esa misma clase deja pasar a CUALQUIER
        // usuario (ventana de arranque del sistema completo, no solo del
        // primer usuario) -- givePermissionTo() de los tests de abajo NO
        // toca model_has_roles, solo model_has_permissions.
        $decoy = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $emptyRole = \App\Models\Role::query()->create([
            'team_id' => config('tenancy.super_admin_team_id'),
            'name' => 'decoy-'.uniqid(),
            'guard_name' => 'web',
            'slug' => 'decoy-'.uniqid(),
        ]);
        $decoy->assignRole($emptyRole);

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'is_active' => true]);

        return [$client, $site, $category, $status];
    }

    private function assetPayload(int $clientId, int $siteId, int $categoryId, int $statusId, string $name): array
    {
        return [
            'internal_tag' => 'TAG-'.uniqid(),
            'name' => $name,
            'category_id' => $categoryId,
            'status_id' => $statusId,
            'site_id' => $siteId,
            'client_id' => $clientId,
        ];
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
