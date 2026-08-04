<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SessionMonitorScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'users.manage', 'guard_name' => 'web']);
        config(['session.driver' => 'database']);
    }

    public function test_sessions_index_excludes_other_operator_users(): void
    {
        $operatorA = $this->bareUser(['is_operator' => true]);
        $operatorB = $this->bareUser(['is_operator' => true, 'email' => 'op-b-sessions@test.local']);

        $clientA = Client::create([
            'name' => 'Cliente A',
            'operator_user_id' => $operatorA->id,
            'is_active' => true,
        ]);
        Client::create([
            'name' => 'Cliente B',
            'operator_user_id' => $operatorB->id,
            'is_active' => true,
        ]);

        $siteA = DB::table('sites')->insertGetId([
            'name' => 'Sede A',
            'code' => 'SA1',
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $clientA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede B',
            'code' => 'SB1',
            'type' => 'physical',
            'is_active' => true,
            'client_id' => Client::where('operator_user_id', $operatorB->id)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userA = $this->bareUser(['email' => 'user-a@sessions.test', 'site_id' => $siteA]);
        $userB = $this->bareUser(['email' => 'user-b@sessions.test', 'site_id' => $siteB]);

        $this->insertActiveSession($userA->id, 'sess-a');
        $this->insertActiveSession($userB->id, 'sess-b');

        // RBAC v2 (Fase 6): operatorA no tiene client_id propio (administra
        // varios) -- mismo centinela de plataforma que ApplyPgsqlTenantRls
        // usa para ese caso, ver su docblock.
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $operatorA->givePermissionTo('users.manage');

        $response = $this->actingAs($operatorA, 'web')->getJson('/api/sessions');

        $response->assertOk();
        $userIds = collect($response->json('sessions'))->pluck('user_id');
        $this->assertTrue($userIds->contains($userA->id));
        $this->assertFalse($userIds->contains($userB->id));
    }

    public function test_logout_user_rejects_foreign_operator_user(): void
    {
        $operatorA = $this->bareUser(['is_operator' => true]);
        $operatorB = $this->bareUser(['is_operator' => true, 'email' => 'op-b-logout@test.local']);

        $clientB = Client::create([
            'name' => 'Cliente B',
            'operator_user_id' => $operatorB->id,
            'is_active' => true,
        ]);
        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede B',
            'code' => 'SB2',
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $clientB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignUser = $this->bareUser(['email' => 'foreign@sessions.test', 'site_id' => $siteB]);
        $this->insertActiveSession($foreignUser->id, 'sess-foreign');

        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $operatorA->givePermissionTo('users.manage');

        $response = $this->actingAs($operatorA, 'web')->postJson('/api/sessions/logout-user', [
            'user_id' => $foreignUser->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('sessions', ['user_id' => $foreignUser->id]);
    }

    public function test_strict_portal_sessions_only_show_portal_client_users(): void
    {
        config([
            'tenancy.base_domain' => 'tikara.test',
            'tenancy.strict_client_portal' => true,
        ]);

        $operator = $this->bareUser(['is_operator' => true]);
        $clientA = Client::create([
            'name' => 'Portal A',
            'portal_slug' => 'sess-portal-a',
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);
        $clientB = Client::create([
            'name' => 'Portal B',
            'portal_slug' => 'sess-portal-b',
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);

        $siteA = DB::table('sites')->insertGetId([
            'name' => 'Sede PA',
            'code' => 'SPA',
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $clientA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede PB',
            'code' => 'SPB',
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $clientB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userA = $this->bareUser(['email' => 'pa-user@sessions.test', 'site_id' => $siteA, 'client_id' => $clientA->id]);
        $userB = $this->bareUser(['email' => 'pb-user@sessions.test', 'site_id' => $siteB, 'client_id' => $clientB->id]);

        $this->insertActiveSession($userA->id, 'sess-pa');
        $this->insertActiveSession($userB->id, 'sess-pb');

        $admin = $this->bareUser(['email' => 'portal-admin@sessions.test', 'site_id' => $siteA, 'client_id' => $clientA->id]);
        setPermissionsTeamId($clientA->id);
        $admin->givePermissionTo('users.manage');

        $request = Request::create('http://sess-portal-a.tikara.test/api/sessions', 'GET');
        $this->app->forgetInstance(TenantContextService::class);
        $this->app->instance('request', $request);
        app(TenantContextService::class)->resolve($request);

        $response = $this->actingAs($admin, 'web')->getJson('/api/sessions');

        $response->assertOk();
        $userIds = collect($response->json('sessions'))->pluck('user_id');
        $this->assertTrue($userIds->contains($userA->id));
        $this->assertFalse($userIds->contains($userB->id));
    }

    private function insertActiveSession(int $userId, string $sessionId): void
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function bareUser(array $overrides = []): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $siteId = DB::table('sites')->insertGetId([
            'name' => 'S'.uniqid(), 'code' => 'X'.uniqid(), 'type' => 'physical',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::create(array_merge([
            'first_name' => 'T', 'paternal_last_name' => 'U',
            'email' => uniqid().'@t.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => $siteId, 'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }
}
