<?php

namespace App\Http\Controllers\Inertia;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvLabel;
use App\Models\InvMaintenanceModality;
use App\Models\InvMaintenanceOrigin;
use App\Models\InvStatus;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientScopeService;
use App\Services\OperatorCatalogScopeService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Páginas Inertia del registro de activos (fase 2, port desde
 * HelpdeskECD2026). Solo GET — las mutaciones (crear/editar/borrar/subir
 * fotos) van por /api/inv-assets vía InvAssetController, igual que el
 * patrón ya establecido en fase 1 (CatalogPageController).
 */
class InvAssetPageController extends Controller
{
    public function __construct(
        protected ClientScopeService $clientScope,
        protected OperatorCatalogScopeService $catalogScope
    ) {}

    public function index(): Response
    {
        $user = Auth::user();

        $assets = $this->clientScope
            ->applyInventoryAssetScope(
                InvAsset::query()->with(['category', 'status', 'label', 'site']),
                $user
            )
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Assets/Index', [
            'assets' => $assets,
            'assetQuota' => $this->assetQuota($user),
            // Catálogos completos (fase de modal de alta/edición) -- el
            // diálogo de crear/editar vive montado sobre esta página.
            ...$this->formCatalogs(),
        ]);
    }

    public function show(InvAsset $inv_asset): Response
    {
        $this->authorizeAssetAccess($inv_asset);

        return Inertia::render('Inventory/Assets/Show', [
            'asset' => $inv_asset->load([
                'category', 'status', 'label', 'site', 'location', 'currentUser', 'images',
                'movements' => fn ($q) => $q->with(['user', 'previousUser', 'admin'])->orderByDesc('date'),
                'components' => fn ($q) => $q->orderBy('name'),
                'maintenances' => fn ($q) => $q->with(['origin', 'modality'])->orderByDesc('start_date'),
            ]),
            // Fase 3: catálogos para los diálogos de asignar/trasladar/dar de baja.
            'statuses' => $this->activeCatalog(InvStatus::class, 'inv_statuses', ['id', 'name', 'badge_class', 'assignable']),
            'sites' => Site::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => Location::where('is_active', true)->orderBy('name')->get(['id', 'name', 'site_id']),
            // Modal de editar (mismo diálogo que Index.jsx) necesita el set completo.
            'categories' => $this->activeCatalog(InvCategory::class, 'inv_categories'),
            'labels' => $this->activeCatalog(InvLabel::class, 'inv_labels'),
            'clientUsers' => User::where('client_id', $inv_asset->client_id)
                ->where('status', 'active')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'paternal_last_name', 'maternal_last_name']),
            // Fase 5: catálogos para el diálogo de registrar mantenimiento.
            'maintenanceOrigins' => $this->activeCatalog(InvMaintenanceOrigin::class, 'inv_maintenance_origins'),
            'maintenanceModalities' => $this->activeCatalog(InvMaintenanceModality::class, 'inv_maintenance_modalities'),
        ]);
    }

    private function formCatalogs(): array
    {
        return [
            'categories' => $this->activeCatalog(InvCategory::class, 'inv_categories'),
            'statuses' => $this->activeCatalog(InvStatus::class, 'inv_statuses', ['id', 'name', 'badge_class']),
            'labels' => $this->activeCatalog(InvLabel::class, 'inv_labels'),
            'sites' => Site::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => Location::where('is_active', true)->orderBy('name')->get(['id', 'name', 'site_id']),
        ];
    }

    /** Catálogos de fase 1, ya operador-scoped -- mismo criterio que CatalogPageController. */
    private function activeCatalog(string $modelClass, string $table, array $columns = ['id', 'name'])
    {
        return $this->catalogScope
            ->apply($modelClass::query()->where('is_active', true), Auth::user(), $table)
            ->orderBy('name')
            ->get($columns);
    }

    /** Cuota de activos por plan (ver plan "Cuota de activos por plan"), mismo shape que seatUsage() de TenantOnboardingController. */
    private function assetQuota(User $user): array
    {
        $clientId = $this->clientScope->resolveUserClientId($user);
        if (! $clientId) {
            return ['used' => 0, 'max' => null];
        }

        return [
            'used' => InvAsset::where('client_id', $clientId)->count(),
            'max' => Client::find($clientId)?->plan?->max_assets,
        ];
    }

    private function authorizeAssetAccess(InvAsset $inv_asset): void
    {
        $scoped = $this->clientScope->applyInventoryAssetScope(
            InvAsset::query()->whereKey($inv_asset->id),
            Auth::user()
        );

        abort_unless($scoped->exists(), 403);
    }
}
