<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvIntegrationRequest extends FormRequest
{
    /**
     * Autorización real vía perm:inventory.manage_config en la ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->route('provider')) {
            'entra_id', 'intune' => [
                'tenant_id' => 'required|string|max:255',
                'client_id' => 'required|string|max:255',
                // Vacío/omitido = conservar el secreto ya guardado -- se resuelve en el controlador.
                'client_secret' => 'nullable|string|max:1000',
            ],
            'ad' => [
                'host' => 'required|string|max:255',
                'port' => 'nullable|integer|min:1|max:65535',
                'base_dn' => 'nullable|string|max:500',
                'bind_dn' => 'required|string|max:500',
                'bind_password' => 'nullable|string|max:1000',
                'use_ssl' => 'boolean',
            ],
            default => [],
        };
    }
}
