<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvAssetRequest;
use App\Http\Requests\UpdateInvAssetRequest;
use App\Models\Client;
use App\Models\InvAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class InvAssetController extends Controller
{
    use AuthorizesInvAssetAccess;

    public function index(Request $request)
    {
        $query = $this->clientScope()->applyInventoryAssetScope(
            InvAsset::query()->with(['category', 'status', 'label', 'site', 'location', 'currentUser']),
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
            'category', 'status', 'label', 'site', 'location', 'currentUser', 'images',
            'movements' => fn ($q) => $q->with(['user', 'previousUser', 'admin'])->orderByDesc('date'),
            'components' => fn ($q) => $q->orderBy('name'),
            'maintenances' => fn ($q) => $q->with(['origin', 'modality'])->orderByDesc('start_date'),
        ]);
    }

    public function store(StoreInvAssetRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();

        if (! $this->clientScope()->assertSiteAccessible($user, (int) $data['site_id'])) {
            return response()->json(['message' => 'La sede seleccionada no pertenece a tu cliente'], 422);
        }

        $data['client_id'] = $this->clientScope()->syncClientIdFromSite((int) $data['site_id']);

        if ($quotaError = $this->assertAssetQuota((int) $data['client_id'])) {
            return response()->json(['message' => $quotaError], 422);
        }

        $data['uuid'] = $data['uuid'] ?? (string) Str::uuid();
        $data['specs'] = $this->packSpecs($data['specs'] ?? null);

        $asset = InvAsset::create($data);

        return response()->json($asset->load(['category', 'status', 'label', 'site', 'location']), 201);
    }

    public function update(UpdateInvAssetRequest $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();
        $data = $request->validated();

        if (! $this->clientScope()->assertSiteAccessible($user, (int) $data['site_id'])) {
            return response()->json(['message' => 'La sede seleccionada no pertenece a tu cliente'], 422);
        }

        $data['client_id'] = $this->clientScope()->syncClientIdFromSite((int) $data['site_id']);
        $data['specs'] = $this->packSpecs($data['specs'] ?? null);

        $inv_asset->update($data);

        return response()->json($inv_asset->load(['category', 'status', 'label', 'site', 'location']));
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

    /** specs se captura como texto libre en fase 2 (ver plan) -- se guarda como json simple. */
    private function packSpecs(?string $freeform): ?array
    {
        if ($freeform === null || trim($freeform) === '') {
            return null;
        }

        return ['notes' => $freeform];
    }
}
