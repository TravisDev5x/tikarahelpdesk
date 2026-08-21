<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvAssetRequest;
use App\Http\Requests\UpdateInvAssetRequest;
use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvAssetSpec;
use App\Support\Inventory\AssetSpecSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class InvAssetController extends Controller
{
    use AuthorizesInvAssetAccess;

    public function index(Request $request)
    {
        $query = $this->clientScope()->applyInventoryAssetScope(
            InvAsset::query()->with(['category', 'manufacturer', 'status', 'label', 'site', 'location', 'currentUser']),
            Auth::user()
        );

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
        if ($request->filled('user_id')) {
            $query->where('current_user_id', $request->input('user_id'));
        }

        return $query->orderBy('name')->paginate(25);
    }

    public function show(InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);

        // Detalle completo (fase de modal de detalle) -- el activo se ve en
        // un diálogo montado sobre Index.jsx, no una página aparte; este
        // endpoint es ahora la única fuente de datos para esa vista, mismas
        // relaciones que antes cargaba InvAssetPageController::show().
        return $inv_asset->load([
            'category', 'manufacturer', 'status', 'label', 'site', 'location', 'currentUser', 'images', 'specs', 'documents',
            'warranties' => fn ($q) => $q->orderByDesc('ends_at'),
            'disposal' => fn ($q) => $q->with('authorizedBy'),
            'movements' => fn ($q) => $q->with(['user', 'previousUser', 'admin'])->orderByDesc('date'),
            'components' => fn ($q) => $q->orderBy('name'),
            'maintenances' => fn ($q) => $q->with(['origin', 'modality'])->orderByDesc('start_date'),
        ]);
    }

    public function store(StoreInvAssetRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();
        $specs = $data['specs'] ?? null;
        unset($data['specs']);

        if (! $this->clientScope()->assertSiteAccessible($user, (int) $data['site_id'])) {
            return response()->json(['message' => 'La sede seleccionada no pertenece a tu cliente'], 422);
        }

        $data['client_id'] = $this->clientScope()->syncClientIdFromSite((int) $data['site_id']);

        if ($quotaError = $this->assertAssetQuota((int) $data['client_id'])) {
            return response()->json(['message' => $quotaError], 422);
        }

        $data['uuid'] = $data['uuid'] ?? (string) Str::uuid();

        $asset = InvAsset::create($data);
        $this->syncSpecs($asset, $specs);

        return response()->json($asset->load(['category', 'manufacturer', 'status', 'label', 'site', 'location', 'specs']), 201);
    }

    public function update(UpdateInvAssetRequest $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();
        $data = $request->validated();
        $specs = $data['specs'] ?? null;
        unset($data['specs']);

        if (! $this->clientScope()->assertSiteAccessible($user, (int) $data['site_id'])) {
            return response()->json(['message' => 'La sede seleccionada no pertenece a tu cliente'], 422);
        }

        $data['client_id'] = $this->clientScope()->syncClientIdFromSite((int) $data['site_id']);

        $inv_asset->update($data);
        $this->syncSpecs($inv_asset, $specs);

        return response()->json($inv_asset->load(['category', 'manufacturer', 'status', 'label', 'site', 'location', 'specs']));
    }

    public function destroy(InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $inv_asset->delete();

        return response()->noContent();
    }

    /** Cuota de activos por plan (ver plan "Cuota de activos por plan") -- null si no hay límite o no se alcanzó. */
    private function assertAssetQuota(int $clientId): ?string
    {
        $max = Client::find($clientId)?->plan?->max_assets;
        if ($max === null) {
            return null;
        }

        $used = InvAsset::where('client_id', $clientId)->count();
        if ($used >= $max) {
            return "Alcanzaste el límite de activos de tu plan ({$max}). Contacta a soporte para ampliarlo.";
        }

        return null;
    }

    /**
     * Ficha técnica estructurada (fase 2.1) -- sincroniza inv_asset_specs
     * contra el schema del tipo de categoría (App\Support\Inventory\AssetSpecSchema),
     * filtrando en silencio cualquier key fuera de ese schema (el único
     * llamador real es AssetFormDialog.jsx, que ya solo manda keys
     * válidas -- no vale la pena un 422 por esto). inv_assets.specs (json,
     * texto libre) ya no se escribe pero se deja intacto para filas viejas.
     *
     * @param  array<int, array{key?: string, value?: string}>|null  $specs
     */
    private function syncSpecs(InvAsset $asset, ?array $specs): void
    {
        $asset->loadMissing('category');
        $validKeys = AssetSpecSchema::validKeysForType($asset->category?->type);

        $incoming = collect($specs ?? [])
            ->filter(fn ($row) => in_array($row['key'] ?? null, $validKeys, true) && filled($row['value'] ?? null))
            ->keyBy('key');

        $asset->specs()->whereNotIn('key', $incoming->keys())->delete();

        foreach ($incoming as $key => $row) {
            InvAssetSpec::updateOrCreate(
                ['asset_id' => $asset->id, 'key' => $key],
                ['client_id' => $asset->client_id, 'value' => $row['value']]
            );
        }
    }
}
