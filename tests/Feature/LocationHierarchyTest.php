<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditoría de Inventario, fase 2.3 (ubicación jerárquica). `locations` es
 * compartida con Users/Tickets -- este cambio es aditivo, se prueba solo
 * lo nuevo (parent_id/type), sin tocar los tests existentes de esa tabla.
 */
class LocationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'catalogs.manage', 'guard_name' => 'web']);
    }

    private function adminFixture(): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('catalogs.manage');

        return compact('client', 'site', 'admin');
    }

    public function test_creating_a_location_with_a_parent_and_type_persists_them(): void
    {
        ['site' => $site, 'admin' => $admin] = $this->adminFixture();
        $building = Location::create(['site_id' => $site, 'name' => 'Edificio A', 'type' => 'building', 'is_active' => true]);

        $response = $this->actingAs($admin, 'web')->postJson('/api/locations', [
            'site_id' => $site, 'name' => 'Piso 2', 'type' => 'floor', 'parent_id' => $building->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('locations', [
            'name' => 'Piso 2', 'type' => 'floor', 'parent_id' => $building->id,
        ]);
    }

    public function test_a_location_without_a_parent_still_works_as_before(): void
    {
        ['site' => $site, 'admin' => $admin] = $this->adminFixture();

        $response = $this->actingAs($admin, 'web')->postJson('/api/locations', [
            'site_id' => $site, 'name' => 'Recepción',
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('parent_id'));
    }

    public function test_a_location_cannot_be_its_own_parent(): void
    {
        ['site' => $site, 'admin' => $admin] = $this->adminFixture();
        $location = Location::create(['site_id' => $site, 'name' => 'Rack 1', 'is_active' => true]);

        $response = $this->actingAs($admin, 'web')->putJson("/api/locations/{$location->id}", [
            'site_id' => $site, 'name' => 'Rack 1', 'parent_id' => $location->id,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Auditoría de bugs críticos (2026-08-22): LocationController no tenía
     * ningún scoping de tenant -- IDOR cross-tenant total en las 4 rutas.
     */
    public function test_index_excludes_locations_of_another_tenant(): void
    {
        ['site' => $site, 'admin' => $admin] = $this->adminFixture();
        Location::create(['site_id' => $site, 'name' => 'Mía', 'is_active' => true]);

        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        Location::create(['site_id' => $otherSite, 'name' => 'Ajena', 'is_active' => true]);

        $response = $this->actingAs($admin, 'web')->getJson('/api/locations');

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Mía'));
        $this->assertFalse($names->contains('Ajena'));
    }

    public function test_cannot_create_a_location_for_another_tenants_site(): void
    {
        ['admin' => $admin] = $this->adminFixture();
        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);

        $response = $this->actingAs($admin, 'web')->postJson('/api/locations', [
            'site_id' => $otherSite, 'name' => 'Intrusa',
        ]);

        $response->assertForbidden();
    }

    public function test_cannot_update_or_delete_a_location_belonging_to_another_tenant(): void
    {
        ['admin' => $admin] = $this->adminFixture();
        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $foreign = Location::create(['site_id' => $otherSite, 'name' => 'Ajena', 'is_active' => true]);

        $this->actingAs($admin, 'web')->putJson("/api/locations/{$foreign->id}", [
            'site_id' => $otherSite, 'name' => 'Hackeada',
        ])->assertForbidden();

        $this->actingAs($admin, 'web')->deleteJson("/api/locations/{$foreign->id}")->assertForbidden();

        $this->assertDatabaseHas('locations', ['id' => $foreign->id, 'name' => 'Ajena']);
    }

    public function test_cannot_link_parent_id_to_another_tenants_location(): void
    {
        ['site' => $site, 'admin' => $admin] = $this->adminFixture();
        $otherClient = Client::factory()->create();
        $otherSite = $this->makeSite($otherClient->id);
        $foreignParent = Location::create(['site_id' => $otherSite, 'name' => 'Ajena', 'is_active' => true]);

        $response = $this->actingAs($admin, 'web')->postJson('/api/locations', [
            'site_id' => $site, 'name' => 'Piso 3', 'parent_id' => $foreignParent->id,
        ]);

        $response->assertForbidden();
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
