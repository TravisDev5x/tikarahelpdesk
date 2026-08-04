<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesMspTwinClientFixtures;
use Tests\TestCase;

/**
 * Aislamiento API entre dos clientes del mismo operador MSP (fase 2.1).
 */
class TenantApiIsolationTest extends TestCase
{
    use CreatesMspTwinClientFixtures;

    /**
     * Con RLS (Postgres) las filas fuera del tenant son invisibles a nivel de
     * BD antes de que la policy de autorización se ejecute (404 vía binding de
     * ruta). Sin RLS (SQLite), la policy sí encuentra la fila y la rechaza
     * explícitamente (403). Ambos casos niegan el acceso correctamente.
     */
    private function assertTenantBoundaryDenied(TestResponse $response): void
    {
        if (PgsqlRowLevelSecurity::enabled()) {
            $response->assertNotFound();
        } else {
            $response->assertForbidden();
        }
    }
    use RefreshDatabase;

    public function test_portal_tickets_index_and_show_isolate_client_data(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $world = $this->createTwinClientIsolationWorld();
        $this->resetTenantContext();

        $index = $this->actingAs($world['agentA'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/tickets'));

        $index->assertOk();
        $subjects = collect($index->json('data'))->pluck('subject');
        $this->assertTrue($subjects->contains('Ticket Alpha'));
        $this->assertFalse($subjects->contains('Ticket Beta'));

        $showOwn = $this->actingAs($world['agentA'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/tickets/'.$world['ticketA']->id));
        $showOwn->assertOk()->assertJsonPath('subject', 'Ticket Alpha');

        $showForeign = $this->actingAs($world['agentA'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/tickets/'.$world['ticketB']->id));
        $this->assertTenantBoundaryDenied($showForeign);
    }

    public function test_portal_incidents_index_and_show_isolate_client_data(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $world = $this->createTwinClientIsolationWorld();
        $this->resetTenantContext();

        $index = $this->actingAs($world['agentA'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/incidents'));

        $index->assertOk();
        $subjects = collect($index->json('data'))->pluck('subject');
        $this->assertTrue($subjects->contains('Incidencia Alpha'));
        $this->assertFalse($subjects->contains('Incidencia Beta'));

        $this->actingAs($world['agentA'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/incidents/'.$world['incidentA']->id))
            ->assertOk();

        $this->assertTenantBoundaryDenied(
            $this->actingAs($world['agentA'], 'web')
                ->getJson($this->portalApiUrl($world['clientA'], '/api/incidents/'.$world['incidentB']->id))
        );
    }

    public function test_portal_sedes_and_clientes_lists_are_scoped_to_portal_client(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $world = $this->createTwinClientIsolationWorld();
        $this->resetTenantContext();

        $sedes = $this->actingAs($world['agentA'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/sites'));
        $sedes->assertOk();
        $siteClientIds = collect($sedes->json())->pluck('client_id')->unique()->filter()->values();
        $this->assertSame([(int) $world['clientA']->id], $siteClientIds->all());

        $clientes = $this->actingAs($world['agentA'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/clients'));
        $clientes->assertOk();
        $clientIds = collect($clientes->json())->pluck('id');
        $this->assertTrue($clientIds->contains($world['clientA']->id));
        $this->assertFalse($clientIds->contains($world['clientB']->id));
    }

    public function test_agent_cannot_mutate_foreign_client_or_site(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $world = $this->createTwinClientIsolationWorld();
        $this->resetTenantContext();

        $this->actingAs($world['agentA'], 'web')
            ->putJson('/api/clients/'.$world['clientB']->id, ['name' => 'Hackeado'])
            ->assertForbidden();

        $this->assertTenantBoundaryDenied(
            $this->actingAs($world['agentA'], 'web')
                ->putJson('/api/sites/'.$world['siteB'], ['name' => 'Sede hackeada'])
        );
    }

    public function test_other_msp_operator_cannot_access_foreign_client_ticket(): void
    {
        $world = $this->createTwinClientIsolationWorld();

        $foreignClient = \App\Models\Client::create([
            'name' => 'Cliente ajeno',
            'operator_user_id' => $world['otherOperator']->id,
            'is_active' => true,
        ]);
        $foreignSite = Site::create([
            'name' => 'Sede ajena',
            'code' => 'FOR-'.random_int(1000, 9999),
            'type' => 'physical',
            'is_active' => true,
            'client_id' => $foreignClient->id,
        ]);
        $world['otherOperator']->update(['site_id' => $foreignSite->id]);
        setPermissionsTeamId($foreignClient->id);
        $world['otherOperator']->givePermissionTo('tickets.manage_all');

        $this->assertTenantBoundaryDenied(
            $this->actingAs($world['otherOperator'], 'web')
                ->getJson('/api/tickets/'.$world['ticketA']->id)
        );
    }

    public function test_msp_operator_on_root_sees_both_clients_but_portal_still_isolates(): void
    {
        if (! \Schema::hasColumn('clients', 'portal_slug')) {
            $this->markTestSkipped('Migración portal_slug no aplicada.');
        }

        $world = $this->createTwinClientIsolationWorld();
        // El operador MSP administra varios clients, sin uno propio --
        // mismo centinela de plataforma que usa ApplyPgsqlTenantRls.
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $world['operator']->givePermissionTo('catalogs.manage');

        $clientes = $this->actingAs($world['operator'], 'web')->getJson('/api/clients');
        $clientes->assertOk();
        $names = collect($clientes->json())->pluck('name');
        $this->assertTrue($names->contains('Empresa Alpha'));
        $this->assertTrue($names->contains('Empresa Beta'));

        $this->resetTenantContext();
        $portalTickets = $this->actingAs($world['operator'], 'web')
            ->getJson($this->portalApiUrl($world['clientA'], '/api/tickets'));
        $portalTickets->assertOk();
        $subjects = collect($portalTickets->json('data'))->pluck('subject');
        $this->assertTrue($subjects->contains('Ticket Alpha'));
        $this->assertFalse($subjects->contains('Ticket Beta'));
    }
}
