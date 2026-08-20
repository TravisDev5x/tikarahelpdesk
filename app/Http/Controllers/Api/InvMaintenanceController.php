<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Concerns\AuthorizesInvMaintenanceAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvMaintenance;
use App\Models\InvMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimientos de activos (fase 5, port desde HelpdeskECD2026). A
 * diferencia del ciclo de vida (fase 3, InvMovementController), SÍ tiene
 * update/destroy -- inv_maintenances no es una bitácora inmutable, es un
 * recurso editable (se abre, se documenta, se cierra). Abrir uno genera un
 * InvMovement tipo MAINTENANCE para que aparezca en el historial unificado
 * del activo; cerrar (update con end_date) no duplica un movimiento nuevo.
 */
class InvMaintenanceController extends Controller
{
    use AuthorizesInvAssetAccess;
    use AuthorizesInvMaintenanceAccess {
        AuthorizesInvMaintenanceAccess::clientScope insteadof AuthorizesInvAssetAccess;
    }

    public function index(Request $request)
    {
        $query = $this->clientScope()->applyInventoryMaintenanceScope(
            InvMaintenance::query()->with(['asset', 'origin', 'modality', 'loggedBy']),
            Auth::user()
        );

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }
        if ($request->input('open') === '1') {
            $query->open();
        }
        if ($request->input('closed') === '1') {
            $query->closed();
        }

        return $query->orderByDesc('start_date')->paginate(25);
    }

    public function show(InvMaintenance $inv_maintenance)
    {
        $this->authorizeMaintenanceAccess($inv_maintenance);

        return $inv_maintenance->load(['asset', 'origin', 'modality', 'loggedBy']);
    }

    public function store(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();

        $data = $request->validate([
            'origin_id' => 'nullable|exists:inv_maintenance_origins,id',
            'modality_id' => 'nullable|exists:inv_maintenance_modalities,id',
            'title' => 'required|string|max:255',
            'diagnosis' => 'nullable|string|max:2000',
            'solution' => 'nullable|string|max:2000',
            'cost' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $maintenance = DB::transaction(function () use ($inv_asset, $data, $user) {
            $maintenance = InvMaintenance::create([
                ...$data,
                'asset_id' => $inv_asset->id,
                'logged_by' => $user->id,
                'client_id' => $inv_asset->client_id,
            ]);

            InvMovement::create([
                'asset_id' => $inv_asset->id,
                'type' => 'MAINTENANCE',
                'admin_id' => $user->id,
                'notes' => $data['title'],
                'metadata' => ['maintenance_id' => $maintenance->id],
                'client_id' => $inv_asset->client_id,
                'date' => now(),
            ]);

            return $maintenance;
        });

        return response()->json($maintenance->load(['asset', 'origin', 'modality', 'loggedBy']), 201);
    }

    public function update(Request $request, InvMaintenance $inv_maintenance)
    {
        $this->authorizeMaintenanceAccess($inv_maintenance);

        $data = $request->validate([
            'origin_id' => 'nullable|exists:inv_maintenance_origins,id',
            'modality_id' => 'nullable|exists:inv_maintenance_modalities,id',
            'title' => 'required|string|max:255',
            'diagnosis' => 'nullable|string|max:2000',
            'solution' => 'nullable|string|max:2000',
            'cost' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $inv_maintenance->update($data);

        return response()->json($inv_maintenance->load(['asset', 'origin', 'modality', 'loggedBy']));
    }

    public function destroy(InvMaintenance $inv_maintenance)
    {
        $this->authorizeMaintenanceAccess($inv_maintenance);
        $inv_maintenance->delete();

        return response()->noContent();
    }
}
