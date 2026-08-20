<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvComponentAccess;
use App\Http\Controllers\Controller;
use App\Models\InvComponent;
use App\Models\InvComponentMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Acciones sobre un componente individual (fase 4): asignar/retirar/dar de
 * baja. El despiece masivo desde un activo vive aparte, en
 * InvAssetDisassemblyController (genera movimientos EXTRACCION, no expuesto
 * aquí). Sin update/destroy -- bitácora inmutable, mismo criterio que
 * InvMovementController (fase 3).
 */
class InvComponentMovementController extends Controller
{
    use AuthorizesInvComponentAccess;

    public function index(InvComponent $inv_component)
    {
        $this->authorizeComponentAccess($inv_component);

        return $inv_component->movements()
            ->with(['asset', 'originAsset', 'admin'])
            ->orderByDesc('date')
            ->get();
    }

    public function assign(Request $request, InvComponent $inv_component)
    {
        $this->authorizeComponentAccess($inv_component);
        $user = Auth::user();

        if ($inv_component->asset_id) {
            return response()->json(['message' => 'Este componente ya está asignado a un activo -- retíralo primero'], 422);
        }

        $data = $request->validate([
            'asset_id' => 'required|exists:inv_assets,id',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (! $this->clientScope()->applyInventoryAssetScope(
            \App\Models\InvAsset::query()->whereKey($data['asset_id']),
            $user
        )->exists()) {
            return response()->json(['message' => 'El activo seleccionado no pertenece a tu cliente'], 422);
        }

        $movement = DB::transaction(function () use ($inv_component, $data, $user) {
            $movement = InvComponentMovement::create([
                'component_id' => $inv_component->id,
                'asset_id' => $data['asset_id'],
                'origin_asset_id' => null,
                'type' => 'ASIGNAR',
                'admin_id' => $user->id,
                'notes' => $data['notes'] ?? null,
                'client_id' => $inv_component->client_id,
                'date' => now(),
            ]);

            $inv_component->update(['asset_id' => $data['asset_id']]);

            return $movement;
        });

        return response()->json($movement->load(['asset', 'originAsset', 'admin']), 201);
    }

    public function unassign(Request $request, InvComponent $inv_component)
    {
        $this->authorizeComponentAccess($inv_component);
        $user = Auth::user();

        if (! $inv_component->asset_id) {
            return response()->json(['message' => 'Este componente no está asignado a ningún activo'], 422);
        }

        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $movement = DB::transaction(function () use ($inv_component, $data, $user) {
            $movement = InvComponentMovement::create([
                'component_id' => $inv_component->id,
                'asset_id' => null,
                'origin_asset_id' => $inv_component->origin_asset_id ?? $inv_component->asset_id,
                'type' => 'RETIRAR',
                'admin_id' => $user->id,
                'notes' => $data['notes'] ?? null,
                'client_id' => $inv_component->client_id,
                'date' => now(),
            ]);

            $inv_component->update([
                'asset_id' => null,
                'origin_asset_id' => $inv_component->origin_asset_id ?? $inv_component->asset_id,
            ]);

            return $movement;
        });

        return response()->json($movement->load(['asset', 'originAsset', 'admin']), 201);
    }

    public function retire(Request $request, InvComponent $inv_component)
    {
        $this->authorizeComponentAccess($inv_component);
        $user = Auth::user();

        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $movement = DB::transaction(function () use ($inv_component, $data, $user) {
            $movement = InvComponentMovement::create([
                'component_id' => $inv_component->id,
                'asset_id' => null,
                'origin_asset_id' => $inv_component->origin_asset_id ?? $inv_component->asset_id,
                'type' => 'BAJA',
                'admin_id' => $user->id,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'client_id' => $inv_component->client_id,
                'date' => now(),
            ]);

            $inv_component->update([
                'asset_id' => null,
                'origin_asset_id' => $inv_component->origin_asset_id ?? $inv_component->asset_id,
                'status' => 'BAJA',
            ]);

            return $movement;
        });

        return response()->json($movement->load(['asset', 'originAsset', 'admin']), 201);
    }
}
