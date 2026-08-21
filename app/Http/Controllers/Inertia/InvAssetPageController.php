<?php

namespace App\Http\Controllers\Inertia;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvLabel;
use App\Models\InvManufacturer;
use App\Models\InvMaintenanceModality;
use App\Models\InvMaintenanceOrigin;
use App\Models\InvStatus;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientScopeService;
use App\Services\OperatorCatalogScopeService;
use App\Support\Inventory\AssetSpecSchema;
use Illuminate\Http\Request;
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

    public function index(Request $request): Response
    {
        $user = Auth::user();

        $query = $this->clientScope->applyInventoryAssetScope(
            InvAsset::query()->with(['category', 'status', 'label', 'site']),
            $user
        );

        // Filtros -- antes de este fix esta página NUNCA los aplicaba (el
        // formulario de Index.jsx los mandaba en la URL, pero index() los
        // ignoraba por completo; solo InvAssetController::index(), la API,
        // los tenía). Mismas reglas que esa API, calcadas aquí.
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if ($request->filled('status_id')) {
            $query->where('status_id', $request->input('status_id'));
        }
        if ($request->filled('site_id')) {
            $query->where('site_id', $request->input('site_id'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('internal_tag', 'like', "%{$search}%")
                    ->orWhere('serial', 'like', "%{$search}%");
            });
        }
        if ($request->filled('assigned')) {
            $request->input('assigned') === '1'
                ? $query->whereNotNull('current_user_id')
                : $query->whereNull('current_user_id');
        }
        // Auditoría de Inventario (fase 1, crítico): esta página nunca leía
        // ?user_id=, a diferencia de InvAssetController::index() (API) --
        // el click en una barra de "Top responsables" de Monitor.jsx
        // (router.visit(`/inventory/assets?user_id=${id}`)) navegaba aquí
        // pero aterrizaba en la lista completa sin filtrar.
        if ($request->filled('user_id')) {
            $query->where('current_user_id', $request->input('user_id'));
        }

        $this->applySort($query, $request->input('sort'));

        $perPage = (int) $request->input('per_page', 25);
        $perPage = in_array($perPage, [10, 15, 25, 50, 100], true) ? $perPage : 25;

        $assets = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Inventory/Assets/Index', [
            'assets' => $assets,
            'assetQuota' => $this->assetQuota($user),
            // (object), no array: PHP serializa un array vacío como JSON "[]",
            // no "{}" -- en el navegador, "[].sort" NO es undefined, es
            // Array.prototype.sort (una función real), así que
            // "filters.sort ?? DEFAULT_SORT" nunca caía al default y
            // useState() terminaba "inicializando" con esa función. Con
            // (object) siempre es "{}", y "{}.sort" sí es undefined.
            'filters' => (object) $request->only(['search', 'category_id', 'status_id', 'site_id', 'assigned', 'user_id', 'sort', 'per_page']),
            // Catálogos completos (fase de modal de alta/edición/detalle) --
            // los diálogos de crear/editar/ver un activo viven montados
            // sobre esta página, ya no hay ruta /show aparte.
            ...$this->formCatalogs(),
            // Ficha técnica estructurada (fase 2.1) -- schema estático por
            // category.type, misma fuente que usa el backend para filtrar
            // en InvAssetController::syncSpecs(), cero riesgo de desincronía.
            'assetSpecSchema' => AssetSpecSchema::all(),
            'clientUsers' => $this->clientUsers($user),
            'maintenanceOrigins' => $this->activeCatalog(InvMaintenanceOrigin::class, 'inv_maintenance_origins'),
            'maintenanceModalities' => $this->activeCatalog(InvMaintenanceModality::class, 'inv_maintenance_modalities'),
            // ?asset=ID (ej. desde un link de Monitor.jsx) -- Index.jsx abre
            // el modal de detalle de ese activo al montar. null si no vino,
            // no es visible para este usuario, o no existe -- sin romper la
            // página, el modal simplemente no se abre.
            'initialAssetId' => $this->resolveInitialAssetId($request, $user),
        ]);
    }

    private function resolveInitialAssetId(Request $request, User $user): ?int
    {
        $id = $request->input('asset');
        if (! $id) {
            return null;
        }

        $scoped = $this->clientScope->applyInventoryAssetScope(InvAsset::query()->whereKey($id), $user);

        return $scoped->exists() ? (int) $id : null;
    }

    /** Usuarios del propio cliente del usuario en sesión, para el diálogo de asignar. */
    private function clientUsers(User $user)
    {
        $clientId = $this->clientScope->resolveUserClientId($user);

        return User::where('client_id', $clientId)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'paternal_last_name', 'maternal_last_name']);
    }

    /**
     * "PEPS"/"UEPS" (FIFO/LIFO) por fecha de compra -- orden por defecto es
     * por nombre. CASE WHEN en vez de "NULLS LAST" (sintaxis de Postgres, no
     * portable a SQLite, que es el driver de la suite de tests) para que los
     * activos sin fecha de compra siempre queden al final sin importar la
     * dirección.
     */
    private function applySort($query, ?string $sort): void
    {
        match ($sort) {
            'peps' => $query
                ->orderByRaw('CASE WHEN purchase_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('purchase_date')
                ->orderBy('created_at'),
            'ueps' => $query
                ->orderByRaw('CASE WHEN purchase_date IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('purchase_date')
                ->orderByDesc('created_at'),
            default => $query->orderBy('name'),
        };
    }

    private function formCatalogs(): array
    {
        return [
            'categories' => $this->activeCatalog(InvCategory::class, 'inv_categories'),
            // "assignable" -- lo necesita el diálogo de "Dar de baja" del
            // detalle (filtra a los estatus marcados como no asignables).
            'statuses' => $this->activeCatalog(InvStatus::class, 'inv_statuses', ['id', 'name', 'badge_class', 'assignable']),
            'labels' => $this->activeCatalog(InvLabel::class, 'inv_labels'),
            'manufacturers' => $this->activeCatalog(InvManufacturer::class, 'inv_manufacturers'),
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

}
