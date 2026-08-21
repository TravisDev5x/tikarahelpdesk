<?php

namespace App\Support\Inventory;

/**
 * Auditoría de Inventario, fase 2.1: ficha técnica por tipo de categoría
 * (`inv_categories.type` -- HARDWARE/SOFTWARE/CONSUMIBLE, ya fijo desde
 * fase 1). Vive en PHP, no en base de datos -- una sola fuente para
 * backend (validación/filtrado en InvAssetController) y frontend
 * (InvAssetPageController expone all() como prop, AssetFormDialog.jsx la
 * consume tal cual), cero riesgo de que ambos lados se desincronicen.
 *
 * Editable por-categoría (en vez de por-tipo fijo) queda para una fase
 * posterior si se necesita -- el almacenamiento (inv_asset_specs, EAV) no
 * cambia cuando eso pase, solo de dónde sale este arreglo.
 */
class AssetSpecSchema
{
    public static function all(): array
    {
        return [
            'HARDWARE' => [
                ['key' => 'cpu', 'label' => 'Procesador (CPU)'],
                ['key' => 'ram', 'label' => 'Memoria RAM'],
                ['key' => 'storage_type', 'label' => 'Tipo de almacenamiento'],
                ['key' => 'storage_capacity', 'label' => 'Capacidad de almacenamiento'],
                ['key' => 'gpu', 'label' => 'Tarjeta de video (GPU)'],
                ['key' => 'mac_ethernet', 'label' => 'MAC Ethernet'],
                ['key' => 'mac_wifi', 'label' => 'MAC Wi-Fi'],
                ['key' => 'hostname', 'label' => 'Hostname'],
                ['key' => 'os', 'label' => 'Sistema operativo'],
                ['key' => 'os_version', 'label' => 'Versión de SO'],
                ['key' => 'bios_uefi', 'label' => 'Versión BIOS/UEFI'],
                ['key' => 'tpm', 'label' => 'TPM'],
                ['key' => 'encryption', 'label' => 'Cifrado (BitLocker u otro)'],
                ['key' => 'antivirus', 'label' => 'Antivirus / EDR'],
            ],
            'SOFTWARE' => [
                ['key' => 'version', 'label' => 'Versión'],
                ['key' => 'license_key', 'label' => 'Clave de licencia'],
                ['key' => 'license_type', 'label' => 'Tipo de licencia'],
                ['key' => 'seats', 'label' => 'Número de licencias'],
            ],
            'CONSUMIBLE' => [],
        ];
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function forType(?string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /** @return array<int, string> */
    public static function validKeysForType(?string $type): array
    {
        return array_column(self::forType($type), 'key');
    }
}
