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
 * Fuga cross-tenant corregida 2026-08-12: admin_notifications no tenía
 * client_id -- cualquier admin de tenant (el bypass de
 * EnsurePermissionOrAdmin da acceso a todo usuario con rol 'admin', y todo
 * tenant tiene uno) veía y podía resolver solicitudes de reset de
 * contraseña / baja de cuenta de OTROS tenants -- toma de cuenta cross-
 * tenant completa. Ver AdminNotificationController y la migración
 * add_client_id_to_admin_notifications_table.
 */
class AdminNotificationsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_and_actions_are_scoped_per_tenant(): void
    {
        [$adminA, $clientA, $targetA, $adminB, $clientB, $targetB] = $this->makeTwoTenants();

        $notificationIdA = $this->insertPasswordResetNotification($clientA->id, $targetA->id);
        $notificationIdB = $this->insertPasswordResetNotification($clientB->id, $targetB->id);

        // 1) index(): A no ve la notificación de B.
        $response = $this->actingAs($adminA, 'web')->getJson('/api/admin/notifications');
        $response->assertOk();
        $ids = collect($response->json('notifications'))->pluck('id');
        $this->assertContains($notificationIdA, $ids, 'A debe ver su propia notificación.');
        $this->assertNotContains($notificationIdB, $ids, 'A NO debe ver la notificación de B -- fuga cross-tenant.');

        // 2) markRead(): A no puede marcar como leída la notificación de B.
        $this->app['auth']->forgetGuards();
        $readResponse = $this->actingAs($adminA, 'web')->postJson("/api/admin/notifications/{$notificationIdB}/read");
        $readResponse->assertStatus(404);
        $this->assertNull(DB::table('admin_notifications')->where('id', $notificationIdB)->value('read_at'));

        // 3) resolvePasswordReset(): A no puede resetear la contraseña de B vía la notificación de B.
        $oldHashB = $targetB->password;
        $this->app['auth']->forgetGuards();
        $resolveResponse = $this->actingAs($adminA, 'web')->postJson("/api/admin/notifications/{$notificationIdB}/resolve-password", [
            'user_id' => $targetB->id,
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'communication_method' => 'whatsapp_empresarial',
        ]);
        $resolveResponse->assertStatus(404);
        $this->assertSame($oldHashB, $targetB->fresh()->password, 'La contraseña de B no debía cambiar.');

        // 4) Defensa en profundidad: aunque A ataque su PROPIA notificación
        //    pero mande el user_id de B en el body, se rechaza igual.
        $this->app['auth']->forgetGuards();
        $crossUserResponse = $this->actingAs($adminA, 'web')->postJson("/api/admin/notifications/{$notificationIdA}/resolve-password", [
            'user_id' => $targetB->id,
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'communication_method' => 'whatsapp_empresarial',
        ]);
        $crossUserResponse->assertStatus(403);
        $this->assertSame($oldHashB, $targetB->fresh()->password, 'La contraseña de B no debía cambiar ni con la notificación de A.');

        // 5) Camino feliz: A SÍ puede resolver su propia notificación, de su propio usuario.
        $this->app['auth']->forgetGuards();
        $ownResponse = $this->actingAs($adminA, 'web')->postJson("/api/admin/notifications/{$notificationIdA}/resolve-password", [
            'user_id' => $targetA->id,
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'communication_method' => 'whatsapp_empresarial',
        ]);
        $ownResponse->assertOk();
        $this->assertTrue(Hash::check('Str0ng!Passw0rd', $targetA->fresh()->password));
    }

    private function insertPasswordResetNotification(int $clientId, int $userId): int
    {
        return DB::table('admin_notifications')->insertGetId([
            'type' => 'password_reset_request',
            'client_id' => $clientId,
            'payload' => json_encode(['user_id' => $userId, 'requested_via' => 'employee_number']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Client, 2: User, 3: User, 4: Client, 5: User} adminA, clientA, targetA, adminB, clientB, targetB */
    private function makeTwoTenants(): array
    {
        $operator = $this->bareUser('operator-'.uniqid().'@test.local', null);
        $clientA = Client::create(['name' => 'Tenant A', 'operator_user_id' => $operator->id, 'is_active' => true]);
        $clientB = Client::create(['name' => 'Tenant B', 'operator_user_id' => $operator->id, 'is_active' => true]);

        Permission::firstOrCreate(['name' => 'notifications.manage', 'guard_name' => 'web']);

        setPermissionsTeamId($clientA->id);
        $adminRoleA = Role::create([
            'name' => 'admin-ani-'.uniqid(), 'slug' => 'admin-ani-'.uniqid(), 'guard_name' => 'web',
            'team_id' => $clientA->id, 'scope_archetype' => 'admin',
        ]);
        $adminRoleA->givePermissionTo(['notifications.manage']);
        $adminA = $this->bareUser('admin-a-'.uniqid().'@test.local', $clientA->id);
        $adminA->assignRole($adminRoleA);
        $targetA = $this->bareUser('target-a-'.uniqid().'@test.local', $clientA->id);

        setPermissionsTeamId($clientB->id);
        $adminRoleB = Role::create([
            'name' => 'admin-ani-'.uniqid(), 'slug' => 'admin-ani-'.uniqid(), 'guard_name' => 'web',
            'team_id' => $clientB->id, 'scope_archetype' => 'admin',
        ]);
        $adminRoleB->givePermissionTo(['notifications.manage']);
        $adminB = $this->bareUser('admin-b-'.uniqid().'@test.local', $clientB->id);
        $adminB->assignRole($adminRoleB);
        $targetB = $this->bareUser('target-b-'.uniqid().'@test.local', $clientB->id);

        setPermissionsTeamId($clientA->id);

        return [$adminA, $clientA, $targetA, $adminB, $clientB, $targetB];
    }

    private function bareUser(string $email, ?int $clientId): User
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
            'site_id' => null, 'client_id' => $clientId,
            'status' => 'active', 'onboarding_completed' => true, 'email_verified_at' => now(),
        ]);
    }
}
