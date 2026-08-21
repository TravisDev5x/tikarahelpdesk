<?php

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvAssetRequest extends FormRequest
{
    /**
     * Autorización real vía perm:inventory.manage_assets en la ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // client_id se resuelve del site_id (mismo criterio que
        // ClientScopeService::syncClientIdFromSite) para poder validar
        // unicidad de uuid/internal_tag/serial por tenant, no global.
        $clientId = Site::where('id', $this->input('site_id'))->value('client_id');

        return self::rulesFor($clientId);
    }

    /**
     * Extraído de rules() (fase 6) para que el import masivo pueda validar
     * cada fila del Excel con exactamente las mismas reglas que el alta
     * manual, dado el client_id ya resuelto de esa fila.
     */
    public static function rulesFor(?int $clientId): array
    {
        return [
            'uuid' => ['nullable', 'uuid', Rule::unique('inv_assets', 'uuid')->where('client_id', $clientId)],
            'internal_tag' => ['required', 'string', 'max:255', Rule::unique('inv_assets', 'internal_tag')->where('client_id', $clientId)],
            'serial' => ['nullable', 'string', 'max:255', Rule::unique('inv_assets', 'serial')->where('client_id', $clientId)],
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:inv_categories,id',
            'manufacturer_id' => 'nullable|exists:inv_manufacturers,id',
            'model' => 'nullable|string|max:255',
            'status_id' => 'required|exists:inv_statuses,id',
            'label_id' => 'nullable|exists:inv_labels,id',
            'condition' => 'nullable|string|in:NUEVO,BUENO,REGULAR,MALO,PARA_PIEZAS',
            'site_id' => 'required|exists:sites,id',
            'location_id' => 'nullable|exists:locations,id',
            'specs' => 'nullable|array',
            'specs.*.key' => 'required_with:specs|string|max:60',
            'specs.*.value' => 'nullable|string|max:1000',
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
