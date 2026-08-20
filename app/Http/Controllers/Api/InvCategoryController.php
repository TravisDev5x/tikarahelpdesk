<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ManagesOperatorCatalog;
use App\Http\Controllers\Controller;
use App\Models\InvCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvCategoryController extends Controller
{
    use ManagesOperatorCatalog;

    protected function catalogModelClass(): string
    {
        return InvCategory::class;
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
            'name' => $scope->uniqueNameRule($user, 'inv_categories'),
            'type' => 'nullable|string|in:HARDWARE,SOFTWARE,CONSUMIBLE',
            'prefix' => 'nullable|string|max:20',
            'require_specs' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $category = InvCategory::create(array_merge([
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
            'prefix' => $data['prefix'] ?? null,
            'require_specs' => $data['require_specs'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ], $scope->operatorAttributesForCreate($user)));

        return response()->json($category, 201);
    }

    public function update(Request $request, InvCategory $inv_category)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_category);
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_categories', $inv_category->id),
            'type' => 'nullable|string|in:HARDWARE,SOFTWARE,CONSUMIBLE',
            'prefix' => 'nullable|string|max:20',
            'require_specs' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $inv_category->update([
            'name' => $data['name'],
            'type' => $data['type'] ?? $inv_category->type,
            'prefix' => $data['prefix'] ?? $inv_category->prefix,
            'require_specs' => $data['require_specs'] ?? $inv_category->require_specs,
            'is_active' => $data['is_active'] ?? $inv_category->is_active,
        ]);

        return response()->json($inv_category);
    }

    public function destroy(InvCategory $inv_category)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_category);
        $inv_category->delete();

        return response()->noContent();
    }
}
