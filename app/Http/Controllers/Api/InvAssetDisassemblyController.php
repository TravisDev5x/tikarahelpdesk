<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvComponentMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Despiece (fase 4, port desde HelpdeskECD2026): extraer uno o varios
 * componentes ACTUALES de un activo de golpe. Cada uno genera un
 * InvComponentMovement tipo EXTRACCION -- mismo efecto sobre el componente
 * que "retirar" (InvComponentMovementController::unassign), pero semántica
 * distinta (salió porque se desarmó el activo completo, no una decisión
 * aislada sobre el componente).
 */
class InvAssetDisassemblyController extends Controller
{
    use AuthorizesInvAssetAccess;

    public function store(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();

        $data = $request->validate([
            'component_ids' => 'required|array|min:1',
            'component_ids.*' => 'integer',
            'notes' => 'nullable|string|max:2000',
        ]);

        $components = $inv_asset->components()->whereIn('id', $data['component_ids'])->get();

        if ($components->count() !== count($data['component_ids'])) {
            return response()->json(['message' => 'Alguno de los componentes seleccionados no pertenece a este activo'], 422);
        }

        $movements = DB::transaction(function () use ($inv_asset, $components, $data, $user) {
            $created = [];
            foreach ($components as $component) {
                $created[] = InvComponentMovement::create([
                    'component_id' => $component->id,
                    'asset_id' => null,
                    'origin_asset_id' => $component->origin_asset_id ?? $inv_asset->id,
                    'type' => 'EXTRACCION',
                    'admin_id' => $user->id,
                    'notes' => $data['notes'] ?? null,
                    'client_id' => $inv_asset->client_id,
                    'date' => now(),
                ]);

                $component->update([
                    'asset_id' => null,
                    'origin_asset_id' => $component->origin_asset_id ?? $inv_asset->id,
                ]);
            }

            return $created;
        });

        return response()->json($movements, 201);
    }
}
