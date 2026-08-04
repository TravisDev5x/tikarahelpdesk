<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'users.manage', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['slug' => 'admin']);
        Role::firstOrCreate(['name' => 'usuario', 'guard_name' => 'web'], ['slug' => 'usuario']);
    }

    public function test_invitation_without_role_creates_pending_admin_user(): void
    {
        $inviter = $this->bareUser(['email' => 'inviter@test.local']);
        $client = Client::create([
            'name' => 'Empresa X',
            'operator_user_id' => $this->bareUser(['is_operator' => true])->id,
            'is_active' => true,
        ]);

        $invitation = UserInvitation::create([
            'email' => 'nuevo@empresa.test',
            'token' => (string) Str::uuid(),
            'invited_by' => $inviter->id,
            'client_id' => $client->id,
            'role_id' => null,
            'status' => UserInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $accept = $this->post('/register/accept', [
            'token' => $invitation->token,
            'first_name' => 'Nuevo',
            'paternal_last_name' => 'Empleado',
            'password' => 'Segura123!@#',
            'password_confirmation' => 'Segura123!@#',
        ]);

        $accept->assertRedirect();
        $accept->assertSessionHasNoErrors();

        $user = User::where('email', 'nuevo@empresa.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('pending_admin', $user->status);
        $this->assertSame(0, $user->roles()->count());
        $this->assertSame(UserInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
    }

    public function test_create_invitation_on_strict_portal_forces_client_id(): void
    {
        config([
            'tenancy.base_domain' => 'tikara.test',
            'tenancy.strict_client_portal' => true,
        ]);

        $operator = $this->bareUser(['is_operator' => true]);
        $clientA = Client::create([
            'name' => 'Portal A',
            'portal_slug' => 'inv-portal-a',
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);
        $clientB = Client::create([
            'name' => 'Portal B',
            'portal_slug' => 'inv-portal-b',
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);

        $admin = $this->bareUser(['email' => 'portal-admin@test.local', 'client_id' => $clientA->id]);
        setPermissionsTeamId($clientA->id);
        $admin->assignRole('admin');
        $admin->givePermissionTo('users.manage');

        $request = Request::create('http://inv-portal-a.tikara.test/api/invitations', 'POST');
        $this->app->forgetInstance(TenantContextService::class);
        $this->app->instance('request', $request);
        app(TenantContextService::class)->resolve($request);

        $ok = $this->actingAs($admin, 'web')->postJson('/api/invitations', [
            'email' => 'staff-a@portal.test',
        ]);
        $ok->assertCreated();
        $this->assertSame($clientA->id, UserInvitation::where('email', 'staff-a@portal.test')->value('client_id'));

        Auth::guard('web')->logout();

        $requestB = Request::create('http://inv-portal-a.tikara.test/api/invitations', 'POST');
        $this->app->forgetInstance(TenantContextService::class);
        $this->app->instance('request', $requestB);
        app(TenantContextService::class)->resolve($requestB);

        $bad = $this->actingAs($admin, 'web')->postJson('/api/invitations', [
            'email' => 'staff-b@portal.test',
            'client_id' => $clientB->id,
        ]);
        $bad->assertUnprocessable();
    }

    public function test_accept_invitation_rejects_wrong_portal(): void
    {
        config([
            'tenancy.base_domain' => 'tikara.test',
            'tenancy.strict_client_portal' => true,
        ]);

        $operator = $this->bareUser(['is_operator' => true]);
        $clientA = Client::create([
            'name' => 'A',
            'portal_slug' => 'accept-a',
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);

        Client::create([
            'name' => 'B',
            'portal_slug' => 'accept-b',
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);

        $invitation = UserInvitation::create([
            'email' => 'wrong-portal@test.local',
            'token' => (string) Str::uuid(),
            'invited_by' => $operator->id,
            'client_id' => $clientA->id,
            'role_id' => null,
            'status' => UserInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $request = Request::create('http://accept-b.tikara.test/register/accept?token='.$invitation->token, 'GET');
        $this->app->forgetInstance(TenantContextService::class);
        $this->app->instance('request', $request);
        app(TenantContextService::class)->resolve($request);

        $response = $this->get('/register/accept?token='.$invitation->token);
        $response->assertOk();
        $props = $response->original->getData()['page']['props'] ?? [];
        $this->assertNull($props['token'] ?? null);
        $this->assertStringContainsString('portal', strtolower((string) ($props['error'] ?? '')));
    }

    public function test_legacy_invitation_with_role_activates_user_immediately(): void
    {
        $inviter = $this->bareUser(['email' => 'legacy-inv@test.local']);
        $role = Role::where('name', 'usuario')->first();

        $invitation = UserInvitation::create([
            'email' => 'legacy-user@test.local',
            'token' => (string) Str::uuid(),
            'invited_by' => $inviter->id,
            'client_id' => null,
            'role_id' => $role->id,
            'status' => UserInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $this->post('/register/accept', [
            'token' => $invitation->token,
            'first_name' => 'Legacy',
            'paternal_last_name' => 'User',
            'password' => 'Segura123!@#',
            'password_confirmation' => 'Segura123!@#',
        ])->assertRedirect();

        $user = User::where('email', 'legacy-user@test.local')->first();
        $this->assertNotNull($user);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->hasRole('usuario'));
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
