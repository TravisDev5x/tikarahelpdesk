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
