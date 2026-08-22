<?php

namespace App\Http\Controllers\Inertia;

use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\User;
use App\Services\ClientScopeService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vista de asignación consolidada ("¿quién tiene qué?") -- pendiente del
 * roadmap original de fase 7, retomado aparte de la auditoría ITAM/CMDB.
 * Un solo query scoped + agregación en PHP, mismo criterio que
 * InvMonitorPageController::reports()->topAssignees (que solo muestra el
 * top 5 dentro de una gráfica); aquí se lista el roster completo.
 */
class InvAssetAssignmentPageController extends Controller
{
    public function __construct(protected ClientScopeService $clientScope) {}

    public function index(): Response
    {
        $user = Auth::user();

        $assets = $this->clientScope->applyInventoryAssetScope(InvAsset::query(), $user)
            ->whereNotNull('current_user_id')
            ->with(['currentUser:id,first_name,paternal_last_name,maternal_last_name', 'category:id,name'])
            ->get();

        $roster = $assets->groupBy('current_user_id')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'user_id' => $first->current_user_id,
                    'user_name' => $this->userLabel($first->currentUser),
                    'asset_count' => $group->count(),
                    'total_value' => round((float) $group->sum('cost'), 2),
                    'assets' => $group->map(fn (InvAsset $a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'internal_tag' => $a->internal_tag,
                        'category' => $a->category?->name,
                    ])->values(),
                ];
            })
            ->sortByDesc('asset_count')
            ->values();

        return Inertia::render('Inventory/Assignments', ['roster' => $roster]);
    }

    private function userLabel(?User $user): string
    {
        if (! $user) {
            return '—';
        }

        return trim(implode(' ', array_filter([$user->first_name, $user->paternal_last_name, $user->maternal_last_name])));
    }
}
