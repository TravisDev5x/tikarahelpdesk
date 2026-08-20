<?php

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $asset = $this->route('inv_asset');
        $clientId = $this->filled('site_id')
            ? Site::where('id', $this->input('site_id'))->value('client_id')
            : $asset->client_id;

        return [
            'uuid' => ['nullable', 'uuid', Rule::unique('inv_assets', 'uuid')->where('client_id', $clientId)->ignore($asset->id)],
            'internal_tag' => ['required', 'string', 'max:255', Rule::unique('inv_assets', 'internal_tag')->where('client_id', $clientId)->ignore($asset->id)],
            'serial' => ['nullable', 'string', 'max:255', Rule::unique('inv_assets', 'serial')->where('client_id', $clientId)->ignore($asset->id)],
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:inv_categories,id',
            'status_id' => 'required|exists:inv_statuses,id',
            'label_id' => 'nullable|exists:inv_labels,id',
            'condition' => 'nullable|string|in:NUEVO,BUENO,REGULAR,MALO,PARA_PIEZAS',
            'site_id' => 'required|exists:sites,id',
            'location_id' => 'nullable|exists:locations,id',
            'specs' => 'nullable|string|max:5000',
            'cost' => 'nullable|numeric|min:0',
            'purchase_date' => 'nullable|date',
            'warranty_expiry' => 'nullable|date',
            'supplier' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public function messages(): array
    {
        return [
            'internal_tag.required' => 'El número de inventario es obligatorio.',
            'internal_tag.unique' => 'Ya existe un activo con este número de inventario.',
            'serial.unique' => 'Ya existe un activo con este número de serie.',
            'site_id.required' => 'La sede es obligatoria.',
            'site_id.exists' => 'La sede no es válida.',
            'category_id.required' => 'La categoría es obligatoria.',
            'status_id.required' => 'El estatus es obligatorio.',
        ];
    }
}
