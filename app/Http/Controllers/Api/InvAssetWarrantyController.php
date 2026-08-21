<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvWarranty;
use Illuminate\Http\Request;

/**
 * Garantías de un activo (auditoría de Inventario, fase 2.3) -- opcional,
 * no reemplaza inv_assets.warranty_expiry (que sigue alimentando la alerta
 * de garantías por vencer tal cual). Sin update a propósito: para
 * corregir una garantía se borra y se agrega de nuevo, mismo criterio de
 * simplicidad que componentes.
 */
class InvAssetWarrantyController extends Controller
{
    use AuthorizesInvAssetAccess;

    public function store(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);

        $data = $request->validate([
            'provider' => 'required|string|max:255',
            'warranty_number' => 'nullable|string|max:255',
            'coverage' => 'nullable|string|max:2000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'required|date',
        ]);

        $warranty = InvWarranty::create([
            ...$data,
            'asset_id' => $inv_asset->id,
            'client_id' => $inv_asset->client_id,
        ]);

        return response()->json($warranty, 201);
    }

    public function destroy(InvAsset $inv_asset, InvWarranty $warranty)
    {
        $this->authorizeAssetAccess($inv_asset);

        if ((int) $warranty->asset_id !== (int) $inv_asset->id) {
            return response()->json(['message' => 'Garantía no válida'], 404);
        }

        $warranty->delete();

        return response()->noContent();
    }
}
