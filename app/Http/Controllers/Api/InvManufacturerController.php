<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ManagesOperatorCatalog;
use App\Http\Controllers\Controller;
use App\Models\InvManufacturer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvManufacturerController extends Controller
{
    use ManagesOperatorCatalog;

    protected function catalogModelClass(): string
    {
        return InvManufacturer::class;
    }

    public function index()
    {
        return $this->scopedCatalogQuery()->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_manufacturers'),
            'is_active' => 'boolean',
        ]);

        $manufacturer = InvManufacturer::create(array_merge([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ], $scope->operatorAttributesForCreate($user)));

        return response()->json($manufacturer, 201);
    }

    public function update(Request $request, InvManufacturer $inv_manufacturer)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_manufacturer);
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_manufacturers', $inv_manufacturer->id),
            'is_active' => 'boolean',
        ]);

        $inv_manufacturer->update([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? $inv_manufacturer->is_active,
        ]);

        return response()->json($inv_manufacturer);
    }

    public function destroy(InvManufacturer $inv_manufacturer)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_manufacturer);
        $inv_manufacturer->delete();

        return response()->noContent();
    }
}
