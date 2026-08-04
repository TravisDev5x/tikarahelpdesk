<?php

namespace Tests\Feature\Security;

use App\Jobs\ProcessInboundTicket;
use App\Models\Client;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * HALLAZGO (ver resumen entregado aparte): ProcessInboundTicket y
 * ProcessInboundReply fijan team_id (setPermissionsTeamId) y el bypass de
 * RLS (PgsqlRowLevelSecurity::setBypass(true)) al iniciar, pero NUNCA
 * llaman a los clear() correspondientes -- violan el contrato documentado
 * en App\Services\Tenant\TenantContextService (su propio docblock dice
 * "TenantContextService::set($cliente); ... TenantContextService::clear();").
 *
 * En producción, `php artisan queue:work --tries=1 --sleep=3`
 * (docker-compose.prod.yml:37) es un proceso PERSISTENTE que procesa
 * muchos jobs sin reiniciar -- a diferencia de una request HTTP (PHP-FPM
 * reinicia el estado por request), el team_id de Spatie y las variables de
 * sesión Postgres para RLS quedan pegadas al último tenant procesado para
 * CUALQUIER trabajo siguiente en ese mismo worker.
 *
 * Este test no explota un endpoint concreto (hoy ningún otro job lee este
 * estado) -- prueba la fuga en sí, que es el defecto real: cualquier job o
 * listener que se agregue a este worker en el futuro y llame $user->can()/
 * hasRole() sin fijar su propio team_id heredará silenciosamente el de un
 * tenant ajeno, y con RLS heredará bypass=true (ninguna fila filtrada).
 */
class TenantJobContextLeakTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    /**
     * La fuga que este archivo prueba (team_id / app.tenant_bypass sin
     * limpiar tras el job) usa `set_config(..., is_local: false)` a nivel
     * de SESIÓN Postgres -- NO transaccional, así que RefreshDatabase no lo
     * revierte entre tests. Sin este tearDown(), la fuga real que cada test
     * demuestra se filtraría a CUALQUIER test que corra después en el mismo
     * proceso (incluidos archivos ajenos a esta clase), contaminando la
     * suite completa en vez de quedar contenida en el propio hallazgo.
     */
    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        if (PgsqlRowLevelSecurity::enabled()) {
            PgsqlRowLevelSecurity::clear();
        }

        parent::tearDown();
    }

    /**
     * El bypass de RLS se limpia DESPUÉS de crear las fixtures (no en
     * setUp()): CreatesTenantFixtures inserta filas por DB::table() crudo,
     * y con FORCE ROW LEVEL SECURITY esas inserciones necesitan el bypass
     * que TestCase::setUp() ya deja activo por default -- limpiarlo antes
     * de tiempo rompe la creación de fixtures con un error de RLS ajeno al
     * hallazgo que este test prueba.
     */
    private function resetTenantStateToClean(): void
    {
        setPermissionsTeamId(null);
        if (PgsqlRowLevelSecurity::enabled()) {
            PgsqlRowLevelSecurity::clear();
        }
    }

    public function test_inbound_email_job_leaves_permissions_team_id_set_after_finishing(): void
    {
        $fixture = $this->createTenantFixtureSet();
        $tenant = Client::find($fixture['client_id']);
        DB::table('sites')->where('id', $fixture['site_id'])->update(['client_id' => $tenant->id, 'is_active' => true]);
        DB::table('users')->where('id', $fixture['user_id'])->update(['client_id' => $tenant->id]);
        $requesterEmail = \App\Models\User::find($fixture['user_id'])->email;

        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $this->resetTenantStateToClean();
        $this->assertNull(getPermissionsTeamId(), 'Precondición: sin team_id antes de correr el job.');

        ProcessInboundTicket::dispatch($tenant->id, [
            'from' => $requesterEmail,
            'from_name' => 'Cliente',
            'to' => 'soporte@'.$tenant->portal_slug.'.tikara.mx',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<leak-test@empresa.test>',
        ]);

        // El job ya terminó (QUEUE_CONNECTION=sync en tests) -- el team_id
        // sigue siendo el del tenant procesado. Ningún código lo limpió.
        $this->assertSame(
            (string) $tenant->id,
            (string) getPermissionsTeamId(),
            'ProcessInboundTicket no restaura team_id=null al terminar -- queda pegado al tenant '
            .'del último email procesado para cualquier job siguiente en el mismo worker persistente.'
        );
    }

    public function test_inbound_email_job_leaves_rls_bypass_session_variable_set_after_finishing(): void
    {
        if (! PgsqlRowLevelSecurity::enabled()) {
            $this->markTestSkipped('Requiere Postgres + TENANCY_PGSQL_RLS=true.');
        }

        $fixture = $this->createTenantFixtureSet();
        $tenant = Client::find($fixture['client_id']);
        DB::table('sites')->where('id', $fixture['site_id'])->update(['client_id' => $tenant->id, 'is_active' => true]);
        DB::table('users')->where('id', $fixture['user_id'])->update(['client_id' => $tenant->id]);
        $requesterEmail = \App\Models\User::find($fixture['user_id'])->email;

        $now = now();
        DB::table('ticket_types')->insertOrIgnore(['id' => 3, 'name' => 'Solicitud de cambio', 'code' => 'change_request', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('priorities')->insertOrIgnore(['id' => 3, 'name' => 'Media', 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $this->resetTenantStateToClean();
        $before = DB::selectOne("select current_setting('app.tenant_bypass', true) as v")->v;
        $this->assertNotSame('true', $before, 'Precondición: bypass no debe estar activo antes del job.');

        ProcessInboundTicket::dispatch($tenant->id, [
            'from' => $requesterEmail,
            'from_name' => 'Cliente',
            'to' => 'soporte@'.$tenant->portal_slug.'.tikara.mx',
            'subject' => 'Ayuda',
            'body_plain' => 'Detalle',
            'message_id' => '<leak-test-rls@empresa.test>',
        ]);

        $after = DB::selectOne("select current_setting('app.tenant_bypass', true) as v")->v;
        $this->assertSame(
            'true',
            $after,
            'ProcessInboundTicket activa app.tenant_bypass=true (RLS bypass) y nunca lo revierte -- '
            .'cualquier query de otro job en la misma conexión persistente del worker corre SIN RLS.'
        );
    }
}
