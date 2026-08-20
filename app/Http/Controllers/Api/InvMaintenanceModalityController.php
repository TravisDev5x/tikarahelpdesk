<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ManagesOperatorCatalog;
use App\Http\Controllers\Controller;
use App\Models\InvMaintenanceModality;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvMaintenanceModalityController extends Controller
{
    use ManagesOperatorCatalog;

    protected function catalogModelClass(): string
    {
        return InvMaintenanceModality::class;
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
            'name' => $scope->uniqueNameRule($user, 'inv_maintenance_modalities'),
            'code' => $scope->uniqueCodeRule($user, 'inv_maintenance_modalities'),
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        $modality = InvMaintenanceModality::create(array_merge([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ], $scope->operatorAttributesForCreate($user)));

        return response()->json($modality, 201);
    }

    public function update(Request $request, InvMaintenanceModality $inv_maintenance_modality)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_maintenance_modality);
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_maintenance_modalities', $inv_maintenance_modality->id),
            'code' => $scope->uniqueCodeRule($user, 'inv_maintenance_modalities', $inv_maintenance_modality->id),
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        $inv_maintenance_modality->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? $inv_maintenance_modality->code,
            'sort_order' => $data['sort_order'] ?? $inv_maintenance_modality->sort_order,
            'is_active' => $data['is_active'] ?? $inv_maintenance_modality->is_active,
        ]);

        return response()->json($inv_maintenance_modality);
    }

    public function destroy(InvMaintenanceModality $inv_maintenance_modality)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_maintenance_modality);
        $inv_maintenance_modality->delete();

        return response()->noContent();
    }
}
