<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvAssetRelationship;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        // Auditoría de bugs críticos (2026-08-22): el índice único de BD
        // solo cubre la tupla exacta (parent,child), no ambas direcciones
        // -- dos requests casi simultáneos en direcciones opuestas podían
        // pasar el exists() de abajo antes de que cualquiera de los dos
        // hiciera commit, duplicando el par en reversa. Un lock atómico por
        // par (sin importar el orden) cierra la ventana; funciona con
        // cualquier driver de cache configurado, no depende de una feature
        // específica de Postgres/MySQL.
        $pairKey = 'inv-asset-relationship:'.min($inv_asset->id, $child->id).'-'.max($inv_asset->id, $child->id);

        try {
            $relationship = Cache::lock($pairKey, 5)->block(3, function () use ($inv_asset, $child, $data) {
                return DB::transaction(function () use ($inv_asset, $child, $data) {
                    $alreadyLinked = InvAssetRelationship::query()
                        ->where(function ($q) use ($inv_asset, $child) {
                            $q->where('parent_asset_id', $inv_asset->id)->where('child_asset_id', $child->id);
                        })
                        ->orWhere(function ($q) use ($inv_asset, $child) {
                            $q->where('parent_asset_id', $child->id)->where('child_asset_id', $inv_asset->id);
                        })
                        ->exists();
                    if ($alreadyLinked) {
                        return null;
                    }

                    return InvAssetRelationship::create([
                        'parent_asset_id' => $inv_asset->id,
                        'child_asset_id' => $child->id,
                        'relationship_type' => $data['relationship_type'],
                        'notes' => $data['notes'] ?? null,
                        'client_id' => $inv_asset->client_id,
                        'created_by' => Auth::id(),
                    ]);
                });
            });
        } catch (UniqueConstraintViolationException) {
            // Choque exacto contra el índice único (doble clic/reintento) --
            // mismo mensaje que el chequeo "ya están relacionados" de arriba,
            // no un 500.
            return response()->json(['message' => 'Estos activos ya están relacionados'], 422);
        }

        if ($relationship === null) {
            return response()->json(['message' => 'Estos activos ya están relacionados'], 422);
        }

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
