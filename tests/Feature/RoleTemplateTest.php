<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationObjectSeeder;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * RBAC v2 (Fase 6), Paso 5: plantillas editables por tenant + overrides
 * directos por usuario, sobre el catálogo de objetos (Paso 4) y el
 * team_id de spatie/laravel-permission (Paso 1). Solo backend, sin UI.
 */
class RoleTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationObjectSeeder::class);

        $operator = $this->makeUser('operator@test.local');
        $this->client = Client::create([
            'name' => 'Tenant Templates',
            'operator_user_id' => $operator->id,
            'is_active' => true,
            'portal_slug' => 'tenant-templates-'.uniqid(),
        ]);

        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->admin = $this->makeUser('admin@test.local');
        $this->admin->update(['client_id' => $this->client->id]);
        $this->admin->assignRole('admin');
    }

    public function test_admin_creates_a_custom_template_with_selected_permissions(): void
    {
        $response = $this->actingAs($this->admin, 'web')->postJson('/api/role-templates', [
            'name' => 'Mesa de ayuda nivel 2',
            'scope_archetype' => 'agente',
            'objects' => [
                ['key' => 'tickets.view', 'level' => 'full'],
                ['key' => 'tickets.comment', 'level' => 'full'],
                ['key' => 'tickets.escalate', 'level' => 'none'],
            ],
        ]);

        $response->assertCreated();

        $role = Role::where('team_id', $this->client->id)->where('name', 'Mesa de ayuda nivel 2')->first();
        $this->assertNotNull($role);
        $this->assertSame('agente', $role->scope_archetype);
        $this->assertTrue($role->hasPermissionTo('tickets.view_area'));
        $this->assertTrue($role->hasPermissionTo('tickets.comment'));
        $this->assertFalse($role->hasPermissionTo('tickets.view_own'));
        $this->assertFalse($role->hasPermissionTo('tickets.escalate'));
    }

    public function test_read_level_resolves_to_the_read_permission_not_the_full_one(): void
    {
        $response = $this->actingAs($this->admin, 'web')->postJson('/api/role-templates', [
            'name' => 'Solo lectura de tickets',
            'scope_archetype' => 'solicitante',
            'objects' => [
                ['key' => 'tickets.view', 'level' => 'read'],
            ],
        ]);

        $response->assertCreated();

        $role = Role::where('team_id', $this->client->id)->where('name', 'Solo lectura de tickets')->first();
        $this->assertTrue($role->hasPermissionTo('tickets.view_own'));
        $this->assertFalse($role->hasPermissionTo('tickets.view_area'));
    }

    /** Fase 7.4: "Activos" de Inventario es el único objeto con 3 niveles reales (full/edit/read). */
    public function test_edit_level_resolves_to_the_edit_permission_not_full_or_read(): void
    {
        $response = $this->actingAs($this->admin, 'web')->postJson('/api/role-templates', [
            'name' => 'Editor de inventario',
            'scope_archetype' => 'agente',
            'objects' => [
                ['key' => 'inventory.manage_assets', 'level' => 'edit'],
            ],
        ]);

        $response->assertCreated();

        $role = Role::where('team_id', $this->client->id)->where('name', 'Editor de inventario')->first();
        $this->assertTrue($role->hasPermissionTo('inventory.edit_assets'));
        $this->assertFalse($role->hasPermissionTo('inventory.manage_assets'));
        $this->assertFalse($role->hasPermissionTo('inventory.view_assets'));
    }

    public function test_admin_updates_a_template_and_permissions_are_resynced(): void
    {
        $create = $this->actingAs($this->admin, 'web')->postJson('/api/role-templates', [
            'name' => 'Editable',
            'scope_archetype' => 'agente',
            'objects' => [['key' => 'tickets.comment', 'level' => 'full']],
        ]);
        $roleId = $create->json('id');

        $update = $this->actingAs($this->admin, 'web')->putJson("/api/role-templates/{$roleId}", [
            'name' => 'Editable',
            'scope_archetype' => 'supervisor',
            'objects' => [['key' => 'tickets.escalate', 'level' => 'full']],
        ]);

        $update->assertOk();
        $role = Role::find($roleId);
        $this->assertSame('supervisor', $role->scope_archetype);
        $this->assertFalse($role->hasPermissionTo('tickets.comment'));
        $this->assertTrue($role->hasPermissionTo('tickets.escalate'));
    }

    /** Aislamiento por team_id: un tenant no puede ver ni editar la plantilla de otro. */
    public function test_admin_of_one_tenant_cannot_edit_another_tenants_template(): void
    {
        $otherOperator = $this->makeUser('operator2@test.local');
        $otherClient = Client::create([
            'name' => 'Otro Tenant',
            'operator_user_id' => $otherOperator->id,
            'is_active' => true,
            'portal_slug' => 'otro-tenant-'.uniqid(),
        ]);
        setPermissionsTeamId($otherClient->id);
        $this->seed(TenantRoleSeeder::class);
        $otherRole = Role::where('team_id', $otherClient->id)->where('name', 'agente')->first();

        $response = $this->actingAs($this->admin, 'web')->putJson("/api/role-templates/{$otherRole->id}", [
            'name' => 'Hackeado',
            'scope_archetype' => 'agente',
            'objects' => [],
        ]);

        $response->assertForbidden();
        $this->assertSame('agente', $otherRole->fresh()->name);
    }

    public function test_admin_of_one_tenant_does_not_see_another_tenants_templates_in_the_index(): void
    {
        $otherOperator = $this->makeUser('operator3@test.local');
        $otherClient = Client::create([
            'name' => 'Tenant Aparte',
            'operator_user_id' => $otherOperator->id,
            'is_active' => true,
            'portal_slug' => 'tenant-aparte-'.uniqid(),
        ]);
        setPermissionsTeamId($otherClient->id);
        $this->seed(TenantRoleSeeder::class);

        setPermissionsTeamId($this->client->id);
        $response = $this->actingAs($this->admin, 'web')->getJson('/api/roles');

        $response->assertOk();
        $names = collect($response->json())->pluck('team_id')->filter()->unique();
        $this->assertNotContains($otherClient->id, $names);
    }

    /** Override directo (Spatie nativo, givePermissionTo) se refleja en $user->can(...) sin pasar por su plantilla. */
    public function test_direct_permission_override_is_reflected_without_a_role(): void
    {
        $agent = $this->makeUser('agent-override@test.local');
        $agent->update(['client_id' => $this->client->id]);
        setPermissionsTeamId($this->client->id);

        $this->assertFalse($agent->can('incidents.manage_all'));

        $response = $this->actingAs($this->admin, 'web')->postJson("/api/users/{$agent->id}/permission-overrides", [
            'permission' => 'incidents.manage_all',
        ]);

        $response->assertCreated();
        $this->assertTrue($agent->fresh()->can('incidents.manage_all'));
        $this->assertTrue($agent->fresh()->hasDirectPermission('incidents.manage_all'));
        $this->assertFalse($agent->fresh()->getPermissionsViaRoles()->pluck('name')->contains('incidents.manage_all'));
    }

    /** Endpoint de solo lectura: distingue permisos vía plantilla de overrides directos. */
    public function test_permission_breakdown_distinguishes_role_vs_direct(): void
    {
        $agent = $this->makeUser('agent-breakdown@test.local');
        $agent->update(['client_id' => $this->client->id]);
        setPermissionsTeamId($this->client->id);
        $agent->assignRole('agente');
        $agent->givePermissionTo('incidents.manage_all');

        $response = $this->actingAs($this->admin, 'web')->getJson("/api/users/{$agent->id}/permissions");

        $response->assertOk();
        $this->assertContains('tickets.assign', $response->json('via_roles'));
        $this->assertContains('incidents.manage_all', $response->json('direct'));
        $this->assertNotContains('incidents.manage_all', $response->json('via_roles'));
        $this->assertContains('agente', $response->json('roles'));
    }

    /** Revocar el override directo no le quita el permiso si también le llega por su plantilla. */
    public function test_revoking_override_keeps_permission_if_granted_via_role(): void
    {
        $agent = $this->makeUser('agent-revoke@test.local');
        $agent->update(['client_id' => $this->client->id]);
        setPermissionsTeamId($this->client->id);
        $agent->assignRole('agente');
        $agent->givePermissionTo('tickets.assign');

        $this->actingAs($this->admin, 'web')
            ->deleteJson("/api/users/{$agent->id}/permission-overrides/tickets.assign")
            ->assertOk();

        // Sigue pudiendo -- lo tiene vía el rol 'agente', el override solo era redundante.
        $this->assertTrue($agent->fresh()->can('tickets.assign'));
        $this->assertFalse($agent->fresh()->hasDirectPermission('tickets.assign'));
    }

    private function makeUser(string $email): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'status' => 'active', 'onboarding_completed' => true,
        ]);
    }
}
