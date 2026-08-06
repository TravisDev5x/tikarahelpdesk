<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\TenantContextService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientPortalTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_subdomain_resolves_client_portal_context(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $client = Client::create([
            'name' => 'Empresa Alpha',
            'portal_slug' => 'alpha',
            'is_active' => true,
        ]);

        $request = Request::create('http://alpha.tikara.test/login', 'GET');
        config(['tenancy.base_domain' => 'tikara.test']);

        $ctx = app(TenantContextService::class)->resolve($request);

        $this->assertTrue($ctx->isClientPortal());
        $this->assertSame($client->id, $ctx->clientId);
    }

    public function test_user_of_other_client_cannot_access_portal(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $op = $this->bareUser(['is_operator' => true]);
        $clientA = Client::create(['name' => 'A', 'portal_slug' => 'client-a', 'operator_user_id' => $op->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'B', 'portal_slug' => 'client-b', 'operator_user_id' => $op->id, 'is_active' => true]);

        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede B', 'code' => 'SB', 'type' => 'physical', 'is_active' => true,
            'client_id' => $clientB->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $userB = $this->bareUser(['email' => 'userb@test.local', 'site_id' => $siteB, 'client_id' => $clientB->id]);

        $request = Request::create('http://client-a.tikara.test/dashboard', 'GET');
        $request->setUserResolver(fn () => $userB);
        config(['tenancy.base_domain' => 'tikara.test', 'tenancy.strict_client_portal' => true]);

        $service = app(TenantContextService::class);
        $service->resolve($request);

        $this->assertFalse($service->userCanAccessCurrentPortal($userB));
    }

    public function test_login_rejected_on_wrong_client_portal(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $op = $this->bareUser(['is_operator' => true]);
        $clientA = Client::create(['name' => 'A', 'portal_slug' => 'client-a', 'operator_user_id' => $op->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'B', 'portal_slug' => 'client-b', 'operator_user_id' => $op->id, 'is_active' => true]);

        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede B', 'code' => 'SB2', 'type' => 'physical', 'is_active' => true,
            'client_id' => $clientB->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $userB = $this->bareUser([
            'email' => 'portal-user@test.local',
            'password' => Hash::make('SecretPass123!'),
            'site_id' => $siteB,
            'client_id' => $clientB->id,
        ]);
        $userB->forceFill(['email_verified_at' => now()])->save();

        config(['tenancy.base_domain' => 'tikara.test', 'tenancy.strict_client_portal' => true]);

        $response = $this->postJson('http://client-a.tikara.test/api/login', [
            'identifier' => 'portal-user@test.local',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.root', 'No tienes acceso a este portal. Inicia sesión en la URL de tu organización.');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'tenant_boundary',
            'action' => 'login_rejected',
            'client_id' => $clientA->id,
            'user_id' => $userB->id,
        ]);
    }

    public function test_authenticated_access_to_wrong_portal_is_logged(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $op = $this->bareUser(['is_operator' => true]);
        $clientA = Client::create(['name' => 'A', 'portal_slug' => 'client-a', 'operator_user_id' => $op->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'B', 'portal_slug' => 'client-b', 'operator_user_id' => $op->id, 'is_active' => true]);

        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede B', 'code' => 'SB3', 'type' => 'physical', 'is_active' => true,
            'client_id' => $clientB->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $userB = $this->bareUser(['email' => 'access-blocked@test.local', 'site_id' => $siteB, 'client_id' => $clientB->id]);

        config(['tenancy.base_domain' => 'tikara.test', 'tenancy.strict_client_portal' => true]);

        $response = $this->actingAs($userB)
            ->getJson('http://client-a.tikara.test/api/ping');

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'tenant_boundary',
            'action' => 'access_blocked',
            'client_id' => $clientA->id,
            'user_id' => $userB->id,
        ]);
    }

    private function bareUser(array $overrides = []): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $siteId = DB::table('sites')->insertGetId([
            'name' => 'Sede '.uniqid(),
            'code' => 'C'.uniqid(),
            'type' => 'physical',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
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
