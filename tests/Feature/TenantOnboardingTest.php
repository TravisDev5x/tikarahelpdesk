<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Mail\UserInvitation as UserInvitationMail;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
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

        // 7.3 sigue -- ya no es el último paso construido, a diferencia de
        // cuando este test se escribió (7.1-7.2 nada más).
        $response->assertRedirect('/onboarding/company');

        $user->refresh();
        $this->assertNotNull($user->client_id);
        $this->assertFalse((bool) $user->onboarding_completed, 'onboarding_completed debe quedarse false -- 7.3/7.4 siguen pendientes.');

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
            ->assertRedirect('/onboarding/company');

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
            ->assertRedirect('/onboarding/company');

        $userB = $this->makeFreshUser();
        $this->actingAs($userB, 'web')->post('/onboarding', ['business_name' => 'Tenant Dos'])
            ->assertRedirect('/onboarding/company');

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

    public function test_user_with_client_already_set_is_redirected_to_the_next_step_not_repeat_the_form(): void
    {
        $user = $this->makeFreshUser();
        $this->actingAs($user, 'web')->post('/onboarding', ['business_name' => 'Ya Completado'])
            ->assertRedirect('/onboarding/company');

        $client = Client::findOrFail($user->fresh()->client_id);
        $this->assertSame('tenant_named', $client->onboarding_step);

        // Revisitar /onboarding (7.2) con client_id ya resuelto no repite el
        // formulario -- manda al paso que le toca según onboarding_step.
        $this->actingAs($user->fresh(), 'web')->get('/onboarding')
            ->assertRedirect('/onboarding/company');

        $this->assertSame('tenant_named', $client->fresh()->onboarding_step);
    }

    // ── 7.3: datos de empresa ────────────────────────────────────────────

    private function userAtStep(string $step): array
    {
        $user = $this->makeFreshUser();
        $this->actingAs($user, 'web')->post('/onboarding', ['business_name' => 'Tenant '.uniqid()]);
        $user->refresh();
        $client = Client::findOrFail($user->client_id);

        if ($step !== 'tenant_named') {
            $client->update(['onboarding_step' => $step]);
        }

        return [$user, $client->fresh()];
    }

    public function test_company_data_step_saves_fields_and_advances_state(): void
    {
        [$user] = $this->userAtStep('tenant_named');

        $response = $this->actingAs($user, 'web')->post('/onboarding/company', [
            'legal_name' => 'Distribuidora del Valle S.A. de C.V.',
            'tax_id' => 'DVA010101AB1',
            'contact_phone' => '5512345678',
            'website' => 'https://distribuidoradelvalle.mx',
            'address' => 'Av. Reforma 123',
            'city' => 'Ciudad de México',
            'country' => 'MX',
        ]);

        $response->assertRedirect('/onboarding/modality');

        $client = Client::findOrFail($user->fresh()->client_id);
        $this->assertSame('company_data', $client->onboarding_step);
        $this->assertSame('Distribuidora del Valle S.A. de C.V.', $client->legal_name);
        $this->assertSame('DVA010101AB1', $client->tax_id);
        $this->assertSame('5512345678', $client->contact_phone);
        $this->assertSame('https://distribuidoradelvalle.mx', $client->website);
        $this->assertSame('Av. Reforma 123', $client->address);
        $this->assertSame('Ciudad de México', $client->city);
        $this->assertSame('MX', $client->country);
        $this->assertFalse((bool) $user->fresh()->onboarding_completed);
    }

    public function test_company_data_step_requires_address_city_country_but_not_the_rest(): void
    {
        [$user] = $this->userAtStep('tenant_named');

        $this->actingAs($user, 'web')->post('/onboarding/company', [
            'address' => 'Av. Reforma 123',
            'city' => 'Ciudad de México',
            'country' => 'MX',
        ])->assertRedirect('/onboarding/modality');

        $this->assertSame('company_data', Client::findOrFail($user->fresh()->client_id)->onboarding_step);
    }

    /**
     * tax_id no tiene ninguna validación de negocio (no se verifica contra
     * el SAT ni ninguna otra fuente externa) -- solo el regex de FORMATO
     * heredado del wizard legacy. Un RFC con formato válido pero inventado
     * se guarda tal cual y no bloquea el flujo.
     */
    public function test_tax_id_has_no_business_validation_only_format_and_is_saved_as_is(): void
    {
        [$user] = $this->userAtStep('tenant_named');

        $this->actingAs($user, 'web')->post('/onboarding/company', [
            'tax_id' => 'XXX010101XX1', // formato válido, RFC inventado
            'address' => 'Calle Falsa 123',
            'city' => 'Guadalajara',
            'country' => 'MX',
        ])->assertRedirect('/onboarding/modality');

        $client = Client::findOrFail($user->fresh()->client_id);
        $this->assertSame('company_data', $client->onboarding_step);
        $this->assertSame('XXX010101XX1', $client->tax_id);
    }

    public function test_company_data_step_is_unreachable_before_naming_the_tenant(): void
    {
        $user = $this->makeFreshUser();

        $this->actingAs($user, 'web')->get('/onboarding/company')->assertRedirect('/onboarding');
    }

    // ── 7.4: modalidad ───────────────────────────────────────────────────

    public function test_modality_step_saves_the_three_possible_values(): void
    {
        foreach (['internal', 'msp', 'hybrid'] as $mode) {
            [$user] = $this->userAtStep('company_data');

            $this->actingAs($user, 'web')->post('/onboarding/modality', ['mode' => $mode]);

            $this->assertSame($mode, Client::findOrFail($user->fresh()->client_id)->mode, "mode={$mode} no se guardó.");
        }
    }

    public function test_internal_mode_skips_customers_step_and_continues_to_staff(): void
    {
        [$user] = $this->userAtStep('company_data');

        $response = $this->actingAs($user, 'web')->post('/onboarding/modality', ['mode' => 'internal']);

        $response->assertRedirect('/onboarding/staff');

        $user->refresh();
        $this->assertFalse((bool) $user->onboarding_completed, 'onboarding_completed debe quedarse false -- 7.6 sigue pendiente.');
        $this->assertSame('customers_skipped', Client::findOrFail($user->client_id)->onboarding_step);

        // Confirma que redirectPath() también lo deja pasar de ahí en adelante.
        $this->actingAs($user, 'web')->get('/onboarding/customers')->assertRedirect('/onboarding/staff');
    }

    public function test_msp_mode_continues_to_customers_step(): void
    {
        [$user] = $this->userAtStep('company_data');

        $response = $this->actingAs($user, 'web')->post('/onboarding/modality', ['mode' => 'msp']);

        $response->assertRedirect('/onboarding/customers');
        $this->assertFalse((bool) $user->fresh()->onboarding_completed);
        $this->assertSame('modality_set', Client::findOrFail($user->fresh()->client_id)->onboarding_step);
    }

    public function test_modality_step_is_unreachable_before_company_data(): void
    {
        [$user] = $this->userAtStep('tenant_named');

        $this->actingAs($user, 'web')->get('/onboarding/modality')->assertRedirect('/onboarding/company');
    }

    // ── 7.5: Customers/Sites externos ────────────────────────────────────

    public function test_customers_step_creates_external_customer_with_sites_under_the_correct_tenant(): void
    {
        [$user, $client] = $this->userAtStep('modality_set');
        $client->update(['mode' => 'msp']);
        $otherClient = Client::create(['name' => 'Otro Tenant', 'portal_slug' => 'otro-tenant-'.uniqid(), 'is_active' => true]);

        $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => 'Cliente Externo SA',
            'customer_address' => 'Insurgentes Sur 100',
            'sites' => [
                ['name' => 'Matriz', 'address' => 'Insurgentes Sur 100'],
                ['name' => 'Sucursal Norte', 'address' => 'Av. Universidad 200'],
            ],
        ])->assertRedirect('/onboarding/customers');

        // sites TAMBIÉN tiene RLS -- hay que cargar la relación mientras el
        // bypass sigue activo (->sites() sin cargar dispara una query nueva
        // fuera del bypass y vuelve a caer en el mismo problema que ya
        // resolvimos para Customer).
        PgsqlRowLevelSecurity::setBypass(true);
        $customer = Customer::where('client_id', $client->id)->where('is_internal', false)->with('sites')->first();
        PgsqlRowLevelSecurity::clear();

        $this->assertNotNull($customer);
        $this->assertSame('Cliente Externo SA', $customer->name);
        $this->assertSame(2, $customer->sites->count());
        $this->assertSame($client->id, $customer->client_id);
        $this->assertNotSame($otherClient->id, $customer->client_id, 'El Customer externo no debe quedar bajo otro tenant.');

        foreach ($customer->sites as $site) {
            $this->assertSame($client->id, $site->client_id);
            $this->assertSame($customer->id, $site->customer_id);
        }
    }

    public function test_customers_step_requires_at_least_one_site(): void
    {
        [$user] = $this->userAtStep('modality_set');

        $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => 'Cliente Sin Sede',
            'customer_address' => 'Algún lado',
            'sites' => [],
        ])->assertSessionHasErrors('sites');
    }

    /**
     * sites tiene unique(client_id, name) -- TENANT completo, no por
     * Customer. Dos Customers externos del mismo tenant pidiendo ambos una
     * sede "Matriz" reventaba con un UniqueConstraintViolationException
     * crudo antes de este test (encontrado escribiendo la integración de
     * este mismo sprint) -- ahora se valida con un mensaje claro.
     */
    public function test_customers_step_rejects_a_site_name_already_used_by_another_customer_of_the_same_tenant(): void
    {
        [$user] = $this->userAtStep('modality_set');

        $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => 'Cliente Uno',
            'customer_address' => 'Dir 1',
            'sites' => [['name' => 'Matriz', 'address' => 'Dir 1']],
        ])->assertRedirect('/onboarding/customers');

        $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => 'Cliente Dos',
            'customer_address' => 'Dir 2',
            'sites' => [['name' => 'Matriz', 'address' => 'Dir 2']],
        ])->assertSessionHasErrors('sites.0.name');

        $this->assertSame(0, Customer::where('name', 'Cliente Dos')->count(), 'No debía crearse el Customer si su sede colisiona.');
    }

    public function test_customers_step_rejects_duplicate_site_names_within_the_same_submission(): void
    {
        [$user] = $this->userAtStep('modality_set');

        $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => 'Cliente Con Sedes Repetidas',
            'customer_address' => 'Dir 1',
            'sites' => [
                ['name' => 'Sucursal', 'address' => 'Dir 1'],
                ['name' => 'Sucursal', 'address' => 'Dir 2'],
            ],
        ])->assertSessionHasErrors('sites.0.name');
    }

    public function test_customers_step_allows_adding_more_than_one_customer_before_finishing(): void
    {
        [$user, $client] = $this->userAtStep('modality_set');

        $addCustomer = fn (string $name) => $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => $name,
            'customer_address' => 'Dirección de '.$name,
            'sites' => [['name' => 'Matriz de '.$name, 'address' => 'Dirección de '.$name]],
        ]);

        $addCustomer('Cliente Uno')->assertRedirect('/onboarding/customers');
        $addCustomer('Cliente Dos')->assertRedirect('/onboarding/customers');

        PgsqlRowLevelSecurity::setBypass(true);
        $count = Customer::where('client_id', $client->id)->where('is_internal', false)->count();
        PgsqlRowLevelSecurity::clear();

        $this->assertSame(2, $count);
        // Sigue sin completarse -- agregar clientes no es lo mismo que terminar el paso.
        $this->assertFalse((bool) $user->fresh()->onboarding_completed);
    }

    public function test_finishing_customers_step_advances_to_staff_without_completing_onboarding(): void
    {
        [$user, $client] = $this->userAtStep('modality_set');

        $this->actingAs($user, 'web')->post('/onboarding/customers/finish')
            ->assertRedirect('/onboarding/staff');

        $this->assertFalse((bool) $user->fresh()->onboarding_completed, 'onboarding_completed debe quedarse false -- 7.6 sigue pendiente.');
        $this->assertSame('customers_added', $client->fresh()->onboarding_step);
    }

    public function test_customers_step_is_unreachable_before_modality(): void
    {
        [$user] = $this->userAtStep('company_data');

        $this->actingAs($user, 'web')->get('/onboarding/customers')->assertRedirect('/onboarding/modality');
    }

    // ── Integración: tramo completo ──────────────────────────────────────

    public function test_full_flow_msp_mode_ends_at_customers_added_with_two_customers(): void
    {
        $user = $this->makeFreshUser();

        $this->actingAs($user, 'web')->post('/onboarding', ['business_name' => 'MSP Integral'])
            ->assertRedirect('/onboarding/company');

        $this->actingAs($user, 'web')->post('/onboarding/company', [
            'address' => 'Calle 1', 'city' => 'CDMX', 'country' => 'MX',
        ])->assertRedirect('/onboarding/modality');

        $this->actingAs($user, 'web')->post('/onboarding/modality', ['mode' => 'msp'])
            ->assertRedirect('/onboarding/customers');

        $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => 'Cliente A', 'customer_address' => 'Dir A',
            'sites' => [['name' => 'Matriz A', 'address' => 'Dir A']],
        ])->assertRedirect('/onboarding/customers');

        $this->actingAs($user, 'web')->post('/onboarding/customers', [
            'customer_name' => 'Cliente B', 'customer_address' => 'Dir B',
            'sites' => [['name' => 'Matriz B', 'address' => 'Dir B']],
        ])->assertRedirect('/onboarding/customers');

        $this->actingAs($user, 'web')->post('/onboarding/customers/finish')
            ->assertRedirect('/onboarding/staff');

        $user->refresh();
        $client = Client::findOrFail($user->client_id);
        $this->assertFalse((bool) $user->onboarding_completed, 'onboarding_completed debe quedarse false -- 7.6 sigue pendiente.');
        $this->assertSame('customers_added', $client->onboarding_step);
        $this->assertSame('msp', $client->mode);

        PgsqlRowLevelSecurity::setBypass(true);
        $count = Customer::where('client_id', $client->id)->where('is_internal', false)->count();
        PgsqlRowLevelSecurity::clear();
        $this->assertSame(2, $count);

        $this->actingAs($user, 'web')->post('/onboarding/staff/finish')
            ->assertRedirect('/home');

        $user->refresh();
        $this->assertTrue((bool) $user->onboarding_completed);
        $this->assertSame('staff_invited', $client->fresh()->onboarding_step);
        $this->assertNull(app(\App\Services\OnboardingRedirectService::class)->redirectPath($user));
    }

    public function test_full_flow_internal_mode_skips_customers_and_ends_at_the_same_7_6_exit_point(): void
    {
        $user = $this->makeFreshUser();

        $this->actingAs($user, 'web')->post('/onboarding', ['business_name' => 'Interno Integral'])
            ->assertRedirect('/onboarding/company');

        $this->actingAs($user, 'web')->post('/onboarding/company', [
            'address' => 'Calle 1', 'city' => 'CDMX', 'country' => 'MX',
        ])->assertRedirect('/onboarding/modality');

        $this->actingAs($user, 'web')->post('/onboarding/modality', ['mode' => 'internal'])
            ->assertRedirect('/onboarding/staff');

        $user->refresh();
        $client = Client::findOrFail($user->client_id);
        $this->assertFalse((bool) $user->onboarding_completed, 'onboarding_completed debe quedarse false -- 7.6 sigue pendiente.');
        $this->assertSame('customers_skipped', $client->onboarding_step);
        $this->assertSame('internal', $client->mode);

        $this->actingAs($user, 'web')->post('/onboarding/staff/finish')
            ->assertRedirect('/home');

        $user->refresh();
        $this->assertTrue((bool) $user->onboarding_completed);
        $this->assertSame('staff_invited', $client->fresh()->onboarding_step);

        // Mismo punto de salida que el flujo MSP -- ninguno de los dos se
        // queda con un redirect de onboarding pendiente.
        $this->assertNull(app(\App\Services\OnboardingRedirectService::class)->redirectPath($user));
    }

    // ── 7.6: invitar personal ────────────────────────────────────────────

    public function test_staff_step_is_unreachable_before_customers_step_completes(): void
    {
        [$user] = $this->userAtStep('modality_set');

        $this->actingAs($user, 'web')->get('/onboarding/staff')->assertRedirect('/onboarding/customers');
    }

    public function test_staff_step_invites_someone_reusing_invitation_controller(): void
    {
        Mail::fake();
        [$user, $client] = $this->userAtStep('customers_added');

        $response = $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'nueva-agente@example.test',
            'role' => 'agente',
        ]);

        $response->assertRedirect('/onboarding/staff');

        $invitation = UserInvitation::where('email', 'nueva-agente@example.test')->firstOrFail();
        $this->assertSame($client->id, $invitation->client_id);
        $this->assertSame(UserInvitation::STATUS_PENDING, $invitation->status);
        // Auth::user() dentro de InvitationController::store(), invocado vía
        // Request::create() sintético, sigue resolviendo al admin fundador
        // real de la sesión -- no null, no otro usuario.
        $this->assertSame($user->id, $invitation->invited_by);

        $role = Role::findOrFail($invitation->role_id);
        $this->assertSame('agente', $role->name);
        $this->assertSame($client->id, $role->team_id);

        Mail::assertQueued(UserInvitationMail::class);
    }

    /**
     * "email inválido" nunca llega a InvitationController::store() -- lo
     * atrapa la validación PROPIA de storeStaff() ('email' => 'required|email')
     * antes de delegar. Confirma eso explícitamente (no se crea nada, error
     * en 'email'), y por separado cubre los 2 casos que SÍ dependen de la
     * validación interna de InvitationController::store() (ver los 2 tests
     * siguientes): email ya usado por un usuario existente, y doble
     * invitación al mismo correo dentro del mismo flujo.
     */
    public function test_staff_step_rejects_malformed_email_before_ever_reaching_invitation_controller(): void
    {
        [$user] = $this->userAtStep('customers_added');

        $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'no-es-un-correo',
            'role' => 'agente',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('user_invitations', ['email' => 'no-es-un-correo']);
    }

    /**
     * El ValidationException que lanza InvitationController::store() (no uno
     * de storeStaff()) tiene que propagarse igual de bien a la respuesta
     * Inertia real, aunque venga de una invocación sintética por
     * Request::create() en vez de una request real de router.
     */
    public function test_staff_step_propagates_validation_error_when_email_already_belongs_to_an_existing_user(): void
    {
        [$user, $client] = $this->userAtStep('customers_added');
        $existing = User::where('client_id', $client->id)->firstOrFail();

        $response = $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => $existing->email,
            'role' => 'agente',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('user_invitations', ['email' => $existing->email]);
    }

    public function test_staff_step_propagates_validation_error_when_inviting_the_same_email_twice_in_the_same_flow(): void
    {
        Mail::fake();
        [$user] = $this->userAtStep('customers_added');

        $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'repetida@example.test',
            'role' => 'agente',
        ])->assertRedirect('/onboarding/staff');

        $response = $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'repetida@example.test',
            'role' => 'solicitante',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(
            1,
            UserInvitation::where('email', 'repetida@example.test')->count(),
            'La segunda invitación al mismo correo no debía crear una fila nueva.'
        );
    }

    public function test_staff_step_rejects_admin_as_an_invitable_role_even_forcing_the_request(): void
    {
        [$user] = $this->userAtStep('customers_added');

        $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'quiere-ser-admin@example.test',
            'role' => 'admin',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('user_invitations', ['email' => 'quiere-ser-admin@example.test']);
    }

    public function test_staff_step_client_id_is_explicit_even_off_portal_and_two_tenants_with_same_role_name_do_not_collide(): void
    {
        Mail::fake();
        [$userA, $clientA] = $this->userAtStep('customers_added');
        [$userB, $clientB] = $this->userAtStep('customers_added');

        $this->actingAs($userA, 'web')->post('/onboarding/staff', [
            'email' => 'agente-a@example.test',
            'role' => 'agente',
        ])->assertRedirect('/onboarding/staff');

        $this->actingAs($userB, 'web')->post('/onboarding/staff', [
            'email' => 'agente-b@example.test',
            'role' => 'agente',
        ])->assertRedirect('/onboarding/staff');

        $invitationA = UserInvitation::where('email', 'agente-a@example.test')->firstOrFail();
        $invitationB = UserInvitation::where('email', 'agente-b@example.test')->firstOrFail();

        $this->assertSame($clientA->id, $invitationA->client_id);
        $this->assertSame($clientB->id, $invitationB->client_id);
        $this->assertNotSame($invitationA->role_id, $invitationB->role_id, 'Cada tenant debe usar SU propio rol "agente", no colisionar en uno global.');

        $roleA = Role::findOrFail($invitationA->role_id);
        $roleB = Role::findOrFail($invitationB->role_id);
        $this->assertSame($clientA->id, $roleA->team_id);
        $this->assertSame($clientB->id, $roleB->team_id);
    }

    public function test_staff_step_rejects_invitation_once_plan_seat_limit_is_reached(): void
    {
        Mail::fake();
        [$user, $client] = $this->userAtStep('customers_added');

        $plan = Plan::create([
            'name' => 'Arranque', 'slug' => 'arranque-'.uniqid(),
            'type' => 'both', 'max_users' => 2,
        ]);
        $client->update(['plan_id' => $plan->id]);
        // El propio fundador ya cuenta como 1 usuario activo -- deja 1 cupo libre.

        $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'cabe@example.test',
            'role' => 'agente',
        ])->assertRedirect('/onboarding/staff');

        $response = $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'no-cabe@example.test',
            'role' => 'agente',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('user_invitations', ['email' => 'no-cabe@example.test']);
        // La primera invitación, que sí cabía, se queda -- el rechazo es por invitación, no por paso completo.
        $this->assertDatabaseHas('user_invitations', ['email' => 'cabe@example.test']);
    }

    public function test_staff_step_ignores_expired_invitations_and_blocked_users_when_counting_seats(): void
    {
        Mail::fake();
        [$user, $client] = $this->userAtStep('customers_added');

        $plan = Plan::create([
            'name' => 'Arranque', 'slug' => 'arranque-'.uniqid(),
            'type' => 'both', 'max_users' => 2,
        ]);
        $client->update(['plan_id' => $plan->id]);

        // Invitación YA expirada -- no debe contar contra el cupo.
        UserInvitation::create([
            'email' => 'vieja@example.test', 'token' => (string) \Illuminate\Support\Str::uuid(),
            'invited_by' => $user->id, 'client_id' => $client->id,
            'status' => UserInvitation::STATUS_PENDING, 'expires_at' => now()->subDay(),
        ]);
        // Usuario bloqueado del mismo tenant -- tampoco cuenta.
        User::create([
            'first_name' => 'Bloqueado', 'paternal_last_name' => 'Test',
            'email' => 'bloqueado-'.uniqid().'@example.test', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'client_id' => $client->id, 'status' => 'blocked', 'onboarding_completed' => true,
        ]);

        // Fundador (1 activo) + esta nueva invitación (2) == max_users (2) -- todavía cabe.
        $this->actingAs($user, 'web')->post('/onboarding/staff', [
            'email' => 'si-cabe@example.test',
            'role' => 'agente',
        ])->assertRedirect('/onboarding/staff');

        $this->assertDatabaseHas('user_invitations', ['email' => 'si-cabe@example.test']);
    }

    public function test_finishing_staff_step_completes_onboarding_even_without_inviting_anyone(): void
    {
        [$user, $client] = $this->userAtStep('customers_skipped');

        $this->actingAs($user, 'web')->post('/onboarding/staff/finish')
            ->assertRedirect('/home');

        $this->assertTrue((bool) $user->fresh()->onboarding_completed);
        $this->assertSame('staff_invited', $client->fresh()->onboarding_step);
    }
}
