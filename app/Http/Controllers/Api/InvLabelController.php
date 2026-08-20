<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ManagesOperatorCatalog;
use App\Http\Controllers\Controller;
use App\Models\InvLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvLabelController extends Controller
{
    use ManagesOperatorCatalog;

    protected function catalogModelClass(): string
    {
        return InvLabel::class;
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
            'name' => $scope->uniqueNameRule($user, 'inv_labels'),
            'is_active' => 'boolean',
        ]);

        $label = InvLabel::create(array_merge([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ], $scope->operatorAttributesForCreate($user)));

        return response()->json($label, 201);
    }

    public function update(Request $request, InvLabel $inv_label)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_label);
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_labels', $inv_label->id),
            'is_active' => 'boolean',
        ]);

        $inv_label->update([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? $inv_label->is_active,
        ]);

        return response()->json($inv_label);
    }

    public function destroy(InvLabel $inv_label)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_label);
        $inv_label->delete();

        return response()->noContent();
    }
}
