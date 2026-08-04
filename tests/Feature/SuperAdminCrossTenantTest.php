<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplyPgsqlTenantRls;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RBAC v2 (Fase 6), Paso 6: super_admin debe reconocerse como tal en
 * CUALQUIER contexto de tenant, no solo en el que estaba activo cuando se
 * le asignó el rol -- por eso usa el team_id centinela
 * (config('tenancy.super_admin_team_id')), no un client_id real (ver
 * ApplyPgsqlTenantRls::applyPermissionsTeam() y la corrección de diseño en
 * la auditoría Paso 0.5: model_has_roles.team_id es NOT NULL, así que NULL
 * no sirve como "team universal").
 *
 * Invoca el middleware directamente (Request::create() + setUserResolver())
 * en vez de encadenar varios actingAs()/getJson() en un mismo test -- el
 * cliente HTTP de test reutiliza sesión/cookies entre llamadas dentro del
 * mismo método para rutas sanctum con estado, lo que hace que un segundo
 * actingAs() no siempre reemplace al usuario autenticado de la llamada
 * anterior (limitación conocida del entorno de test, no del middleware).
 * Llamar ApplyPgsqlTenantRls::handle() a mano prueba la lógica real sin
 * pelear con esa limitación.
 */
class SuperAdminCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_team_context_does_not_leak_from_the_previous_requests_user(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'], ['slug' => 'super_admin']);

        $operatorA = $this->makeUser('operator-a@test.local');
        $clientA = Client::create(['name' => 'Tenant A', 'operator_user_id' => $operatorA->id, 'is_active' => true]);

        $userA = $this->makeUser('user-a@test.local');
        $userA->update(['client_id' => $clientA->id]);

        $super = $this->makeUser('super@test.local');
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $super->assignRole('super_admin');

        $middleware = app(ApplyPgsqlTenantRls::class);
        $passThrough = fn ($request) => new Response();

        // 1) Un usuario normal de Tenant A -- el middleware deja team_id=clientA->id.
        $requestA = Request::create('/api/roles');
        $requestA->setUserResolver(fn () => $userA);
        $middleware->handle($requestA, $passThrough);
        $this->assertSame((string) $clientA->id, (string) getPermissionsTeamId());

        // 2) El super_admin actúa justo después -- el middleware NO debe
        //    heredar el team_id que dejó userA; debe reconocer
        //    bypassesOperatorScope() y usar el centinela de plataforma.
        $requestSuper = Request::create('/api/roles');
        $requestSuper->setUserResolver(fn () => $super->fresh());
        $middleware->handle($requestSuper, $passThrough);

        $this->assertSame(
            (string) config('tenancy.super_admin_team_id'),
            (string) getPermissionsTeamId(),
            'El team_id de super_admin no debe heredar el de la request anterior (userA).'
        );
        $this->assertTrue($super->fresh()->hasRole('super_admin'));

        // 3) Un usuario de OTRO tenant (Tenant B) después del super_admin
        //    -- confirma que el centinela tampoco se queda "pegado" para
        //    usuarios normales posteriores.
        $operatorB = $this->makeUser('operator-b@test.local');
        $clientB = Client::create(['name' => 'Tenant B', 'operator_user_id' => $operatorB->id, 'is_active' => true]);
        $userB = $this->makeUser('user-b@test.local');
        $userB->update(['client_id' => $clientB->id]);

        $requestB = Request::create('/api/roles');
        $requestB->setUserResolver(fn () => $userB);
        $middleware->handle($requestB, $passThrough);
        $this->assertSame((string) $clientB->id, (string) getPermissionsTeamId());

        // 4) El super_admin actúa una tercera vez, ahora justo después de
        //    Tenant B -- debe seguir reconociéndose, sin importar cuál
        //    tenant "estuvo activo" último.
        $requestSuper2 = Request::create('/api/roles');
        $requestSuper2->setUserResolver(fn () => $super->fresh());
        $middleware->handle($requestSuper2, $passThrough);
        $this->assertTrue($super->fresh()->hasRole('super_admin'));
        $this->assertSame((string) config('tenancy.super_admin_team_id'), (string) getPermissionsTeamId());
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
