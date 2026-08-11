<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditoría 2026-08-11 (panel de asignación de sites): assertUserAccessible()
 * ya existía y funcionaba bien en UserController::destroy/massDestroy/
 * restore/toggleBlacklist, pero faltaba en update(), UserRoleController::sync()
 * y las 3 acciones de UserPermissionOverrideController -- un admin con
 * users.manage podía, en teoría, editar rol/permisos/datos de un usuario de
 * OTRO tenant si conocía o adivinaba su id. Estos tests reproducen ese hueco
 * exacto como regresión, uno por acción.
 */
class UserManagementTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_rejects_a_user_belonging_to_another_tenant(): void
    {
        [$adminA, , $targetB] = $this->makeTwoTenants();

        $response = $this->actingAs($adminA, 'web')->putJson("/api/users/{$targetB->id}", [
            'first_name' => 'Hackeado',
            'paternal_last_name' => 'Ajeno',
            'role_id' => Role::where('name', 'agente')->where('team_id', $targetB->client_id)->value('id'),
            'campaign' => 'Camp',
            'area' => 'Area',
            'position' => 'Pos',
        ]);

        $response->assertStatus(403);
        $this->assertNotSame('Hackeado', $targetB->fresh()->first_name);
    }

    public function test_sync_roles_rejects_a_user_belonging_to_another_tenant(): void
    {
        [$adminA, , $targetB, $roleB] = $this->makeTwoTenants();

        $response = $this->actingAs($adminA, 'web')->postJson("/api/users/{$targetB->id}/roles", [
            'roles' => [$roleB->id],
        ]);

        $response->assertStatus(403);
        $this->assertFalse($targetB->fresh()->hasRole($roleB));
    }

    public function test_permission_override_store_rejects_a_user_belonging_to_another_tenant(): void
    {
        [$adminA, , $targetB] = $this->makeTwoTenants();
        Permission::firstOrCreate(['name' => 'tickets.create', 'guard_name' => 'web']);

        $response = $this->actingAs($adminA, 'web')->postJson("/api/users/{$targetB->id}/permission-overrides", [
            'permission' => 'tickets.create',
        ]);

        $response->assertStatus(403);
        $this->assertFalse($targetB->fresh()->hasPermissionTo('tickets.create'));
    }

    public function test_permission_override_destroy_rejects_a_user_belonging_to_another_tenant(): void
    {
        [$adminA, , $targetB] = $this->makeTwoTenants();
        Permission::firstOrCreate(['name' => 'tickets.create', 'guard_name' => 'web']);
        $targetB->givePermissionTo('tickets.create');

        $response = $this->actingAs($adminA, 'web')->deleteJson("/api/users/{$targetB->id}/permission-overrides/tickets.create");

        $response->assertStatus(403);
        $this->assertTrue($targetB->fresh()->hasPermissionTo('tickets.create'), 'El override de un usuario ajeno no debía poder revocarse.');
    }

    public function test_permission_override_show_rejects_a_user_belonging_to_another_tenant(): void
    {
        [$adminA, , $targetB] = $this->makeTwoTenants();

        $response = $this->actingAs($adminA, 'web')->getJson("/api/users/{$targetB->id}/permissions");

        $response->assertStatus(403);
    }

    /**
     * Hallazgo real encontrado escribiendo los 5 tests de arriba (no el
     * hueco original, uno nuevo): hasMspWideAccess() (OperatorScopeService,
     * sin tocar) da true para CUALQUIER admin con tickets.manage_all --
     * que todo admin de tenant plano ya trae vía TenantRoleSeeder -- sin
     * ser operador MSP de verdad. Y resolveOperatorUserId() para ese mismo
     * admin resuelve clients.operator_user_id DE SU PROPIO CLIENT (quién
     * lo dio de alta), no un alcance cruzado propio. Sin el guard
     * is_operator agregado en assertUserAccessible(), un admin de tenant
     * normal (rol 'admin' real, no is_operator) quedaba evaluado contra
     * los sites del operador que registró SU tenant en vez de contra su
     * propio tenant -- y perdía acceso a su propio equipo. Este test cubre
     * exactamente ese caso: mismo tenant, admin real, colega con site_id
     * null (como cualquier fundador de onboarding), Client con
     * operator_user_id seteado (como en la mayoría de los fixtures reales
     * del proyecto).
     */
    public function test_admin_with_manage_all_can_still_manage_a_same_tenant_colleague_without_a_site(): void
    {
        $operator = $this->bareUser('operator-'.uniqid().'@test.local', null);
        $client = Client::create(['name' => 'Tenant Con Operador', 'operator_user_id' => $operator->id, 'is_active' => true]);

        setPermissionsTeamId($client->id);
        $this->seed(\Database\Seeders\TenantRoleSeeder::class);

        $admin = $this->bareUser('admin-real-'.uniqid().'@test.local', $client->id, null);
        $admin->assignRole(Role::where('team_id', $client->id)->where('name', 'admin')->firstOrFail());
        $this->assertTrue($admin->can('tickets.manage_all'), 'Precondición: el rol admin real trae tickets.manage_all.');

        $colega = $this->bareUser('colega-'.uniqid().'@test.local', $client->id, null);
        $campaignName = 'Camp-'.uniqid();
        $areaName = 'Area-'.uniqid();
        $positionName = 'Pos-'.uniqid();
        DB::table('campaigns')->insert(['name' => $campaignName, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('areas')->insert(['name' => $areaName, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('positions')->insert(['name' => $positionName, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($admin, 'web')->putJson("/api/users/{$colega->id}", [
            'first_name' => 'Colega',
            'paternal_last_name' => 'Actualizado',
            'role_id' => Role::where('team_id', $client->id)->where('name', 'agente')->value('id'),
            'campaign' => $campaignName,
            'area' => $areaName,
            'position' => $positionName,
        ]);

        $response->assertOk();
        $this->assertSame('Colega', $colega->fresh()->first_name);
    }

    /** @return array{0: User, 1: Client, 2: User, 3: Role} adminA, clientA, targetB, roleB */
    private function makeTwoTenants(): array
    {
        $operator = $this->bareUser('operator-'.uniqid().'@test.local', null);
        $clientA = Client::create(['name' => 'Tenant A', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'Tenant B', 'operator_user_id' => $operator->id, 'is_active' => true]);

        $siteA = DB::table('sites')->insertGetId([
            'name' => 'Sede A-'.uniqid(), 'client_id' => $clientA->id, 'type' => 'physical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede B-'.uniqid(), 'client_id' => $clientB->id, 'type' => 'physical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        setPermissionsTeamId($clientA->id);
        Permission::firstOrCreate(['name' => 'users.manage', 'guard_name' => 'web']);
        $adminRoleA = Role::create([
            'name' => 'admin-uita-'.uniqid(), 'slug' => 'admin-uita-'.uniqid(), 'guard_name' => 'web',
            'team_id' => $clientA->id, 'scope_archetype' => 'admin',
        ]);
        $adminRoleA->givePermissionTo(['users.manage']);
        $adminA = $this->bareUser('admin-a-'.uniqid().'@test.local', $clientA->id, $siteA);
        $adminA->assignRole($adminRoleA);

        setPermissionsTeamId($clientB->id);
        $roleB = Role::create([
            'name' => 'agente', 'slug' => 'agente-'.uniqid(), 'guard_name' => 'web',
            'team_id' => $clientB->id, 'scope_archetype' => 'agente',
        ]);
        $targetB = $this->bareUser('target-b-'.uniqid().'@test.local', $clientB->id, $siteB);

        // Vuelve a dejar el contexto de permisos en el team de A -- el actor
        // real de cada test es adminA, actuando desde una request real (el
        // middleware ya lo resolvería solo, esto es fixture setup puro).
        setPermissionsTeamId($clientA->id);

        return [$adminA, $clientA, $targetB, $roleB];
    }

    private function bareUser(string $email, ?int $clientId, ?int $siteId = null): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $campaignId = DB::table('campaigns')->insertGetId(['name' => 'Camp'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'campaign_id' => $campaignId,
            'site_id' => $siteId, 'client_id' => $clientId,
            'status' => 'active', 'onboarding_completed' => true, 'email_verified_at' => now(),
        ]);
    }
}
