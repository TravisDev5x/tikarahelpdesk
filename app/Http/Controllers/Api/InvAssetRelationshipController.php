<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvAssetRelationship;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Relaciones entre activos (auditoría de Inventario, fase 3.2 -- CMDB,
 * Sección I). No confundir con InvComponentController (fase 4): eso es
 * para partes NO independientes; esto es para activos independientes
 * vinculados entre sí (laptop+dock+monitor).
 */
class InvAssetRelationshipController extends Controller
{
    use AuthorizesInvAssetAccess;

    public const TYPES = ['component_of', 'network_of', 'other'];

    public function store(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);

        $data = $request->validate([
            'child_asset_id' => 'required|exists:inv_assets,id',
            'relationship_type' => 'required|string|in:'.implode(',', self::TYPES),
            'notes' => 'nullable|string|max:2000',
        ]);

        if ((int) $data['child_asset_id'] === (int) $inv_asset->id) {
            return response()->json(['message' => 'Un activo no puede relacionarse consigo mismo'], 422);
        }

        $child = InvAsset::findOrFail($data['child_asset_id']);
        if ((int) $child->client_id !== (int) $inv_asset->client_id) {
            return response()->json(['message' => 'El activo no pertenece a este tenant'], 422);
        }

        $alreadyLinked = InvAssetRelationship::query()
            ->where(function ($q) use ($inv_asset, $child) {
                $q->where('parent_asset_id', $inv_asset->id)->where('child_asset_id', $child->id);
            })
            ->orWhere(function ($q) use ($inv_asset, $child) {
                $q->where('parent_asset_id', $child->id)->where('child_asset_id', $inv_asset->id);
            })
            ->exists();
        if ($alreadyLinked) {
            return response()->json(['message' => 'Estos activos ya están relacionados'], 422);
        }

        $relationship = InvAssetRelationship::create([
            'parent_asset_id' => $inv_asset->id,
            'child_asset_id' => $child->id,
            'relationship_type' => $data['relationship_type'],
            'notes' => $data['notes'] ?? null,
            'client_id' => $inv_asset->client_id,
            'created_by' => Auth::id(),
        ]);

        return response()->json($relationship->load('childAsset:id,name,internal_tag'), 201);
    }

    public function destroy(InvAsset $inv_asset, InvAssetRelationship $relationship)
    {
        $this->authorizeAssetAccess($inv_asset);

        $participates = (int) $relationship->parent_asset_id === (int) $inv_asset->id
            || (int) $relationship->child_asset_id === (int) $inv_asset->id;
        if (! $participates) {
            return response()->json(['message' => 'Relación no válida'], 404);
        }

        $relationship->delete();

        return response()->noContent();
    }
}
