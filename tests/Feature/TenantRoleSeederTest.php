<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FullDemoSeeder;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 3 del sprint maestro: 4 roles nuevos (admin, supervisor, agente,
 * solicitante) coexistiendo con los roles legacy que sigue creando
 * FullDemoSeeder. Ver App\Console\Commands\MigrateLegacyRoles para la
 * migración (aditiva) de usuarios existentes.
 *
 * RBAC v2 (Fase 6): TenantRoleSeeder ya no crea roles globales -- exige
 * team_id resuelto (spatie/laravel-permission teams = clients.id). Todos
 * los tests de este archivo crean un Client real y fijan
 * setPermissionsTeamId($client->id) antes de sembrar, igual que el punto
 * de entrada real (App\Console\Commands\SeedDefaultTenantRoles).
 */
class TenantRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $operator = $this->makeUser('operator@test.local');
        $this->client = Client::create([
            'name' => 'Tenant Roles',
            'operator_user_id' => $operator->id,
            'is_active' => true,
            'portal_slug' => 'tenant-roles-'.uniqid(),
        ]);

        setPermissionsTeamId($this->client->id);
    }

    public function test_seeds_the_four_new_roles_with_expected_permissions(): void
    {
        $this->seed(TenantRoleSeeder::class);

        $solicitante = Role::where('team_id', $this->client->id)->where('name', 'solicitante')->where('guard_name', 'web')->first();
        $agente = Role::where('team_id', $this->client->id)->where('name', 'agente')->where('guard_name', 'web')->first();
        $supervisor = Role::where('team_id', $this->client->id)->where('name', 'supervisor')->where('guard_name', 'web')->first();
        $admin = Role::where('team_id', $this->client->id)->where('name', 'admin')->where('guard_name', 'web')->first();

        $this->assertNotNull($solicitante);
        $this->assertNotNull($agente);
        $this->assertNotNull($supervisor);
        $this->assertNotNull($admin);

        // scope_archetype (RBAC v2): fijo por plantilla, independiente del
        // nombre visible -- ver TicketPolicy::siteScopeType().
        $this->assertSame('solicitante', $solicitante->scope_archetype);
        $this->assertSame('agente', $agente->scope_archetype);
        $this->assertSame('supervisor', $supervisor->scope_archetype);
        $this->assertSame('admin', $admin->scope_archetype);

        $this->assertEqualsCanonicalizing(
            ['tickets.create', 'tickets.view_own'],
            $solicitante->permissions->pluck('name')->all()
        );

        $this->assertTrue($agente->hasPermissionTo('tickets.view_area'));
        $this->assertTrue($agente->hasPermissionTo('tickets.assign'));
        $this->assertFalse($agente->hasPermissionTo('tickets.manage_all'));
        $this->assertFalse($agente->hasPermissionTo('tickets.reassign'));

        // NO tickets.manage_all: bajo el scoping por site de Fase 4, esa
        // permission es "ve todo sin restricción de site" -- exclusiva de
        // admin. Supervisor ve sus sites vía el rol, no vía este permiso.
        $this->assertFalse($supervisor->hasPermissionTo('tickets.manage_all'));
        $this->assertTrue($supervisor->hasPermissionTo('tickets.reassign'));

        $this->assertTrue($admin->hasPermissionTo('tickets.reassign'));
    }

    /**
     * Fase 7 (2026-08-09): 5ta plantilla, "Encargado TI" -- mismo scope_archetype
     * que agente (para poder trabajar el ticket que resulte de aprobar una
     * solicitud), más tickets.review_pending. Nombre libre (con espacio,
     * mayúsculas) a diferencia de los 4 originales -- el slug se deriva con
     * Str::slug(), no se reusa el name crudo.
     */
    public function test_seeds_encargado_ti_role_with_review_pending_permission(): void
    {
        $this->seed(TenantRoleSeeder::class);

        $role = Role::where('team_id', $this->client->id)->where('name', 'Encargado TI')->where('guard_name', 'web')->first();

        $this->assertNotNull($role);
        $this->assertSame('encargado-ti', $role->slug);
        $this->assertSame('agente', $role->scope_archetype);
        $this->assertTrue($role->hasPermissionTo('tickets.review_pending'));
        $this->assertTrue($role->hasPermissionTo('tickets.view_area'));
        $this->assertTrue($role->hasPermissionTo('tickets.assign'));
        $this->assertFalse($role->hasPermissionTo('tickets.manage_all'));
    }

    public function test_tickets_reassign_permission_exists_for_web_and_sanctum_guards(): void
    {
        $this->seed(TenantRoleSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'tickets.reassign', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'tickets.reassign', 'guard_name' => 'sanctum']);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(TenantRoleSeeder::class);
        $this->seed(TenantRoleSeeder::class);

        $this->assertSame(1, Role::where('team_id', $this->client->id)->where('name', 'agente')->where('guard_name', 'web')->count());
        $this->assertSame(1, Role::where('team_id', $this->client->id)->where('name', 'Encargado TI')->where('guard_name', 'web')->count());
        $this->assertSame(1, DB::table('permissions')->where('name', 'tickets.reassign')->where('guard_name', 'web')->count());
    }

    /**
     * Dos tenants distintos pueden tener cada uno su propia plantilla
     * "agente", sin colisión -- unique ahora es (team_id, name, guard_name),
     * no (name, guard_name) global. Este es el test que prueba que el
     * bloqueante de RBAC v2 (unicidad global de nombre de rol) quedó
     * resuelto de verdad.
     */
    public function test_two_tenants_can_have_a_role_with_the_same_name_without_collision(): void
    {
        $this->seed(TenantRoleSeeder::class);

        $otherOperator = $this->makeUser('operator2@test.local');
        $otherClient = Client::create([
            'name' => 'Otro Tenant',
            'operator_user_id' => $otherOperator->id,
            'is_active' => true,
            'portal_slug' => 'otro-tenant-'.uniqid(),
        ]);
        setPermissionsTeamId($otherClient->id);
        $this->seed(TenantRoleSeeder::class);

        $this->assertSame(2, Role::where('name', 'agente')->where('guard_name', 'web')->count());
        $this->assertSame(
            1,
            Role::where('team_id', $this->client->id)->where('name', 'agente')->where('guard_name', 'web')->count()
        );
        $this->assertSame(
            1,
            Role::where('team_id', $otherClient->id)->where('name', 'agente')->where('guard_name', 'web')->count()
        );
    }

    /**
     * RBAC v2 (Fase 6) cambia lo que "coexistir" significa aquí: los roles
     * de FullDemoSeeder (Role::firstOrCreate() con el patrón legacy, sin
     * pasar por Role::create() de Spatie) NUNCA reciben team_id -- quedan
     * NULL, "globales" en el sentido de Spatie (visibles desde cualquier
     * team_id vía el whereNull(team) OR team=actual de HasRoles::roles()).
     * TenantRoleSeeder sí crea explícitamente dentro de team_id=$this->client->id.
     * Antes de Fase 6, 'admin'/'supervisor' se normalizaban in-place (un
     * único nombre global compartido); ahora son DOS filas separadas (una
     * NULL-team, una del tenant) -- ya no colisionan, así que ya no hay
     * nada que normalizar in-place. Documentado aquí para que quede
     * explícito, no descubierto por sorpresa leyendo el conteo.
     */
    public function test_new_roles_coexist_with_legacy_roles_without_touching_them(): void
    {
        $this->seed(FullDemoSeeder::class);
        $legacyAgentePermsBefore = Role::whereNull('team_id')->where('name', 'soporte')->where('guard_name', 'web')->first()->permissions->pluck('name')->all();

        $this->seed(TenantRoleSeeder::class);

        // El legacy 'soporte' sigue existiendo, intacto -- Fase 3 no lo toca.
        $legacySoporte = Role::whereNull('team_id')->where('name', 'soporte')->where('guard_name', 'web')->first();
        $this->assertNotNull($legacySoporte);
        $this->assertEqualsCanonicalizing($legacyAgentePermsBefore, $legacySoporte->permissions->pluck('name')->all());

        // 'admin' legacy (NULL-team, de FullDemoSeeder) y 'admin' del tenant
        // (team_id=$this->client->id, de TenantRoleSeeder) ya NO son la misma
        // fila -- una cada una, en su propio team_id.
        $this->assertSame(1, Role::whereNull('team_id')->where('name', 'admin')->where('guard_name', 'web')->count());
        $this->assertSame(1, Role::where('team_id', $this->client->id)->where('name', 'admin')->where('guard_name', 'web')->count());
        $this->assertSame(1, Role::whereNull('team_id')->where('name', 'supervisor')->where('guard_name', 'web')->count());
        $this->assertSame(1, Role::where('team_id', $this->client->id)->where('name', 'supervisor')->where('guard_name', 'web')->count());
    }

    // ── MigrateLegacyRoles ───────────────────────────────────────────────

    public function test_migrate_legacy_roles_is_additive_and_idempotent(): void
    {
        $this->seed(FullDemoSeeder::class);
        $this->seed(TenantRoleSeeder::class);

        $user = $this->makeUser('agente-legacy@test.local');
        $user->assignRole('soporte');

        $this->artisan('roles:migrate-legacy', ['portal_slug' => $this->client->portal_slug, '--dry-run' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->hasRole('agente'));

        $this->artisan('roles:migrate-legacy', ['portal_slug' => $this->client->portal_slug])->assertSuccessful();
        $user->refresh();
        $this->assertTrue($user->hasRole('agente'));
        // Aditivo: el legacy NO se quita.
        $this->assertTrue($user->hasRole('soporte'));

        // Idempotente: correr de nuevo no falla ni duplica la asignación.
        $this->artisan('roles:migrate-legacy', ['portal_slug' => $this->client->portal_slug])->assertSuccessful();
        $this->assertSame(1, $user->fresh()->roles->where('name', 'agente')->count());
    }

    public function test_migrate_legacy_roles_maps_each_legacy_role_correctly(): void
    {
        $this->seed(FullDemoSeeder::class);
        $this->seed(TenantRoleSeeder::class);

        $cases = [
            'gerente' => 'supervisor',
            'soporte_n1' => 'agente',
            'soporte_n2' => 'agente',
            'soporte_n3' => 'agente',
            'usuario' => 'solicitante',
            'consultor' => 'agente',
        ];

        $users = [];
        foreach ($cases as $legacy => $expectedNew) {
            $user = $this->makeUser($legacy.'-'.uniqid().'@test.local');
            $user->assignRole($legacy);
            $users[$legacy] = $user;
        }

        $this->artisan('roles:migrate-legacy', ['portal_slug' => $this->client->portal_slug])->assertSuccessful();

        foreach ($cases as $legacy => $expectedNew) {
            $this->assertTrue($users[$legacy]->fresh()->hasRole($expectedNew), "esperaba que {$legacy} -> {$expectedNew}");
        }
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
