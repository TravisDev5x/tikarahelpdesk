<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvStatus;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Auditoría de Inventario, fase 3.1 (CMDB -- relación Activo↔Ticket, la
 * brecha crítica del informe -- Sección L). Vincular un activo a un
 * ticket NO exige ningún permiso de Inventario, solo TicketPolicy::attach
 * -- ver TicketAssetController.
 */
class InvAssetTicketLinkTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $admin;

    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $operator = $this->makeUser('operator@test.local');
        $operator->update(['is_operator' => true]);
        $this->client = Client::create(['name' => 'Tenant Activo-Ticket', 'operator_user_id' => $operator->id, 'is_active' => true]);

        setPermissionsTeamId($this->client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->admin = $this->makeUser('admin@test.local');
        $this->admin->update(['client_id' => $this->client->id]);
        $this->admin->assignRole('admin');

        $this->catalog = $this->makeCatalog();
    }

    private function assetFixture(?int $clientId = null): InvAsset
    {
        $clientId ??= $this->client->id;
        $site = $this->makeSite($clientId);
        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);

        return InvAsset::create([
            'internal_tag' => 'TAG-'.uniqid(), 'name' => 'Laptop Dell',
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $clientId,
        ]);
    }

    private function makeTicket(?int $clientId = null): Ticket
    {
        $clientId ??= $this->client->id;
        $site = $this->makeSite($clientId);

        return Ticket::create([
            'subject' => 'Laptop no enciende',
            'folio' => 'TK-'.uniqid(),
            'area_origin_id' => $this->catalog['area_id'],
            'area_current_id' => $this->catalog['area_id'],
            'site_id' => $site,
            'client_id' => $clientId,
            'requester_id' => $this->admin->id,
            'ticket_type_id' => $this->catalog['ticket_type_id'],
            'priority_id' => $this->catalog['priority_id'],
            'ticket_state_id' => $this->catalog['ticket_state_id'],
        ]);
    }

    public function test_admin_can_link_and_unlink_an_asset_to_a_ticket(): void
    {
        $ticket = $this->makeTicket();
        $asset = $this->assetFixture();

        $link = $this->actingAs($this->admin, 'web')->postJson("/api/tickets/{$ticket->id}/assets", [
            'asset_id' => $asset->id,
        ]);
        $link->assertCreated();
        $this->assertDatabaseHas('inv_asset_ticket', ['inv_asset_id' => $asset->id, 'ticket_id' => $ticket->id]);

        $show = $this->actingAs($this->admin, 'web')->getJson("/api/tickets/{$ticket->id}");
        $show->assertOk();
        $this->assertSame($asset->id, $show->json('asset_links.0.asset.id'));

        $assetShow = $this->actingAs($this->admin, 'web')->getJson("/api/inv-assets/{$asset->id}");
        $assetShow->assertOk();
        $this->assertSame($ticket->id, $assetShow->json('ticket_links.0.ticket.id'));

        $this->actingAs($this->admin, 'web')
            ->deleteJson("/api/tickets/{$ticket->id}/assets/{$link->json('id')}")
            ->assertNoContent();
        $this->assertDatabaseMissing('inv_asset_ticket', ['inv_asset_id' => $asset->id, 'ticket_id' => $ticket->id]);
    }

    public function test_cannot_link_an_asset_from_another_tenant(): void
    {
        $ticket = $this->makeTicket();

        $otherOperator = $this->makeUser('operator2@test.local');
        $otherOperator->update(['is_operator' => true]);
        $otherClient = Client::create(['name' => 'Otro tenant', 'operator_user_id' => $otherOperator->id, 'is_active' => true]);
        $foreignAsset = $this->assetFixture($otherClient->id);

        $response = $this->actingAs($this->admin, 'web')->postJson("/api/tickets/{$ticket->id}/assets", [
            'asset_id' => $foreignAsset->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_link_the_same_asset_twice(): void
    {
        $ticket = $this->makeTicket();
        $asset = $this->assetFixture();

        $this->actingAs($this->admin, 'web')->postJson("/api/tickets/{$ticket->id}/assets", ['asset_id' => $asset->id])->assertCreated();
        $again = $this->actingAs($this->admin, 'web')->postJson("/api/tickets/{$ticket->id}/assets", ['asset_id' => $asset->id]);

        $again->assertStatus(422);
    }

    public function test_search_is_scoped_to_the_tickets_tenant(): void
    {
        $ticket = $this->makeTicket();
        $ownAsset = $this->assetFixture();
        $ownAsset->update(['name' => 'Laptop Buscable']);

        $otherOperator = $this->makeUser('operator3@test.local');
        $otherOperator->update(['is_operator' => true]);
        $otherClient = Client::create(['name' => 'Otro tenant 2', 'operator_user_id' => $otherOperator->id, 'is_active' => true]);
        $foreignAsset = $this->assetFixture($otherClient->id);
        $foreignAsset->update(['name' => 'Laptop Buscable']);

        $response = $this->actingAs($this->admin, 'web')->getJson("/api/tickets/{$ticket->id}/assets/search?q=Buscable");

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($ownAsset->id));
        $this->assertFalse($ids->contains($foreignAsset->id));
    }

    private function makeSite(int $clientId): int
    {
        $now = now();

        return DB::table('sites')->insertGetId([
            'client_id' => $clientId,
            'name' => 'S'.uniqid(),
            'code' => 'X'.uniqid(),
            'type' => 'physical',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
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
