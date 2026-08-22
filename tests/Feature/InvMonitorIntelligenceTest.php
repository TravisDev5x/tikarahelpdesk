<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvAssetTicket;
use App\Models\InvCategory;
use App\Models\InvMovement;
use App\Models\InvStatus;
use App\Models\InvWarranty;
use App\Models\Ticket;
use App\Models\User;
use App\Services\InvMonitorAlertsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditoría de Inventario, fase 5 (Inteligencia): renovación predictiva,
 * scoring de anomalías de traslados, "activos con demasiados tickets".
 */
class InvMonitorIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.view_assets', 'guard_name' => 'web']);
    }

    private function fixtures(): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.view_assets');

        $category = InvCategory::create(['name' => 'Laptops', 'is_active' => true]);
        $status = InvStatus::create(['name' => 'Disponible', 'assignable' => true, 'is_active' => true]);
        $asset = fn (string $tag) => InvAsset::create([
            'internal_tag' => $tag, 'name' => "Asset {$tag}",
            'category_id' => $category->id, 'status_id' => $status->id,
            'site_id' => $site, 'client_id' => $client->id,
        ]);

        return compact('client', 'site', 'admin', 'asset');
    }

    public function test_warranty_expiring_includes_rows_from_inv_warranties_only(): void
    {
        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->fixtures();
        $laptop = $asset('LAP-1'); // sin warranty_expiry en la columna del activo

        InvWarranty::create([
            'asset_id' => $laptop->id, 'client_id' => $client->id, 'provider' => 'Dell',
            'ends_at' => now()->addDays(10),
        ]);

        $rows = app(InvMonitorAlertsService::class)->warrantyExpiring($admin);

        $this->assertCount(1, $rows);
        $this->assertSame('inv_warranties', $rows->first()['source']);
        $this->assertSame('critica', $rows->first()['severity']);
    }

    public function test_warranty_expiring_dedupes_when_both_sources_apply_and_keeps_the_soonest(): void
    {
        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->fixtures();
        $laptop = $asset('LAP-1');
        $laptop->update(['warranty_expiry' => now()->addDays(20)->toDateString()]);

        InvWarranty::create([
            'asset_id' => $laptop->id, 'client_id' => $client->id, 'provider' => 'Dell',
            'ends_at' => now()->addDays(5),
        ]);

        $rows = app(InvMonitorAlertsService::class)->warrantyExpiring($admin);

        $this->assertCount(1, $rows);
        $this->assertSame(now()->addDays(5)->toDateString(), $rows->first()['expires_on']);
    }

    public function test_warranty_expiring_severity_buckets(): void
    {
        ['admin' => $admin, 'asset' => $asset] = $this->fixtures();

        $vencida = $asset('LAP-VENCIDA');
        $vencida->update(['warranty_expiry' => now()->subDays(5)->toDateString()]);

        $critica = $asset('LAP-CRITICA');
        $critica->update(['warranty_expiry' => now()->addDays(10)->toDateString()]);

        $proxima = $asset('LAP-PROXIMA');
        $proxima->update(['warranty_expiry' => now()->addDays(25)->toDateString()]);

        $rows = app(InvMonitorAlertsService::class)->warrantyExpiring($admin)->keyBy('internal_tag');

        $this->assertSame('vencida', $rows['LAP-VENCIDA']['severity']);
        $this->assertSame('critica', $rows['LAP-CRITICA']['severity']);
        $this->assertSame('proxima', $rows['LAP-PROXIMA']['severity']);
    }

    public function test_repeated_transfers_classifies_severity_by_window(): void
    {
        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->fixtures();
        $watch = $asset('LAP-WATCH');
        $critical = $asset('LAP-CRITICAL');

        // "watch": 2 traslados en 24h, nada más.
        $this->makeTransfer($watch, $client, $admin, now()->subHours(2));
        $this->makeTransfer($watch, $client, $admin, now()->subHours(1));

        // "critica": 4 traslados en 24h.
        foreach (range(1, 4) as $i) {
            $this->makeTransfer($critical, $client, $admin, now()->subHours($i));
        }

        $rows = app(InvMonitorAlertsService::class)->repeatedTransfers($admin)->keyBy('internal_tag');

        $this->assertSame('atencion', $rows['LAP-WATCH']['severity']);
        $this->assertSame(2, $rows['LAP-WATCH']['transfers_24h']);
        $this->assertSame('critica', $rows['LAP-CRITICAL']['severity']);
        $this->assertSame(4, $rows['LAP-CRITICAL']['transfers_24h']);
    }

    public function test_problem_assets_flags_assets_with_enough_recent_tickets(): void
    {
        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->fixtures();
        $problem = $asset('LAP-PROBLEM');
        $fine = $asset('LAP-FINE');

        $catalog = $this->makeTicketCatalog();

        foreach (range(1, 3) as $i) {
            $ticket = Ticket::create([
                'folio' => "T-{$problem->id}-{$i}", 'subject' => 'Falla',
                'area_origin_id' => $catalog['area_id'], 'area_current_id' => $catalog['area_id'],
                'site_id' => $problem->site_id, 'client_id' => $client->id, 'requester_id' => $admin->id,
                'ticket_type_id' => $catalog['ticket_type_id'], 'priority_id' => $catalog['priority_id'],
                'ticket_state_id' => $catalog['ticket_state_id'],
            ]);
            InvAssetTicket::create([
                'inv_asset_id' => $problem->id, 'ticket_id' => $ticket->id,
                'client_id' => $client->id, 'linked_by' => $admin->id,
            ]);
        }

        $oldTicket = Ticket::create([
            'folio' => "T-{$fine->id}-old", 'subject' => 'Falla vieja',
            'area_origin_id' => $catalog['area_id'], 'area_current_id' => $catalog['area_id'],
            'site_id' => $fine->site_id, 'client_id' => $client->id, 'requester_id' => $admin->id,
            'ticket_type_id' => $catalog['ticket_type_id'], 'priority_id' => $catalog['priority_id'],
            'ticket_state_id' => $catalog['ticket_state_id'],
        ]);
        $link = InvAssetTicket::create([
            'inv_asset_id' => $fine->id, 'ticket_id' => $oldTicket->id,
            'client_id' => $client->id, 'linked_by' => $admin->id,
        ]);
        DB::table('inv_asset_ticket')->where('id', $link->id)->update(['created_at' => now()->subDays(120)]);

        $rows = app(InvMonitorAlertsService::class)->problemAssets($admin);

        $this->assertCount(1, $rows);
        $this->assertSame('LAP-PROBLEM', $rows->first()['internal_tag']);
        $this->assertSame(3, $rows->first()['ticket_count']);
    }

    public function test_counts_includes_problem_assets_and_matches_row_counts(): void
    {
        ['client' => $client, 'admin' => $admin, 'asset' => $asset] = $this->fixtures();
        $laptop = $asset('LAP-1');
        $laptop->update(['warranty_expiry' => now()->addDays(5)->toDateString()]);

        $service = app(InvMonitorAlertsService::class);
        $counts = $service->counts($admin);

        $this->assertArrayHasKey('problem_assets', $counts);
        $this->assertSame($service->warrantyExpiring($admin)->count(), $counts['warranty_expiring']);
        $this->assertSame($service->problemAssets($admin)->count(), $counts['problem_assets']);
    }

    public function test_monitor_and_wallboard_pages_still_render_with_problem_assets_prop(): void
    {
        ['admin' => $admin] = $this->fixtures();

        foreach (['/inventory/monitor', '/inventory/wallboard'] as $path) {
            $response = $this->actingAs($admin, 'web')->get($path);
            $response->assertOk();
            $response->assertInertia(fn ($page) => $page->has('problemAssets'));
        }
    }

    private function makeTransfer(InvAsset $asset, Client $client, User $admin, $date): void
    {
        InvMovement::create([
            'asset_id' => $asset->id, 'type' => 'TRASLADO', 'admin_id' => $admin->id,
            'client_id' => $client->id, 'date' => $date,
        ]);
    }

    private function makeTicketCatalog(): array
    {
        $now = now();

        return [
            'area_id' => DB::table('areas')->insertGetId(['name' => 'Area'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'priority_id' => DB::table('priorities')->insertGetId(['name' => 'Prio'.uniqid(), 'level' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_state_id' => DB::table('ticket_states')->insertGetId(['name' => 'Estado'.uniqid(), 'code' => 'st'.uniqid(), 'is_active' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now]),
            'ticket_type_id' => DB::table('ticket_types')->insertGetId(['name' => 'Tipo'.uniqid(), 'code' => 'ty'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]),
        ];
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

    private function clientUser(int $clientId, int $siteId): User
    {
        $now = now();
        $areaId = DB::table('areas')->insertGetId(['name' => 'A'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $positionId = DB::table('positions')->insertGetId(['name' => 'P'.uniqid(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return User::create([
            'first_name' => 'T', 'paternal_last_name' => 'U',
            'email' => uniqid().'@t.local', 'password' => Hash::make('x'),
            'employee_number' => (string) random_int(100000, 999999),
            'area_id' => $areaId, 'position_id' => $positionId, 'site_id' => $siteId,
            'client_id' => $clientId, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }
}
