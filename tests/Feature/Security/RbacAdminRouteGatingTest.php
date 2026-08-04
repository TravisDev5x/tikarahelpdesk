<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Previene: un usuario con rol 'agente' (sin roles.manage/users.manage/
 * catalogs.manage) accediendo a acciones administrativas por conocer la URL
 * o el ID de un recurso real (roles.manage: gestión de roles/plantillas;
 * users.manage: gestión de usuarios; catalogs.manage: catálogos/clientes) --
 * el middleware `perm:` a nivel de ruta (routes/api.php) es la primera
 * barrera; este test confirma que de verdad bloquea, no solo que existe en
 * el código de la ruta.
 */
class RbacAdminRouteGatingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        $operator = $this->makeUser('operator@test.local');
        $this->client = Client::create(['name' => 'Tenant RBAC Gating', 'operator_user_id' => $operator->id, 'is_active' => true]);

        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->agente = $this->makeUser('agente@test.local', $this->client->id);
        $this->agente->assignRole('agente');
    }

    public function test_agente_cannot_create_a_client_reserved_for_catalogs_manage(): void
    {
        $response = $this->actingAs($this->agente, 'web')
            ->postJson('/api/clients', ['name' => 'Cliente colado']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('clients', ['name' => 'Cliente colado']);
    }

    public function test_agente_cannot_delete_an_existing_role_by_id_reserved_for_roles_manage(): void
    {
        $agenteRole = Role::where('name', 'agente')->where('team_id', $this->client->id)->firstOrFail();

        $response = $this->actingAs($this->agente, 'web')
            ->deleteJson("/api/roles/{$agenteRole->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $agenteRole->id]);
    }

    public function test_agente_cannot_create_a_role_template_reserved_for_roles_manage(): void
    {
        $response = $this->actingAs($this->agente, 'web')
            ->postJson('/api/role-templates', [
                'name' => 'Plantilla colada',
                'scope_archetype' => 'admin',
                'permissions' => ['tickets.manage_all'],
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'Plantilla colada', 'team_id' => $this->client->id]);
    }

    public function test_agente_cannot_create_a_user_reserved_for_users_manage(): void
    {
        $response = $this->actingAs($this->agente, 'web')
            ->postJson('/api/users', [
                'first_name' => 'Colado',
                'paternal_last_name' => 'Test',
                'email' => 'colado@test.local',
                'password' => 'password123',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'colado@test.local']);
    }

    public function test_agente_cannot_delete_an_existing_user_by_id_reserved_for_users_manage(): void
    {
        $victim = $this->makeUser('victima@test.local', $this->client->id);

        $response = $this->actingAs($this->agente, 'web')
            ->deleteJson("/api/users/{$victim->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'deleted_at' => null]);
    }

    private function makeUser(string $email, ?int $clientId = null): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'client_id' => $clientId,
            'status' => 'active', 'onboarding_completed' => true,
        ]);
    }
}
