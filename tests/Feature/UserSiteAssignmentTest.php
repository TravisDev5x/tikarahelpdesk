<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Panel de asignación de site_user fuera de onboarding (auditoría
 * 2026-08-11, deuda documentada desde Fase 7.7): GET/POST
 * /api/users/{user}/sites, gateado por sites.assign_staff (no
 * users.manage). Cierra el círculo real de la deuda: un invitado que
 * acepta después de que el admin ya terminó el onboarding ahora sí puede
 * recibir un site asignado.
 */
class UserSiteAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_lists_assigned_and_available_sites_scoped_to_the_tenant(): void
    {
        [$supervisor, $client, $target] = $this->makeTenantWithSupervisor();

        $siteA = Site::create(['client_id' => $client->id, 'name' => 'Sede A', 'type' => 'physical', 'is_active' => true]);
        $siteB = Site::create(['client_id' => $client->id, 'name' => 'Sede B', 'type' => 'physical', 'is_active' => true]);
        $inactiveSite = Site::create(['client_id' => $client->id, 'name' => 'Sede Inactiva', 'type' => 'physical', 'is_active' => false]);
        $target->sites()->sync([$siteA->id]);

        $response = $this->actingAs($supervisor, 'web')->getJson("/api/users/{$target->id}/sites");

        $response->assertOk();
        $assignedIds = collect($response->json('assigned'))->pluck('id');
        $availableIds = collect($response->json('available'))->pluck('id');

        // El tenant ya trae otras sedes propias (la "Oficina principal" por
        // defecto del Customer interno, la sede hogar del supervisor) --
        // no se afirma el conjunto exacto, solo que las 2 nuevas SÍ están
        // disponibles y la inactiva NO.
        $this->assertEqualsCanonicalizing([$siteA->id], $assignedIds->all());
        $this->assertContains($siteA->id, $availableIds);
        $this->assertContains($siteB->id, $availableIds);
        $this->assertNotContains($inactiveSite->id, $availableIds, 'Sedes inactivas no deben ofrecerse como opción.');
    }

    public function test_sync_assigns_sites_via_site_user(): void
    {
        [$supervisor, $client, $target] = $this->makeTenantWithSupervisor();

        $siteA = Site::create(['client_id' => $client->id, 'name' => 'Sede A', 'type' => 'physical', 'is_active' => true]);
        $siteB = Site::create(['client_id' => $client->id, 'name' => 'Sede B', 'type' => 'physical', 'is_active' => true]);

        $response = $this->actingAs($supervisor, 'web')->postJson("/api/users/{$target->id}/sites", [
            'site_ids' => [$siteA->id, $siteB->id],
        ]);

        $response->assertOk();

        // sites tiene RLS -- leer la relación fuera de una request real
        // (ApplyPgsqlTenantRls ya limpió su contexto al terminar el POST de
        // arriba) necesita el mismo bypass puntual ya usado en el resto del
        // proyecto para este caso exacto.
        PgsqlRowLevelSecurity::setBypass(true);
        $assignedSiteIds = $target->sites()->pluck('site_id')->all();
        PgsqlRowLevelSecurity::clear();

        $this->assertEqualsCanonicalizing([$siteA->id, $siteB->id], $assignedSiteIds);
    }

    public function test_sync_can_unassign_by_sending_an_empty_list(): void
    {
        [$supervisor, $client, $target] = $this->makeTenantWithSupervisor();
        $siteA = Site::create(['client_id' => $client->id, 'name' => 'Sede A', 'type' => 'physical', 'is_active' => true]);
        $target->sites()->sync([$siteA->id]);

        $this->actingAs($supervisor, 'web')->postJson("/api/users/{$target->id}/sites", ['site_ids' => []])
            ->assertOk();

        PgsqlRowLevelSecurity::setBypass(true);
        $count = $target->sites()->count();
        PgsqlRowLevelSecurity::clear();
        $this->assertSame(0, $count);
    }

    public function test_sync_rejects_a_site_belonging_to_another_tenant(): void
    {
        [$supervisor, $client, $target] = $this->makeTenantWithSupervisor();
        $otherClient = Client::create(['name' => 'Otro Tenant', 'is_active' => true]);
        $foreignSite = Site::create(['client_id' => $otherClient->id, 'name' => 'Sede Ajena', 'type' => 'physical', 'is_active' => true]);

        $response = $this->actingAs($supervisor, 'web')->postJson("/api/users/{$target->id}/sites", [
            'site_ids' => [$foreignSite->id],
        ]);

        $response->assertStatus(422);

        PgsqlRowLevelSecurity::setBypass(true);
        $count = $target->sites()->count();
        PgsqlRowLevelSecurity::clear();
        $this->assertSame(0, $count, 'Un site de otro tenant no debía quedar asignado.');
    }

    public function test_show_rejects_a_target_user_belonging_to_another_tenant(): void
    {
        [$supervisor] = $this->makeTenantWithSupervisor();
        $otherClient = Client::create(['name' => 'Otro Tenant B', 'is_active' => true]);
        $otherSite = Site::create(['client_id' => $otherClient->id, 'name' => 'Sede B', 'type' => 'physical', 'is_active' => true]);
        $foreignTarget = $this->bareUser('foreign-'.uniqid().'@test.local', $otherClient->id, $otherSite->id);

        $response = $this->actingAs($supervisor, 'web')->getJson("/api/users/{$foreignTarget->id}/sites");

        $response->assertStatus(403);
    }

    public function test_sync_rejects_a_target_user_belonging_to_another_tenant(): void
    {
        [$supervisor] = $this->makeTenantWithSupervisor();
        $otherClient = Client::create(['name' => 'Otro Tenant C', 'is_active' => true]);
        $otherSite = Site::create(['client_id' => $otherClient->id, 'name' => 'Sede C', 'type' => 'physical', 'is_active' => true]);
        $foreignTarget = $this->bareUser('foreign2-'.uniqid().'@test.local', $otherClient->id, $otherSite->id);

        $response = $this->actingAs($supervisor, 'web')->postJson("/api/users/{$foreignTarget->id}/sites", [
            'site_ids' => [$otherSite->id],
        ]);

        $response->assertStatus(403);

        PgsqlRowLevelSecurity::setBypass(true);
        $count = $foreignTarget->sites()->count();
        PgsqlRowLevelSecurity::clear();
        $this->assertSame(0, $count);
    }

    public function test_a_user_without_sites_assign_staff_permission_is_rejected(): void
    {
        [, $client, $target] = $this->makeTenantWithSupervisor();
        $site = Site::create(['client_id' => $client->id, 'name' => 'Sede A', 'type' => 'physical', 'is_active' => true]);

        setPermissionsTeamId($client->id);
        $agente = $this->bareUser('agente-'.uniqid().'@test.local', $client->id, $site->id);
        $agente->assignRole(Role::where('team_id', $client->id)->where('name', 'agente')->firstOrFail());

        $this->actingAs($agente, 'web')->getJson("/api/users/{$target->id}/sites")->assertStatus(403);
        $this->actingAs($agente, 'web')->postJson("/api/users/{$target->id}/sites", ['site_ids' => [$site->id]])
            ->assertStatus(403);
    }

    /**
     * Cierra el círculo real de la deuda documentada: asignar un site
     * desde ESTE panel (no desde onboarding) y confirmar que el scoping de
     * tickets (Fase 4/5, TicketPolicy::scopeFor) ya lo respeta de
     * inmediato, sin nada más que hacer.
     */
    public function test_assigning_a_site_from_this_panel_is_immediately_respected_by_ticket_scoping(): void
    {
        [$supervisor, $client, $target] = $this->makeTenantWithSupervisor();
        $site = Site::create(['client_id' => $client->id, 'name' => 'Sede Nueva', 'type' => 'physical', 'is_active' => true]);

        setPermissionsTeamId($client->id);
        $target->assignRole(Role::where('team_id', $client->id)->where('name', 'agente')->firstOrFail());

        $catalog = $this->makeCatalog();
        $requester = $this->bareUser('requester-'.uniqid().'@test.local', $client->id, $site->id);
        $ticket = \App\Models\Ticket::create([
            'subject' => 'Ticket en sede nueva',
            'folio' => 'PANEL-'.uniqid(),
            'area_origin_id' => $catalog['area_id'],
            'area_current_id' => $catalog['area_id'],
            'site_id' => $site->id,
            'client_id' => $client->id,
            'requester_id' => $requester->id,
            'ticket_type_id' => $catalog['ticket_type_id'],
            'priority_id' => $catalog['priority_id'],
            'ticket_state_id' => $catalog['ticket_state_id'],
        ]);

        // Antes de asignar el site: el target no lo ve (agente sin site_user en esa sede).
        $before = $this->actingAs($target, 'web')->getJson('/api/tickets');
        $before->assertOk();
        $this->assertFalse(collect($before->json('data'))->pluck('id')->contains($ticket->id));

        // Cambio de actor real dentro del mismo test: el guard 'sanctum'
        // (el que usan las rutas de api.php, distinto de 'web') cachea su
        // propio usuario resuelto en la instancia de guard ya creada --
        // actingAs(..., 'web') por sí solo no lo invalida. forgetGuards()
        // fuerza a que TODOS los guards (incluido 'sanctum') se
        // re-resuelvan en el siguiente request.
        $this->app['auth']->forgetGuards();

        // Asignación real desde el panel nuevo.
        $this->actingAs($supervisor, 'web')->postJson("/api/users/{$target->id}/sites", [
            'site_ids' => [$site->id],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Después: el scoping de tickets ya lo respeta, sin nada más que hacer.
        $after = $this->actingAs($target, 'web')->getJson('/api/tickets');
        $after->assertOk();
        $this->assertTrue(collect($after->json('data'))->pluck('id')->contains($ticket->id));
    }

    /** @return array{0: User, 1: Client, 2: User} supervisor, client, target(agente sin permiso) */
    private function makeTenantWithSupervisor(): array
    {
        $operator = $this->bareUser('operator-'.uniqid().'@test.local', null);
        $client = Client::create(['name' => 'Tenant Sites', 'operator_user_id' => $operator->id, 'is_active' => true]);

        setPermissionsTeamId($client->id);
        $this->seed(TenantRoleSeeder::class);

        $homeSite = DB::table('sites')->insertGetId([
            'name' => 'Sede Hogar-'.uniqid(), 'client_id' => $client->id, 'type' => 'physical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $supervisor = $this->bareUser('supervisor-'.uniqid().'@test.local', $client->id, $homeSite);
        $supervisor->assignRole(Role::where('team_id', $client->id)->where('name', 'supervisor')->firstOrFail());

        $target = $this->bareUser('target-'.uniqid().'@test.local', $client->id, null);

        return [$supervisor, $client, $target];
    }

    private function bareUser(string $email, ?int $clientId, ?int $siteId = null): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId,
            'site_id' => $siteId, 'client_id' => $clientId,
            'status' => 'active', 'onboarding_completed' => true, 'email_verified_at' => now(),
        ]);
    }

    private function makeCatalog(): array
    {
        $now = now();

        return [
            'area_id' => DB::table('areas')->insertGetId(['name' => 'Area'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'priority_id' => DB::table('priorities')->insertGetId(['name' => 'Prio'.uniqid(), 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_state_id' => DB::table('ticket_states')->insertGetId(['name' => 'Estado'.uniqid(), 'code' => 'st'.uniqid(), 'is_active' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_type_id' => DB::table('ticket_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
        ];
    }
}
