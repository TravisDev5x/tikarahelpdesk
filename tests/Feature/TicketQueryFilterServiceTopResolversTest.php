<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use App\Services\TicketQueryFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Auditoría 2026-08-10, hallazgo de escalabilidad: TicketAnalyticsController
 * pasaba a topResolvers() una Collection materializada con TODOS los ids del
 * scope (pluck('id') -- sin límite, interpolados como literales en un
 * whereIn() gigante). Cambiado a pasar el query builder (subquery SQL), el
 * mismo patrón que ya usaba ResolbebController. topResolvers() ya soportaba
 * ambos casos (ver su docblock: @param Builder|Collection<int,int>) -- este
 * test confirma que el cambio de un caso al otro es puramente de
 * rendimiento, sin alterar ningún número.
 */
class TicketQueryFilterServiceTopResolversTest extends TestCase
{
    use RefreshDatabase;

    public function test_passing_a_builder_subquery_returns_the_same_result_as_a_materialized_collection(): void
    {
        $client = Client::create(['name' => 'Tenant TopResolvers', 'is_active' => true]);
        $requester = $this->makeUser('requester-'.uniqid().'@test.local', $client->id);
        $resolverA = $this->makeUser('resolver-a-'.uniqid().'@test.local', $client->id);
        $resolverB = $this->makeUser('resolver-b-'.uniqid().'@test.local', $client->id);
        $catalog = $this->makeCatalog();

        $finalStateId = DB::table('ticket_states')->insertGetId([
            'name' => 'Cerrado-'.uniqid(), 'code' => 'cl'.uniqid(), 'is_active' => true, 'is_final' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $site = DB::table('sites')->insertGetId([
            'name' => 'Sede-'.uniqid(), 'client_id' => $client->id, 'type' => 'physical', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ticketIds = [];
        // resolverA cierra 3, resolverB cierra 1 -- orden esperado: A primero.
        for ($i = 0; $i < 4; $i++) {
            $ticket = Ticket::create([
                'subject' => "Ticket {$i}",
                'folio' => 'TR-'.uniqid(),
                'area_origin_id' => $catalog['area_id'],
                'area_current_id' => $catalog['area_id'],
                'site_id' => $site,
                'client_id' => $client->id,
                'requester_id' => $requester->id,
                'ticket_type_id' => $catalog['ticket_type_id'],
                'priority_id' => $catalog['priority_id'],
                'ticket_state_id' => $finalStateId,
            ]);
            $ticketIds[] = $ticket->id;

            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $i < 3 ? $resolverA->id : $resolverB->id,
                'action' => 'status_changed',
                'ticket_state_id' => $finalStateId,
            ]);
        }

        $finalStateIds = collect([$finalStateId]);
        $filters = app(TicketQueryFilterService::class);

        // Camino VIEJO: colección materializada (lo que hacía TicketAnalyticsController).
        $ticketIdsCollection = Ticket::whereIn('id', $ticketIds)->pluck('id');
        $resultFromCollection = $filters->topResolvers($ticketIdsCollection, $finalStateIds, 5);

        // Camino NUEVO: subquery (lo que ya hacía ResolbebController, y ahora también Analytics).
        $ticketIdsBuilder = Ticket::whereIn('id', $ticketIds)->select('id');
        $resultFromBuilder = $filters->topResolvers($ticketIdsBuilder, $finalStateIds, 5);

        $this->assertSame($resultFromCollection->toArray(), $resultFromBuilder->toArray(), 'El refactor de rendimiento no debe cambiar ningún número.');

        // Y que el número en sí sea el correcto, no solo que ambos caminos coincidan entre ellos.
        $this->assertSame($resolverA->id, $resultFromBuilder->first()['user_id']);
        $this->assertSame(3, $resultFromBuilder->first()['total']);
        $this->assertSame($resolverB->id, $resultFromBuilder->last()['user_id']);
        $this->assertSame(1, $resultFromBuilder->last()['total']);
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

    private function makeCatalog(): array
    {
        $now = now();

        return [
            'area_id' => DB::table('areas')->insertGetId(['name' => 'Area'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'priority_id' => DB::table('priorities')->insertGetId(['name' => 'Prio'.uniqid(), 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_type_id' => DB::table('ticket_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
        ];
    }
}
