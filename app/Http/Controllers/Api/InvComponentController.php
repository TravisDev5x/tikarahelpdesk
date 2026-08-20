<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvComponentAccess;
use App\Http\Controllers\Controller;
use App\Models\InvComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvComponentController extends Controller
{
    use AuthorizesInvComponentAccess;

    public function index(Request $request)
    {
        $query = $this->clientScope()->applyInventoryComponentScope(
            InvComponent::query()->with(['asset', 'originAsset']),
            Auth::user()
        );

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('serie', 'like', "%{$search}%")
                    ->orWhere('modelo', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('name')->paginate(25);
    }

    public function show(InvComponent $inv_component)
    {
        $this->authorizeComponentAccess($inv_component);

        return $inv_component->load(['asset', 'originAsset']);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'asset_id' => 'nullable|exists:inv_assets,id',
            'name' => 'required|string|max:255',
            'marca' => 'nullable|string|max:255',
            'modelo' => 'nullable|string|max:255',
            'serie' => 'nullable|string|max:255',
            'capacidad' => 'nullable|string|max:255',
            'observacion' => 'nullable|string|max:2000',
            'status' => 'nullable|string|max:255',
        ]);

        if (! empty($data['asset_id']) && ! $this->assetAccessible((int) $data['asset_id'], $user)) {
            return response()->json(['message' => 'El activo seleccionado no pertenece a tu cliente'], 422);
        }

        $clientId = $this->resolveClientId($data['asset_id'] ?? null, $user);
        if (! $clientId) {
            return response()->json(['message' => 'No se pudo determinar el cliente para este componente'], 422);
        }

        $data['client_id'] = $clientId;
        $component = InvComponent::create($data);

        return response()->json($component->load(['asset', 'originAsset']), 201);
    }

    private function assetAccessible(int $assetId, $user): bool
    {
        return $this->clientScope()->applyInventoryAssetScope(
            \App\Models\InvAsset::query()->whereKey($assetId),
            $user
        )->exists();
    }

    public function update(Request $request, InvComponent $inv_component)
    {
        $this->authorizeComponentAccess($inv_component);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'marca' => 'nullable|string|max:255',
            'modelo' => 'nullable|string|max:255',
            'serie' => 'nullable|string|max:255',
            'capacidad' => 'nullable|string|max:255',
            'observacion' => 'nullable|string|max:2000',
            'status' => 'nullable|string|max:255',
        ]);

        $inv_component->update($data);

        return response()->json($inv_component->load(['asset', 'originAsset']));
    }

    public function destroy(InvComponent $inv_component)
    {
        $this->authorizeComponentAccess($inv_component);
        $inv_component->delete();

        return response()->noContent();
    }

    /** El componente hereda client_id del activo si se crea ya asignado; si no, del propio usuario. */
    private function resolveClientId(?int $assetId, $user): ?int
    {
        if ($assetId) {
            return \App\Models\InvAsset::where('id', $assetId)->value('client_id');
        }

        return $this->clientScope()->resolveUserClientId($user);
    }
}
