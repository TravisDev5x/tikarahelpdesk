<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Incident;
use App\Models\User;
use App\Policies\IncidentPolicy;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * IncidentPolicy no tenía cobertura dedicada (a diferencia de TicketPolicy,
 * cubierta a fondo por TicketSiteScopingTest) -- solo un test de scoping
 * (TenantClientResolverTest::test_incident_policy_scope_excludes_other_tenant)
 * y el IDOR de portal en TenantApiIsolationTest. Este archivo prueba la
 * matriz de scopeType() (all / area+own / area / own / null) que
 * IncidentController::index()/show()/update()/changeStatus() usa para
 * autorizar, con permisos otorgados directamente (no vía rol fijo) para
 * cubrir TODAS las combinaciones que la policy soporta, no solo las que el
 * seeder actual produce.
 */
class IncidentPolicyMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private int $areaMine;

    private int $areaOther;

    private int $site;

    private array $catalog;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->makeUser('admin@test.local', null, []);
        $this->client = Client::create(['name' => 'Tenant Incidents', 'operator_user_id' => $admin->id, 'is_active' => true]);
        $admin->update(['client_id' => $this->client->id]);

        setPermissionsTeamId($this->client->id);

        // Reporter/assignee "de otro usuario" en los tests de abajo -- las FK
        // reales de Postgres (reporter_id/assigned_user_id -> users) no
        // toleran IDs inventados como 99999.
        $this->otherUser = $this->makeUser('other-reporter@test.local', null, []);

        $this->areaMine = $this->makeArea('Area mía');
        $this->areaOther = $this->makeArea('Area ajena');
        $this->site = DB::table('sites')->insertGetId([
            'name' => 'Site incidents', 'code' => 'INC-'.uniqid(), 'type' => 'physical',
            'is_active' => true, 'client_id' => $this->client->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->catalog = $this->makeIncidentCatalog();

        foreach ([
            'incidents.view_area', 'incidents.view_own', 'incidents.manage_all',
            'incidents.create', 'incidents.change_status', 'incidents.comment', 'incidents.assign',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    /**
     * Previene: escalamiento de privilegios donde un usuario con
     * incidents.manage_all ve TODAS las incidencias sin importar área/site
     * -- correcto para admin/is_operator, pero ver test_seeded_supervisor_role
     * más abajo para el caso donde este mismo permiso se otorga a un rol que
     * NO debería tener alcance total.
     */
    public function test_all_scope_sees_incident_of_any_area(): void
    {
        $user = $this->makeUser('all@test.local', $this->areaMine, ['incidents.manage_all']);
        $incident = $this->makeIncident($this->areaOther, $this->otherUser->id);

        $this->assertTrue($this->authorize('view', $user, $incident));
    }

    public function test_operator_flag_sees_incident_of_any_area_even_without_permission(): void
    {
        $user = $this->makeUser('operator@test.local', $this->areaMine, []);
        $user->update(['is_operator' => true]);
        // ClientScopeService::incidentVisibleToUser() enruta a un usuario
        // is_operator por la vía "MSP operator" (OperatorScopeService), que
        // exige ser DUEÑO del client (clients.operator_user_id), no solo
        // pertenecer a él -- por eso este client se re-asigna a este mismo
        // usuario, distinto del resto de la clase (donde el dueño es $admin).
        $this->client->update(['operator_user_id' => $user->id]);
        $incident = $this->makeIncident($this->areaOther, $this->otherUser->id);

        $this->assertTrue($this->authorize('view', $user->fresh(), $incident));
    }

    /**
     * Previene: un usuario con view_area+view_own viendo incidencias de
     * OTRA área en las que ni siquiera es reporter (el '+own' solo debe
     * ampliar a "lo mío", no a "cualquier área").
     */
    public function test_area_plus_own_scope_does_not_leak_other_areas_incidents_reported_by_others(): void
    {
        $user = $this->makeUser('areaown@test.local', $this->areaMine, ['incidents.view_area', 'incidents.view_own']);
        $foreignIncident = $this->makeIncident($this->areaOther, $this->otherUser->id);

        $this->assertFalse($this->authorize('view', $user, $foreignIncident));
    }

    public function test_area_plus_own_scope_sees_own_incident_reported_outside_their_area(): void
    {
        $user = $this->makeUser('areaown2@test.local', $this->areaMine, ['incidents.view_area', 'incidents.view_own']);
        $ownIncidentElsewhere = $this->makeIncident($this->areaOther, $user->id);

        $this->assertTrue($this->authorize('view', $user, $ownIncidentElsewhere));
    }

    /**
     * Previene: IDOR donde alguien con SOLO view_area (sin view_own) accede
     * a un incidente que reportó él mismo pero que vive en un área que no es
     * la suya -- la policy no debe otorgar acceso "porque es mío" si el
     * usuario nunca recibió el permiso view_own.
     */
    public function test_area_only_scope_denies_own_reported_incident_outside_their_area(): void
    {
        $user = $this->makeUser('areaonly@test.local', $this->areaMine, ['incidents.view_area']);
        $ownIncidentElsewhere = $this->makeIncident($this->areaOther, $user->id);

        $this->assertFalse($this->authorize('view', $user, $ownIncidentElsewhere));
    }

    /**
     * Previene: IDOR donde alguien con SOLO view_own ve incidencias de
     * colegas en su misma área -- view_own debe limitarse estrictamente a
     * reporter_id === user->id, sin importar que compartan área.
     */
    public function test_own_only_scope_denies_colleague_incident_in_same_area(): void
    {
        $user = $this->makeUser('ownonly@test.local', $this->areaMine, ['incidents.view_own']);
        $colleagueIncident = $this->makeIncident($this->areaMine, $this->otherUser->id);

        $this->assertFalse($this->authorize('view', $user, $colleagueIncident));
    }

    /**
     * Previene: un usuario sin ningún permiso de incidents.* accediendo al
     * listado o a un incidente por ID directo -- viewAny()/view() deben
     * negar ambos, no solo ocultar el índice.
     */
    public function test_no_incident_permission_denies_viewany_and_view(): void
    {
        $user = $this->makeUser('none@test.local', $this->areaMine, []);
        $incident = $this->makeIncident($this->areaMine, $user->id);

        $policy = app(IncidentPolicy::class);
        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($this->authorize('view', $user, $incident));
    }

    /**
     * Previene: un usuario que solo puede VER incidencias de su área
     * (view_area) pero sin ningún permiso de gestión (change_status/comment/
     * assign) pueda mutarlas igual solo por estar en el área correcta.
     */
    public function test_view_area_alone_does_not_grant_update(): void
    {
        $user = $this->makeUser('viewer@test.local', $this->areaMine, ['incidents.view_area']);
        $incident = $this->makeIncident($this->areaMine, $this->otherUser->id);

        $policy = app(IncidentPolicy::class);
        $this->assertFalse($policy->update($user, $incident->fresh()));
    }

    /**
     * Previene: un usuario con incidents.change_status pero de OTRA área (y
     * que no es el assignee) cambiando el estatus de un incidente ajeno --
     * el permiso por sí solo no basta, canManageAction() exige además
     * isCurrentArea() o isAssignee().
     */
    public function test_change_status_permission_denied_outside_area_and_not_assignee(): void
    {
        $user = $this->makeUser('changer@test.local', $this->areaMine, ['incidents.change_status']);
        $foreignIncident = $this->makeIncident($this->areaOther, $this->otherUser->id);

        $policy = app(IncidentPolicy::class);
        $this->assertFalse($policy->changeStatus($user, $foreignIncident->fresh()));
    }

    public function test_change_status_permission_allowed_when_assignee_even_outside_current_area(): void
    {
        $user = $this->makeUser('assignee@test.local', $this->areaMine, ['incidents.change_status']);
        $incident = $this->makeIncident($this->areaOther, $this->otherUser->id, assignedUserId: $user->id);

        $policy = app(IncidentPolicy::class);
        $this->assertTrue($policy->changeStatus($user, $incident->fresh()));
    }

    /**
     * Hallazgo (reportado, no corregido aquí): TenantRoleSeeder evitó
     * deliberadamente dar tickets.manage_all a 'supervisor' para no
     * colapsar su alcance al de admin (ver comentario en el seeder) -- pero
     * SÍ le da incidents.manage_all, que en IncidentPolicy::scopeType()
     * produce el mismo colapso a alcance 'all' que evitaron para tickets.
     * Este test documenta el comportamiento ACTUAL (supervisor ve
     * incidencias de áreas a las que no pertenece) como regresión: si el
     * equipo decide restringir incidents igual que tickets, este test debe
     * cambiar a assertFalse.
     */
    public function test_seeded_supervisor_role_sees_incidents_outside_their_area_via_manage_all(): void
    {
        $this->seed(TenantRoleSeeder::class);

        $supervisor = $this->makeUser('supervisor-seed@test.local', $this->areaMine, []);
        $supervisor->assignRole('supervisor');

        $foreignIncident = $this->makeIncident($this->areaOther, $this->otherUser->id);

        $this->assertTrue(
            $this->authorize('view', $supervisor->fresh(), $foreignIncident),
            'Comportamiento actual: incidents.manage_all en supervisor da alcance total, '
            .'inconsistente con la restricción por site que sí se aplica a tickets.reassign.'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function authorize(string $ability, User $user, Incident $incident): bool
    {
        \App\Support\Tenancy\PgsqlRowLevelSecurity::setBypass(true);

        return app(IncidentPolicy::class)->{$ability}($user, $incident->fresh());
    }

    private function makeUser(string $email, ?int $areaId, array $perms): User
    {
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $user = User::create([
            'first_name' => 'Test', 'paternal_last_name' => 'User',
            'email' => $email, 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => null,
            'client_id' => $this->client->id ?? null,
            'status' => 'active', 'onboarding_completed' => true,
        ]);

        if ($perms) {
            setPermissionsTeamId($this->client->id ?? null);
            $user->givePermissionTo($perms);
        }

        return $user;
    }

    private function makeArea(string $name): int
    {
        return DB::table('areas')->insertGetId([
            'name' => $name.'-'.uniqid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeIncidentCatalog(): array
    {
        $now = now();

        return [
            'type_id' => DB::table('incident_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'severity_id' => DB::table('incident_severities')->insertGetId(['name' => 'Sev'.uniqid(), 'code' => 'sv'.uniqid(), 'level' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'status_id' => DB::table('incident_statuses')->insertGetId(['name' => 'Estado'.uniqid(), 'code' => 'st'.uniqid(), 'is_active' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now]),
        ];
    }

    private function makeIncident(int $areaId, int $reporterId, ?int $assignedUserId = null): Incident
    {
        return Incident::create([
            'subject' => 'Incidente test',
            'enabled_at' => now(),
            'reporter_id' => $reporterId,
            'assigned_user_id' => $assignedUserId,
            'area_id' => $areaId,
            'client_id' => $this->client->id,
            'site_id' => $this->site,
            'incident_type_id' => $this->catalog['type_id'],
            'incident_severity_id' => $this->catalog['severity_id'],
            'incident_status_id' => $this->catalog['status_id'],
        ]);
    }
}
