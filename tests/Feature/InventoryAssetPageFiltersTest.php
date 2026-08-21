<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * InvAssetPageController::index() (Inertia, no la API) -- hasta este fix
 * NUNCA aplicaba filtros/orden/per_page pese a que Index.jsx ya los mandaba
 * en la URL (el formulario "Aplicar filtros" no hacía nada). Cubre
 * filtros, paginación y el orden PEPS/UEPS por fecha de compra.
 */
class InventoryAssetPageFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'inventory.manage_assets', 'guard_name' => 'web']);
    }

    private function fixtures(): array
    {
        $client = Client::factory()->create();
        $site = $this->makeSite($client->id);
        $admin = $this->clientUser($client->id, $site);
        setPermissionsTeamId(config('tenancy.super_admin_team_id'));
        $admin->givePermissionTo('inventory.manage_assets');

        $category = InvCategory::firstOrCreate(['name' => 'Laptops'], ['is_active' => true]);
        $status = InvStatus::firstOrCreate(['name' => 'Disponible'], ['assignable' => true, 'is_active' => true]);

        return compact('client', 'site', 'admin', 'category', 'status');
    }

    private function makeAsset(array $fx, string $tag, array $overrides = []): InvAsset
    {
        return InvAsset::create(array_merge([
            'internal_tag' => $tag, 'name' => "Asset {$tag}",
            'category_id' => $fx['category']->id, 'status_id' => $fx['status']->id,
            'site_id' => $fx['site'], 'client_id' => $fx['client']->id,
        ], $overrides));
    }

    /**
     * Regresión: en una visita sin query params, $request->only([...]) da un
     * array PHP vacío, que json_encode serializa como "[]", no "{}". En el
     * navegador "[].sort" NO es undefined -- es Array.prototype.sort (una
     * función real), así que "filters.sort ?? DEFAULT_SORT" nunca caía al
     * default y useState() terminaba "inicializando" con esa función,
     * reventando con "can't convert undefined to object" (repro real,
     * encontrado en pantalla, no solo teórico). El fix es (object) sobre
     * el resultado de only().
     */
    public function test_bare_visit_sends_filters_as_json_object_not_array(): void
    {
        $fx = $this->fixtures();

        $response = $this->actingAs($fx['admin'], 'web')->get('/inventory/assets');

        $response->assertOk();
        $response->assertSee('"filters":{}', false);
        $response->assertDontSee('"filters":[]', false);
    }

    public function test_index_page_applies_search_filter(): void
    {
        $fx = $this->fixtures();
        $this->makeAsset($fx, 'MATCH-1', ['name' => 'Laptop Dell']);
        $this->makeAsset($fx, 'NOMATCH-1', ['name' => 'Monitor Samsung']);

        $response = $this->actingAs($fx['admin'], 'web')->get('/inventory/assets?search=Dell');

        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Assets/Index', shouldExist: false)
            ->has('assets.data', 1)
            ->where('assets.data.0.internal_tag', 'MATCH-1')
        );
    }

    public function test_index_page_applies_assigned_filter(): void
    {
        $fx = $this->fixtures();
        $owner = $this->clientUser($fx['client']->id, $fx['site']);
        $this->makeAsset($fx, 'ASSIGNED-1', ['current_user_id' => $owner->id]);
        $this->makeAsset($fx, 'FREE-1');

        $response = $this->actingAs($fx['admin'], 'web')->get('/inventory/assets?assigned=0');

        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Assets/Index', shouldExist: false)
            ->has('assets.data', 1)
            ->where('assets.data.0.internal_tag', 'FREE-1')
        );
    }

    public function test_index_page_paginates_with_per_page(): void
    {
        $fx = $this->fixtures();
        for ($i = 1; $i <= 25; $i++) {
            $this->makeAsset($fx, 'PAGE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $response = $this->actingAs($fx['admin'], 'web')->get('/inventory/assets?per_page=10');

        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Assets/Index', shouldExist: false)
            ->has('assets.data', 10)
            ->where('assets.per_page', 10)
            ->where('assets.total', 25)
            ->where('assets.last_page', 3)
        );

        $page2 = $this->actingAs($fx['admin'], 'web')->get('/inventory/assets?per_page=10&page=2');
        $page2->assertInertia(fn ($page) => $page
            ->component('Inventory/Assets/Index', shouldExist: false)
            ->has('assets.data', 10)
            ->where('assets.current_page', 2)
        );
    }

    public function test_index_page_sorts_peps_and_ueps_by_purchase_date_with_nulls_last(): void
    {
        $fx = $this->fixtures();
        $old = $this->makeAsset($fx, 'OLD-1', ['purchase_date' => '2024-01-01']);
        $mid = $this->makeAsset($fx, 'MID-1', ['purchase_date' => '2024-06-01']);
        $noDate = $this->makeAsset($fx, 'NODATE-1');

        $peps = $this->actingAs($fx['admin'], 'web')->get('/inventory/assets?sort=peps');
        $peps->assertInertia(fn ($page) => $page
            ->component('Inventory/Assets/Index', shouldExist: false)
            ->where('assets.data.0.internal_tag', 'OLD-1')
            ->where('assets.data.1.internal_tag', 'MID-1')
            ->where('assets.data.2.internal_tag', 'NODATE-1')
        );

        $ueps = $this->actingAs($fx['admin'], 'web')->get('/inventory/assets?sort=ueps');
        $ueps->assertInertia(fn ($page) => $page
            ->component('Inventory/Assets/Index', shouldExist: false)
            ->where('assets.data.0.internal_tag', 'MID-1')
            ->where('assets.data.1.internal_tag', 'OLD-1')
            ->where('assets.data.2.internal_tag', 'NODATE-1')
        );
    }

    /**
     * Auditoría de Inventario (fase 1, crítico): esta página nunca leía
     * ?user_id=, a diferencia de InvAssetController::index() (API) -- el
     * click en "Top responsables" de Monitor.jsx aterrizaba en la lista
     * completa sin filtrar.
     */
    public function test_index_page_applies_user_id_filter(): void
    {
        $fx = $this->fixtures();
        $owner = $this->clientUser($fx['client']->id, $fx['site']);
        $other = $this->clientUser($fx['client']->id, $fx['site']);
        $this->makeAsset($fx, 'OWNED-1', ['current_user_id' => $owner->id]);
        $this->makeAsset($fx, 'OTHER-1', ['current_user_id' => $other->id]);

        $response = $this->actingAs($fx['admin'], 'web')->get("/inventory/assets?user_id={$owner->id}");

        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Assets/Index', shouldExist: false)
            ->has('assets.data', 1)
            ->where('assets.data.0.internal_tag', 'OWNED-1')
        );
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
