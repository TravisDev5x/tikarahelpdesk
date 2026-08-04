<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\TicketSequence;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

trait CreatesMspTwinClientFixtures
{
    protected function configureTwinClientTenancy(): void
    {
        config([
            'tenancy.base_domain' => 'tikara.test',
            'tenancy.strict_client_portal' => true,
            'tenancy.legacy_msp_wide_access' => false,
        ]);
    }

    protected function resetTenantContext(): void
    {
        $this->app->forgetInstance(TenantContextService::class);
    }

    protected function ensureIsolationPermissions(): void
    {
        foreach ([
            'tickets.manage_all',
            'incidents.manage_all',
            'catalogs.manage',
            'clients.view_all',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    /**
     * Dos empresas cliente del mismo operador MSP, con tickets/incidencias/sedes en cada una.
     *
     * @return array{
     *     operator: User,
     *     otherOperator: User,
     *     clientA: Cliente,
     *     clientB: Cliente,
     *     siteA: int,
     *     siteB: int,
     *     agentA: User,
     *     agentB: User,
     *     ticketA: Ticket,
     *     ticketB: Ticket,
     *     incidentA: Incident,
     *     incidentB: Incident,
     *     areaId: int
     * }
     */
    protected function createTwinClientIsolationWorld(): array
    {
        $this->configureTwinClientTenancy();
        $this->ensureIsolationPermissions();

        $now = now();
        $areaId = DB::table('areas')->insertGetId([
            'name' => 'Área aislamiento '.uniqid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $positionId = DB::table('positions')->insertGetId([
            'name' => 'Puesto '.uniqid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bootstrapSiteId = DB::table('sites')->insertGetId([
            'name' => 'Sede bootstrap '.uniqid(),
            'code' => 'BOOT-'.random_int(1000, 9999),
            'type' => 'physical',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $operator = User::create([
            'first_name' => 'Operador',
            'paternal_last_name' => 'MSP',
            'email' => 'op-isolation-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'employee_number' => 'OP'.random_int(100000, 999999),
            'area_id' => $areaId,
            'position_id' => $positionId,
            'site_id' => $bootstrapSiteId,
            'status' => 'active',
            'is_operator' => true,
            'onboarding_completed' => true,
            'email_verified_at' => $now,
        ]);

        $otherOperator = User::create([
            'first_name' => 'Otro',
            'paternal_last_name' => 'MSP',
            'email' => 'other-op-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'employee_number' => 'OO'.random_int(100000, 999999),
            'area_id' => $areaId,
            'position_id' => $positionId,
            'site_id' => $bootstrapSiteId,
            'status' => 'active',
            'is_operator' => true,
            'onboarding_completed' => true,
            'email_verified_at' => $now,
        ]);

        $clientA = Client::create([
            'name' => 'Empresa Alpha',
            'portal_slug' => 'alpha-'.uniqid(),
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);
        $clientB = Client::create([
            'name' => 'Empresa Beta',
            'portal_slug' => 'beta-'.uniqid(),
            'operator_user_id' => $operator->id,
            'is_active' => true,
        ]);

        $siteA = DB::table('sites')->insertGetId([
            'name' => 'Sede Alpha',
            'code' => 'SA-'.random_int(1000, 9999),
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $clientA->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteB = DB::table('sites')->insertGetId([
            'name' => 'Sede Beta',
            'code' => 'SB-'.random_int(1000, 9999),
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $clientB->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $agentA = $this->createIsolationAgent($areaId, $positionId, $siteA, $clientA->id, 'agent-a');
        $agentB = $this->createIsolationAgent($areaId, $positionId, $siteB, $clientB->id, 'agent-b');

        $catalog = $this->createTicketAndIncidentCatalog($now);

        $ticketA = Ticket::create([
            'subject' => 'Ticket Alpha',
            'folio' => str_pad((string) TicketSequence::nextNumberFor($clientA->id), 5, "0", STR_PAD_LEFT),
            'area_origin_id' => $areaId,
            'area_current_id' => $areaId,
            'site_id' => $siteA,
            'client_id' => $clientA->id,
            'requester_id' => $agentA->id,
            'ticket_type_id' => $catalog['ticket_type_id'],
            'priority_id' => $catalog['priority_id'],
            'ticket_state_id' => $catalog['ticket_state_id'],
        ]);
        $ticketB = Ticket::create([
            'subject' => 'Ticket Beta',
            'folio' => str_pad((string) TicketSequence::nextNumberFor($clientB->id), 5, "0", STR_PAD_LEFT),
            'area_origin_id' => $areaId,
            'area_current_id' => $areaId,
            'site_id' => $siteB,
            'client_id' => $clientB->id,
            'requester_id' => $agentB->id,
            'ticket_type_id' => $catalog['ticket_type_id'],
            'priority_id' => $catalog['priority_id'],
            'ticket_state_id' => $catalog['ticket_state_id'],
        ]);

        $incidentA = Incident::create([
            'subject' => 'Incidencia Alpha',
            'description' => 'Test',
            'occurred_at' => $now,
            'reporter_id' => $agentA->id,
            'area_id' => $areaId,
            'site_id' => $siteA,
            'client_id' => $clientA->id,
            'incident_type_id' => $catalog['incident_type_id'],
            'incident_severity_id' => $catalog['incident_severity_id'],
            'incident_status_id' => $catalog['incident_status_id'],
        ]);
        $incidentB = Incident::create([
            'subject' => 'Incidencia Beta',
            'description' => 'Test',
            'occurred_at' => $now,
            'reporter_id' => $agentB->id,
            'area_id' => $areaId,
            'site_id' => $siteB,
            'client_id' => $clientB->id,
            'incident_type_id' => $catalog['incident_type_id'],
            'incident_severity_id' => $catalog['incident_severity_id'],
            'incident_status_id' => $catalog['incident_status_id'],
        ]);

        return [
            'operator' => $operator,
            'otherOperator' => $otherOperator,
            'clientA' => $clientA,
            'clientB' => $clientB,
            'siteA' => $siteA,
            'siteB' => $siteB,
            'agentA' => $agentA,
            'agentB' => $agentB,
            'ticketA' => $ticketA,
            'ticketB' => $ticketB,
            'incidentA' => $incidentA,
            'incidentB' => $incidentB,
            'areaId' => $areaId,
        ];
    }

    protected function portalApiUrl(Client $client, string $path): string
    {
        return 'http://'.$client->portal_slug.'.tikara.test'.'/'.ltrim($path, '/');
    }

    private function createIsolationAgent(int $areaId, int $positionId, int $siteId, int $clientId, string $prefix): User
    {
        $user = User::create([
            'first_name' => ucfirst($prefix),
            'paternal_last_name' => 'Agente',
            'email' => $prefix.'-'.uniqid().'@isolation.test',
            'password' => Hash::make('password'),
            'employee_number' => strtoupper(substr($prefix, 0, 3)).random_int(10000, 99999),
            'area_id' => $areaId,
            'position_id' => $positionId,
            'site_id' => $siteId,
            'client_id' => $clientId,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        // RBAC v2 (Fase 6): givePermissionTo() necesita team_id resuelto.
        setPermissionsTeamId($clientId);
        $user->givePermissionTo(['tickets.manage_all', 'incidents.manage_all', 'catalogs.manage']);

        return $user;
    }

    /**
     * @return array{
     *     priority_id: int,
     *     ticket_state_id: int,
     *     ticket_type_id: int,
     *     incident_type_id: int,
     *     incident_severity_id: int,
     *     incident_status_id: int
     * }
     */
    private function createTicketAndIncidentCatalog($now): array
    {
        return [
            'priority_id' => DB::table('priorities')->insertGetId([
                'name' => 'Media iso '.uniqid(),
                'level' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'ticket_state_id' => DB::table('ticket_states')->insertGetId([
                'name' => 'Abierto iso '.uniqid(),
                'code' => 'abi_iso_'.uniqid(),
                'is_active' => true,
                'is_final' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'ticket_type_id' => DB::table('ticket_types')->insertGetId([
                'name' => 'Tipo iso '.uniqid(),
                'code' => 'tipo_iso_'.uniqid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'incident_type_id' => DB::table('incident_types')->insertGetId([
                'name' => 'Tipo inc '.uniqid(),
                'code' => 'inc_t_'.uniqid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'incident_severity_id' => DB::table('incident_severities')->insertGetId([
                'name' => 'Alta inc '.uniqid(),
                'code' => 'P2-'.uniqid(),
                'level' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'incident_status_id' => DB::table('incident_statuses')->insertGetId([
                'name' => 'Abierto inc '.uniqid(),
                'code' => 'abi_inc_'.uniqid(),
                'is_active' => true,
                'is_final' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
        ];
    }
}
