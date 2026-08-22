<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditoría de Inventario, fase 4.1 (pantalla de configuración opcional de
 * Entra ID/Intune/AD). Solo cubre la pantalla de config + prueba de
 * conexión -- no hay sincronización real de datos todavía.
 */
class InvIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_config', 'guard_name' => 'web']);
    }

    private function tenantAdmin(): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_config');

        return [$client, $admin];
    }

    public function test_saving_entra_id_config_persists_encrypted_and_reports_connected(): void
    {
        [$client, $admin] = $this->tenantAdmin();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok123'], 200),
            'graph.microsoft.com/v1.0/organization' => Http::response(['value' => [['displayName' => 'Acme']]], 200),
        ]);

        $response = $this->actingAs($admin, 'web')->putJson('/api/inv-integrations/entra_id', [
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'super-secreto',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'connected');
        $response->assertJsonMissing(['config']);
        $response->assertJsonMissing(['client_secret']);
        $this->assertStringNotContainsString('super-secreto', $response->getContent());

        $this->assertDatabaseHas('inv_integrations', [
            'client_id' => $client->id, 'provider' => 'entra_id', 'status' => 'connected',
        ]);

        $raw = DB::table('inv_integrations')->where('client_id', $client->id)->where('provider', 'entra_id')->value('config');
        $this->assertStringNotContainsString('super-secreto', $raw);
        $decoded = json_decode(Crypt::decryptString($raw), true);
        $this->assertSame('super-secreto', $decoded['client_secret']);
    }

    public function test_saving_with_rejected_credentials_sets_error_status(): void
    {
        [, $admin] = $this->tenantAdmin();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'Credenciales inválidas'], 401),
        ]);

        $response = $this->actingAs($admin, 'web')->putJson('/api/inv-integrations/entra_id', [
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'mal-secreto',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'error');
        $this->assertStringContainsString('Credenciales inválidas', $response->json('status_message'));
    }

    public function test_editing_without_secret_keeps_the_previous_one(): void
    {
        [$client, $admin] = $this->tenantAdmin();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok123'], 200),
            'graph.microsoft.com/v1.0/organization' => Http::response(['value' => [['displayName' => 'Acme']]], 200),
        ]);

        $this->actingAs($admin, 'web')->putJson('/api/inv-integrations/entra_id', [
            'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'original-secret',
        ])->assertOk();

        // Segunda edición: cambia solo el tenant_id, deja client_secret vacío.
        $this->actingAs($admin, 'web')->putJson('/api/inv-integrations/entra_id', [
            'tenant_id' => 'tenant-2', 'client_id' => 'client-1', 'client_secret' => '',
        ])->assertOk();

        $raw = DB::table('inv_integrations')->where('client_id', $client->id)->where('provider', 'entra_id')->value('config');
        $decoded = json_decode(Crypt::decryptString($raw), true);
        $this->assertSame('original-secret', $decoded['client_secret']);
        $this->assertSame('tenant-2', $decoded['tenant_id']);
    }

    public function test_ad_without_ldap_extension_reports_a_clear_error(): void
    {
        if (extension_loaded('ldap')) {
            $this->markTestSkipped('Este entorno sí tiene php-ldap -- el caso a probar es justo la ausencia de la extensión.');
        }

        [, $admin] = $this->tenantAdmin();

        $response = $this->actingAs($admin, 'web')->putJson('/api/inv-integrations/ad', [
            'host' => 'dc01.local', 'bind_dn' => 'CN=svc', 'bind_password' => 'x',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'error');
        $this->assertStringContainsString('ldap', strtolower($response->json('status_message')));
    }

    public function test_index_reports_not_configured_without_leaking_across_tenants(): void
    {
        [$clientA, $adminA] = $this->tenantAdmin();
        [$clientB, $adminB] = $this->tenantAdmin();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            'graph.microsoft.com/v1.0/organization' => Http::response(['value' => []], 200),
        ]);

        $this->actingAs($adminA, 'web')->putJson('/api/inv-integrations/entra_id', [
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $indexB = $this->actingAs($adminB, 'web')->getJson('/api/inv-integrations');
        $indexB->assertOk();
        $entraB = collect($indexB->json())->firstWhere('provider', 'entra_id');
        $this->assertSame('not_configured', $entraB['status']);

        $this->assertDatabaseCount('inv_integrations', 1);
        $this->assertDatabaseHas('inv_integrations', ['client_id' => $clientA->id, 'provider' => 'entra_id']);
        $this->assertDatabaseMissing('inv_integrations', ['client_id' => $clientB->id, 'provider' => 'entra_id']);
    }

    public function test_forbidden_without_the_permission(): void
    {
        // EnsurePermissionOrAdmin deja pasar al primer usuario del sistema y a
        // cualquiera mientras no exista ninguna asignación de rol -- decoy
        // ocupa el id=1 y le asignamos un rol vacío para cerrar esa ventana.
        $this->bareUser();
        $user = $this->bareUser();
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $roleName = 'sin-permiso-'.uniqid();
        $role = \App\Models\Role::query()->create([
            'team_id' => config('tenancy.super_admin_team_id'),
            'name' => $roleName,
            'guard_name' => 'web',
            'slug' => \Illuminate\Support\Str::slug($roleName),
        ]);
        $user->assignRole($role);

        $response = $this->actingAs($user, 'web')->getJson('/api/inv-integrations');

        $response->assertForbidden();
    }

    public function test_destroy_removes_the_row_and_reverts_to_not_configured(): void
    {
        [$client, $admin] = $this->tenantAdmin();

        InvIntegration::create([
            'client_id' => $client->id, 'provider' => 'entra_id',
            'config' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's'],
            'status' => 'connected', 'last_tested_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'web')->deleteJson('/api/inv-integrations/entra_id');

        $response->assertOk();
        $response->assertJsonPath('status', 'not_configured');
        $this->assertDatabaseMissing('inv_integrations', ['client_id' => $client->id, 'provider' => 'entra_id']);
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
        return $this->bareUser(['site_id' => $siteId, 'client_id' => $clientId]);
    }

    private function bareUser(array $overrides = []): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $siteId = $overrides['site_id'] ?? DB::table('sites')->insertGetId([
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
