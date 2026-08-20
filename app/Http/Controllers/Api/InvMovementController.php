<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvMovement;
use App\Models\InvStatus;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Acciones del ciclo de vida de un activo (fase 3, port desde
 * HelpdeskECD2026): asignar/devolver/trasladar/dar de baja. Sin
 * update/destroy a propósito -- inv_movements es una bitácora inmutable,
 * igual que en Helpdesk (ver plan).
 */
class InvMovementController extends Controller
{
    use AuthorizesInvAssetAccess;

    public function index(InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);

        return $inv_asset->movements()
            ->with(['user', 'previousUser', 'admin'])
            ->orderByDesc('date')
            ->get();
    }

    public function checkout(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();

        $data = $request->validate([
            'user_id' => [
                'required',
                'exists:users,id',
            ],
            'notes' => 'nullable|string|max:2000',
        ]);

        $target = \App\Models\User::find($data['user_id']);
        if (! $target || (int) $target->client_id !== (int) $inv_asset->client_id) {
            return response()->json(['message' => 'El usuario seleccionado no pertenece a tu cliente'], 422);
        }

        $inv_asset->loadMissing('status');
        if ($inv_asset->status && ! $inv_asset->status->assignable) {
            return response()->json(['message' => 'El estatus actual del activo no permite asignarlo'], 422);
        }

        $movement = DB::transaction(function () use ($inv_asset, $data, $user) {
            $movement = InvMovement::create([
                'asset_id' => $inv_asset->id,
                'type' => 'CHECKOUT',
                'user_id' => $data['user_id'],
                'previous_user_id' => $inv_asset->current_user_id,
                'admin_id' => $user->id,
                'notes' => $data['notes'] ?? null,
                'client_id' => $inv_asset->client_id,
                'date' => now(),
            ]);

            $inv_asset->update(['current_user_id' => $data['user_id']]);

            return $movement;
        });

        return response()->json($movement->load(['user', 'previousUser', 'admin']), 201);
    }

    public function checkin(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();

        if (! $inv_asset->current_user_id) {
            return response()->json(['message' => 'Este activo no tiene un responsable asignado'], 422);
        }

        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $movement = DB::transaction(function () use ($inv_asset, $data, $user) {
            $movement = InvMovement::create([
                'asset_id' => $inv_asset->id,
                'type' => 'CHECKIN',
                'user_id' => $inv_asset->current_user_id,
                'previous_user_id' => null,
                'admin_id' => $user->id,
                'notes' => $data['notes'] ?? null,
                'client_id' => $inv_asset->client_id,
                'date' => now(),
            ]);

            $inv_asset->update(['current_user_id' => null]);

            return $movement;
        });

        return response()->json($movement->load(['user', 'previousUser', 'admin']), 201);
    }

    public function transfer(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'location_id' => 'nullable|exists:locations,id',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (! $this->clientScope()->assertSiteAccessible($user, (int) $data['site_id'])) {
            return response()->json(['message' => 'La sede seleccionada no pertenece a tu cliente'], 422);
        }

        $fromSiteName = optional(Site::find($inv_asset->site_id))->name;
        $toSiteName = optional(Site::find($data['site_id']))->name;

        $movement = DB::transaction(function () use ($inv_asset, $data, $user, $fromSiteName, $toSiteName) {
            $movement = InvMovement::create([
                'asset_id' => $inv_asset->id,
                'type' => 'TRASLADO',
                'admin_id' => $user->id,
                'notes' => $data['notes'] ?? null,
                'metadata' => [
                    'from_site_id' => $inv_asset->site_id,
                    'from_site_name' => $fromSiteName,
                    'to_site_id' => $data['site_id'],
                    'to_site_name' => $toSiteName,
                ],
                'client_id' => $inv_asset->client_id,
                'date' => now(),
            ]);

            $inv_asset->update([
                'site_id' => $data['site_id'],
                'location_id' => $data['location_id'] ?? null,
            ]);

            return $movement;
        });

        return response()->json($movement->load(['user', 'previousUser', 'admin']), 201);
    }

    public function retire(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);
        $user = Auth::user();

        $data = $request->validate([
            'status_id' => 'required|exists:inv_statuses,id',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $status = InvStatus::find($data['status_id']);
        if ($status && $status->assignable) {
            return response()->json(['message' => 'Elige un estatus marcado como no asignable para dar de baja'], 422);
        }

        $movement = DB::transaction(function () use ($inv_asset, $data, $user) {
            $movement = InvMovement::create([
                'asset_id' => $inv_asset->id,
                'type' => 'BAJA',
                'user_id' => $inv_asset->current_user_id,
                'previous_user_id' => null,
                'admin_id' => $user->id,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'client_id' => $inv_asset->client_id,
                'date' => now(),
            ]);

            $inv_asset->update([
                'status_id' => $data['status_id'],
                'current_user_id' => null,
            ]);

            return $movement;
        });

        return response()->json($movement->load(['user', 'previousUser', 'admin']), 201);
    }
}
