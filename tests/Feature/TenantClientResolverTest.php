<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\TenantClientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantClientResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefers_sede_client_over_users_client_id(): void
    {
        $operator = $this->createBareUser();
        $clientSede = Client::create(['name' => 'Desde sede', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $clientUser = Client::create(['name' => 'Desde user', 'operator_user_id' => $operator->id, 'is_active' => true]);

        $siteId = $this->createSite($clientSede->id);
        $user = $this->createBareUser([
            'email' => 'staff@test.local',
            'site_id' => $siteId,
            'client_id' => $clientUser->id,
        ]);

        $this->assertSame($clientSede->id, app(TenantClientResolver::class)->resolve($user));
    }

    public function test_falls_back_to_users_client_id_without_sede_client(): void
    {
        $operator = $this->createBareUser();
        $client = Client::create(['name' => 'Solo user', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $siteId = $this->createSite(null);

        $user = $this->createBareUser([
            'email' => 'nostede@test.local',
            'site_id' => $siteId,
            'client_id' => $client->id,
        ]);

        $this->assertSame($client->id, app(TenantClientResolver::class)->resolve($user));
    }

    public function test_incident_policy_scope_excludes_other_tenant(): void
    {
        $operator = $this->createBareUser();
        $clientA = Client::create(['name' => 'A', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'B', 'operator_user_id' => $operator->id, 'is_active' => true]);

        $siteA = $this->createSite($clientA->id);
        $areaId = DB::table('areas')->insertGetId([
            'name' => 'Soporte',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->createBareUser([
            'email' => 'area@test.local',
            'site_id' => $siteA,
            'area_id' => $areaId,
        ]);
        Permission::firstOrCreate(['name' => 'incidents.view_area', 'guard_name' => 'web']);
        setPermissionsTeamId($clientA->id);
        $user->givePermissionTo('incidents.view_area');

        $typeId = DB::table('incident_types')->insertGetId([
            'name' => 'Tipo', 'code' => 't', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sevId = DB::table('incident_severities')->insertGetId([
            'name' => 'Alta', 'code' => 'a', 'level' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $statusId = DB::table('incident_statuses')->insertGetId([
            'name' => 'Abierto', 'code' => 'ab', 'is_active' => true, 'is_final' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $siteB = $this->createSite($clientB->id);

        DB::table('incidents')->insert([
            ['subject' => 'En A', 'reporter_id' => $user->id, 'area_id' => $areaId, 'site_id' => $siteA, 'client_id' => $clientA->id,
                'incident_type_id' => $typeId, 'incident_severity_id' => $sevId, 'incident_status_id' => $statusId,
                'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['subject' => 'En B', 'reporter_id' => $user->id, 'area_id' => $areaId, 'site_id' => $siteB, 'client_id' => $clientB->id,
                'incident_type_id' => $typeId, 'incident_severity_id' => $sevId, 'incident_status_id' => $statusId,
                'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $count = app(\App\Policies\IncidentPolicy::class)
            ->scopeFor($user, \App\Models\Incident::query())
            ->count();

        $this->assertSame(1, $count);
    }

    private function createBareUser(array $overrides = []): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $siteId = $this->createSite(null);

        return User::create(array_merge([
            'first_name' => 'T',
            'paternal_last_name' => 'U',
            'email' => uniqid().'@t.local',
            'password' => Hash::make('password'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId,
            'position_id' => $positionId,
            'site_id' => $siteId,
            'status' => 'active',
        ], $overrides));
    }

    private function createSite(?int $clientId): int
    {
        return DB::table('sites')->insertGetId([
            'name' => 'Sede '.uniqid(),
            'code' => 'C'.random_int(1000, 9999),
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
