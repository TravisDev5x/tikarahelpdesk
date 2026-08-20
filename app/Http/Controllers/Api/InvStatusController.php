<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ManagesOperatorCatalog;
use App\Http\Controllers\Controller;
use App\Models\InvStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvStatusController extends Controller
{
    use ManagesOperatorCatalog;

    protected function catalogModelClass(): string
    {
        return InvStatus::class;
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
            'name' => $scope->uniqueNameRule($user, 'inv_statuses'),
            'badge_class' => 'nullable|string|max:255',
            'assignable' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $status = InvStatus::create(array_merge([
            'name' => $data['name'],
            'badge_class' => $data['badge_class'] ?? null,
            'assignable' => $data['assignable'] ?? true,
            'is_active' => $data['is_active'] ?? true,
        ], $scope->operatorAttributesForCreate($user)));

        return response()->json($status, 201);
    }

    public function update(Request $request, InvStatus $inv_status)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_status);
        $scope = $this->catalogScope();
        $user = Auth::user();
        $data = $request->validate([
            'name' => $scope->uniqueNameRule($user, 'inv_statuses', $inv_status->id),
            'badge_class' => 'nullable|string|max:255',
            'assignable' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $inv_status->update([
            'name' => $data['name'],
            'badge_class' => $data['badge_class'] ?? $inv_status->badge_class,
            'assignable' => $data['assignable'] ?? $inv_status->assignable,
            'is_active' => $data['is_active'] ?? $inv_status->is_active,
        ]);

        return response()->json($inv_status);
    }

    public function destroy(InvStatus $inv_status)
    {
        $this->catalogScope()->authorizeRow(Auth::user(), $inv_status);
        $inv_status->delete();

        return response()->noContent();
    }
}
