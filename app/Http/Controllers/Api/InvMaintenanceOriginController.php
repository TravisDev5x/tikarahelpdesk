<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ManagesOperatorCatalog;
use App\Http\Controllers\Controller;
use App\Models\InvMaintenanceOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvMaintenanceOriginController extends Controller
{
    use ManagesOperatorCatalog;

    protected function catalogModelClass(): string
    {
        return InvMaintenanceOrigin::class;
    }

    public function index()
    {
        return $this->scopedCatalogQuery()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_maintenance_origins'),
            'code' => $scope->uniqueCodeRule($user, 'inv_maintenance_origins'),
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        $origin = InvMaintenanceOrigin::create(array_merge([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ], $scope->operatorAttributesForCreate($user)));

        return response()->json($origin, 201);
    }

    public function update(Request $request, InvMaintenanceOrigin $inv_maintenance_origin)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_maintenance_origin);
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_maintenance_origins', $inv_maintenance_origin->id),
            'code' => $scope->uniqueCodeRule($user, 'inv_maintenance_origins', $inv_maintenance_origin->id),
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        $inv_maintenance_origin->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? $inv_maintenance_origin->code,
            'sort_order' => $data['sort_order'] ?? $inv_maintenance_origin->sort_order,
            'is_active' => $data['is_active'] ?? $inv_maintenance_origin->is_active,
        ]);

        return response()->json($inv_maintenance_origin);
    }

    public function destroy(InvMaintenanceOrigin $inv_maintenance_origin)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_maintenance_origin);
        $inv_maintenance_origin->delete();

        return response()->noContent();
    }
}
