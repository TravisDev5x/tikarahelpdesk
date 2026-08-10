<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 7 (onboarding de tenant nuevo, 2026-08-09): reemplaza el wizard
 * legacy de operador. Cubre solo lo construido este sprint -- registro +
 * consentimiento (ya vive en AuthController::register) y sub-paso 7.2
 * (nombre del tenant, TenantOnboardingController). 7.3 en adelante queda
 * para la siguiente fase.
 */
class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    // AuthController::register() requiere un site 'REMOTO' resoluble cuando
    // no se manda site_id explícito -- ya lo siembra la propia migración
    // 2026_07_09_000007_create_sites_table.php, no hace falta insertarlo.

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'paternal_last_name' => 'García',
            'maternal_last_name' => null,
            'email' => 'ana-'.uniqid().'@example.test',
            'phone' => null,
            'password' => 'Sup3rSecure!Pass1',
            'password_confirmation' => 'Sup3rSecure!Pass1',
            'privacy_notice_accepted' => true,
        ], $overrides);
    }

    public function test_register_creates_user_without_client_with_consent_recorded(): void
    {
        Mail::fake();

        $payload = $this->registerPayload();
        $response = $this->postJson('/api/register', $payload, ['REMOTE_ADDR' => '203.0.113.7']);

        $response->assertStatus(201);

        $user = User::where('email', $payload['email'])->firstOrFail();
        $this->assertNull($user->client_id);
        $this->assertFalse((bool) $user->is_operator);
        $this->assertNotNull($user->privacy_notice_accepted_at);
        $this->assertSame(AuthController::PRIVACY_NOTICE_VERSION, $user->privacy_notice_version);
        $this->assertSame('203.0.113.7', $user->privacy_notice_ip);
    }

    public function test_register_without_accepting_privacy_notice_is_rejected(): void
    {
        Mail::fake();

        $payload = $this->registerPayload(['privacy_notice_accepted' => false]);
        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['privacy_notice_accepted']);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    private function makeFreshUser(): User
    {
        return User::create([
            'first_name' => 'Fundador', 'paternal_last_name' => 'Test',
            'email' => 'fundador-'.uniqid().'@example.test',
            'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'site_id' => DB::table('sites')->value('id'),
            'client_id' => null,
            'is_operator' => false,
            'onboarding_completed' => false,
            'status' => 'active',
            'privacy_notice_accepted_at' => now(),
            'privacy_notice_version' => AuthController::PRIVACY_NOTICE_VERSION,
            'privacy_notice_ip' => '127.0.0.1',
        ]);
    }

    public function test_reserved_tenant_name_is_rejected(): void
    {
        $user = $this->makeFreshUser();

        $response = $this->actingAs($user, 'web')->post('/onboarding', [
            'business_name' => 'Admin',
        ]);

        $response->assertSessionHasErrors('business_name');
        $this->assertNull($user->fresh()->client_id);
        $this->assertSame(0, Client::where('name', 'Admin')->count());
    }

    public function test_completing_tenant_name_creates_client_with_everything_wired(): void
    {
        $user = $this->makeFreshUser();

        $response = $this->actingAs($user, 'web')->post('/onboarding', [
            'business_name' => 'Distribuidora del Valle',
        ]);

        $response->assertRedirect('/home');

        $user->refresh();
        $this->assertNotNull($user->client_id);
        $this->assertTrue((bool) $user->onboarding_completed);

        $client = Client::findOrFail($user->client_id);
        $this->assertNotNull($client->portal_slug);
        $this->assertNotSame('', $client->portal_slug);
        $this->assertSame('internal', $client->mode);
        $this->assertSame('tenant_named', $client->onboarding_step);
        $this->assertNotNull($client->ticket_prefix, 'ticket_prefix debía auto-asignarse vía Client::booted()');

        // customers tiene RLS bajo Postgres real -- fuera de un request real
        // (que ApplyPgsqlTenantRls ya limpió al terminar el POST de arriba,
        // vía PgsqlRowLevelSecurity::clear() en su finally), esta query
        // corre sin ningún contexto de tenant que la policy reconozca.
        // Mismo bypass puntual que ya usa CustomerHierarchyTest para esto.
        PgsqlRowLevelSecurity::setBypass(true);
        $customer = Customer::where('client_id', $client->id)->where('is_internal', true)->first();
        PgsqlRowLevelSecurity::clear();

        $this->assertNotNull($customer, 'Customer implícito debía crearse automáticamente');
        $this->assertSame($client->name, $customer->name);

        // Las 5 plantillas por defecto, sembradas dentro del team_id real del tenant.
        foreach (['admin', 'supervisor', 'agente', 'solicitante', 'Encargado TI'] as $roleName) {
            $this->assertDatabaseHas('roles', [
                'name' => $roleName,
                'team_id' => $client->id,
                'guard_name' => 'web',
            ]);
        }

        // El fundador quedó con el rol admin del TEAM REAL, no por nombre a secas.
        $adminRole = Role::where('name', 'admin')->where('team_id', $client->id)->firstOrFail();
        $this->assertSame(
            1,
            DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', User::class)
                ->where('role_id', $adminRole->id)
                ->where('team_id', $client->id)
                ->count()
        );
    }

    /**
     * Reproduce el placeholder de bootstrap que AuthController::verifyEmail()
     * asigna en el team_id centinela cuando el usuario todavía no tenía
     * Client -- confirma que TenantOnboardingController lo limpia al crear
     * el tenant real, sin dejar al fundador con dos roles 'admin' en dos
     * teams distintos.
     */
    public function test_completing_tenant_name_clears_the_sentinel_team_placeholder_role(): void
    {
        $user = $this->makeFreshUser();

        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $sentinelAdminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web', 'team_id' => config('tenancy.super_admin_team_id')],
            ['slug' => 'admin']
        );
        $user->syncRoles([$sentinelAdminRole]);

        $this->assertSame(
            1,
            DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('team_id', config('tenancy.super_admin_team_id'))
                ->count()
        );

        $this->actingAs($user, 'web')->post('/onboarding', ['business_name' => 'Consultoria Nortena'])
            ->assertRedirect('/home');

        $this->assertSame(
            0,
            DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('team_id', config('tenancy.super_admin_team_id'))
                ->count(),
            'El placeholder del team_id centinela debía quedar limpio tras crear el tenant real.'
        );
    }

    /**
     * Dos tenants distintos, ambos con un rol 'admin' -- el fundador de cada
     * uno debe terminar con el 'admin' de SU team, nunca el del otro. Es el
     * test explícito de que no reaparece el footgun de Role::findByName()
     * (assignRole('nombre') sin filtrar team_id) que ya mordió 2 veces.
     */
    public function test_two_tenants_created_via_onboarding_do_not_collide_on_admin_role(): void
    {
        $userA = $this->makeFreshUser();
        $this->actingAs($userA, 'web')->post('/onboarding', ['business_name' => 'Tenant Uno'])
            ->assertRedirect('/home');

        $userB = $this->makeFreshUser();
        $this->actingAs($userB, 'web')->post('/onboarding', ['business_name' => 'Tenant Dos'])
            ->assertRedirect('/home');

        $clientA = Client::findOrFail($userA->fresh()->client_id);
        $clientB = Client::findOrFail($userB->fresh()->client_id);
        $this->assertNotSame($clientA->id, $clientB->id);

        $adminRoleA = Role::where('name', 'admin')->where('team_id', $clientA->id)->firstOrFail();
        $adminRoleB = Role::where('name', 'admin')->where('team_id', $clientB->id)->firstOrFail();
        $this->assertNotSame($adminRoleA->id, $adminRoleB->id);

        $this->assertTrue(
            DB::table('model_has_roles')->where('model_id', $userA->id)->where('role_id', $adminRoleA->id)->exists()
        );
        $this->assertFalse(
            DB::table('model_has_roles')->where('model_id', $userA->id)->where('role_id', $adminRoleB->id)->exists(),
            'userA no debe tener el admin del tenant de userB.'
        );
        $this->assertFalse(
            DB::table('model_has_roles')->where('model_id', $userB->id)->where('role_id', $adminRoleA->id)->exists(),
            'userB no debe tener el admin del tenant de userA.'
        );
    }

    public function test_user_with_client_already_set_is_redirected_away_from_onboarding_form(): void
    {
        $user = $this->makeFreshUser();
        $this->actingAs($user, 'web')->post('/onboarding', ['business_name' => 'Ya Completado'])
            ->assertRedirect('/home');

        $client = Client::findOrFail($user->fresh()->client_id);
        $this->assertSame('tenant_named', $client->onboarding_step);

        // "Retomar" en el alcance de este sprint: con client_id ya resuelto,
        // /onboarding no vuelve a mostrar el formulario (7.3+ es lo que
        // continuaría la máquina de estados desde 'tenant_named' en adelante).
        $this->actingAs($user->fresh(), 'web')->get('/onboarding')
            ->assertRedirect('/home');

        $this->assertSame('tenant_named', $client->fresh()->onboarding_step);
    }
}
