<?php

namespace App\Services;

use App\Http\Requests\StoreInvAssetRequest;
use App\Models\Client;
use App\Models\InvAsset;
use App\Models\InvCategory;
use App\Models\InvLabel;
use App\Models\InvStatus;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import masivo de activos desde Excel/CSV (fase 6, port desde
 * HelpdeskECD2026 -- sin formato original que portar, ver plan). Síncrono,
 * sin cola ni tabla de historial a propósito (ver decisiones de diseño del
 * plan). Cada fila reutiliza StoreInvAssetRequest::rulesFor() para validar
 * exactamente igual que el alta manual (InvAssetController::store()).
 */
class InvAssetImportService
{
    private const MAX_ROWS = 1000;

    /** Encabezado normalizado (minúsculas, sin acentos) => campo. */
    public const COLUMNS = [
        'etiqueta interna' => 'internal_tag',
        'nombre' => 'name',
        'categoria' => 'category',
        'estatus' => 'status',
        'etiqueta' => 'label',
        'condicion' => 'condition',
        'serie' => 'serial',
        'sede' => 'site',
        'ubicacion' => 'location',
        'costo' => 'cost',
        'fecha de compra' => 'purchase_date',
        'vencimiento de garantia' => 'warranty_expiry',
        'proveedor' => 'supplier',
        'numero de factura' => 'invoice_number',
        'especificaciones' => 'specs',
        'notas' => 'notes',
    ];

    public function __construct(
        protected ClientScopeService $clientScope,
        protected OperatorCatalogScopeService $catalogScope,
    ) {}

    public function import(UploadedFile $file, User $user): array
    {
        $sheet = IOFactory::createReaderForFile($file->getRealPath())
            ->load($file->getRealPath())
            ->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return ['created' => 0, 'errors' => [['row' => 0, 'messages' => ['El archivo está vacío.']]]];
        }

        $headerMap = $this->matchHeaders(array_shift($rows));
        if ($headerMap === null) {
            return ['created' => 0, 'errors' => [['row' => 1, 'messages' => [
                'El archivo no coincide con la plantilla esperada. Descárgala de nuevo desde "Descargar plantilla".',
            ]]]];
        }

        if (count($rows) > self::MAX_ROWS) {
            return ['created' => 0, 'errors' => [['row' => 0, 'messages' => [
                'El archivo tiene más de '.self::MAX_ROWS.' filas. Divídelo en archivos más pequeños.',
            ]]]];
        }

        $categories = $this->catalogScope->apply(InvCategory::query()->where('is_active', true), $user, 'inv_categories')->get(['id', 'name']);
        $statuses = $this->catalogScope->apply(InvStatus::query()->where('is_active', true), $user, 'inv_statuses')->get(['id', 'name']);
        $labels = $this->catalogScope->apply(InvLabel::query()->where('is_active', true), $user, 'inv_labels')->get(['id', 'name']);

        $created = 0;
        $errors = [];
        $seenInFile = [];
        $quotaByClient = []; // client_id => ['used' => int, 'max' => ?int]
        $messagesTemplate = (new StoreInvAssetRequest())->messages();

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // fila 1 = encabezados
            $raw = $this->mapRow($row, $headerMap);
            if ($this->isBlankRow($raw)) {
                continue;
            }

            $messages = [];

            $categoryId = $this->matchByName($categories, $raw['category'] ?? null, 'Categoría', $messages);
            $statusId = $this->matchByName($statuses, $raw['status'] ?? null, 'Estatus', $messages);
            $labelId = empty($raw['label']) ? null : $this->matchByName($labels, $raw['label'], 'Etiqueta', $messages, false);
            $site = $this->resolveSite($raw['site'] ?? null, $messages);
            $locationId = $this->resolveLocation($raw['location'] ?? null, $site, $messages);

            if ($messages) {
                $errors[] = ['row' => $rowNumber, 'messages' => $messages];

                continue;
            }

            $clientId = $site->client_id;
            $data = [
                'internal_tag' => $raw['internal_tag'] ?: null,
                'serial' => $raw['serial'] ?: null,
                'name' => $raw['name'] ?: null,
                'category_id' => $categoryId,
                'status_id' => $statusId,
                'label_id' => $labelId,
                'condition' => empty($raw['condition']) ? null : Str::upper(str_replace(' ', '_', trim($raw['condition']))),
                'site_id' => $site->id,
                'location_id' => $locationId,
                'specs' => $raw['specs'] ?: null,
                'cost' => $raw['cost'] === '' || $raw['cost'] === null ? null : $raw['cost'],
                'purchase_date' => $raw['purchase_date'] ?: null,
                'warranty_expiry' => $raw['warranty_expiry'] ?: null,
                'supplier' => $raw['supplier'] ?: null,
                'invoice_number' => $raw['invoice_number'] ?: null,
                'notes' => $raw['notes'] ?: null,
            ];

            $validator = Validator::make($data, StoreInvAssetRequest::rulesFor($clientId), $messagesTemplate);
            if ($validator->fails()) {
                $errors[] = ['row' => $rowNumber, 'messages' => $validator->errors()->all()];

                continue;
            }

            $tagKey = $clientId.'|tag|'.Str::lower($data['internal_tag']);
            $serialKey = $data['serial'] ? $clientId.'|serial|'.Str::lower($data['serial']) : null;
            if (isset($seenInFile[$tagKey])) {
                $errors[] = ['row' => $rowNumber, 'messages' => ["La etiqueta interna '{$data['internal_tag']}' está repetida en el archivo (fila {$seenInFile[$tagKey]})."]];

                continue;
            }
            if ($serialKey && isset($seenInFile[$serialKey])) {
                $errors[] = ['row' => $rowNumber, 'messages' => ["El número de serie '{$data['serial']}' está repetido en el archivo (fila {$seenInFile[$serialKey]})."]];

                continue;
            }

            if (! $this->clientScope->assertSiteAccessible($user, (int) $site->id)) {
                $errors[] = ['row' => $rowNumber, 'messages' => ["La sede '{$raw['site']}' no pertenece a tu cliente."]];

                continue;
            }

            if (! isset($quotaByClient[$clientId])) {
                $quotaByClient[$clientId] = [
                    'used' => InvAsset::where('client_id', $clientId)->count(),
                    'max' => Client::find($clientId)?->plan?->max_assets,
                ];
            }
            $quota = $quotaByClient[$clientId];
            if ($quota['max'] !== null && $quota['used'] >= $quota['max']) {
                $errors[] = ['row' => $rowNumber, 'messages' => ["Se alcanzó el límite de activos del plan ({$quota['max']})."]];

                continue;
            }

            $data['client_id'] = $clientId;
            $data['uuid'] = (string) Str::uuid();
            $data['specs'] = $data['specs'] ? ['notes' => $data['specs']] : null;

            InvAsset::create($data);
            $created++;
            $quotaByClient[$clientId]['used']++;
            $seenInFile[$tagKey] = $rowNumber;
            if ($serialKey) {
                $seenInFile[$serialKey] = $rowNumber;
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    private function matchHeaders(array $headerRow): ?array
    {
        $expected = self::COLUMNS;
        $map = [];
        foreach ($headerRow as $idx => $cell) {
            $norm = $this->normalizeHeader((string) $cell);
            if (isset($expected[$norm])) {
                $map[$idx] = $expected[$norm];
                unset($expected[$norm]);
            }
        }

        return empty($expected) ? $map : null;
    }

    private function normalizeHeader(string $header): string
    {
        $header = trim(mb_strtolower($header));

        return strtr($header, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $idx => $field) {
            $value = $row[$idx] ?? null;
            $out[$field] = is_string($value) ? trim($value) : $value;
        }

        return $out;
    }

    private function isBlankRow(array $raw): bool
    {
        foreach (['internal_tag', 'name', 'category', 'status', 'site'] as $field) {
            if (! empty($raw[$field])) {
                return false;
            }
        }

        return true;
    }

    private function matchByName(Collection $collection, ?string $name, string $label, array &$messages, bool $required = true): ?int
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            if ($required) {
                $messages[] = "{$label} es obligatoria.";
            }

            return null;
        }

        $match = $collection->first(fn ($row) => Str::lower(trim($row->name)) === Str::lower($name));
        if (! $match) {
            $messages[] = "{$label} '{$name}' no existe.";

            return null;
        }

        return $match->id;
    }

    private function resolveSite(?string $name, array &$messages): ?Site
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            $messages[] = 'La sede es obligatoria.';

            return null;
        }

        $matches = Site::where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->get(['id', 'name', 'client_id']);

        if ($matches->isEmpty()) {
            $messages[] = "La sede '{$name}' no existe o no está activa.";

            return null;
        }
        if ($matches->count() > 1) {
            $messages[] = "Hay más de una sede llamada '{$name}' en el sistema; renómbrala para que sea única.";

            return null;
        }

        return $matches->first();
    }

    private function resolveLocation(?string $name, ?Site $site, array &$messages): ?int
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '' || ! $site) {
            return null;
        }

        $location = Location::where('site_id', $site->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if (! $location) {
            $messages[] = "La ubicación '{$name}' no existe en la sede '{$site->name}'.";

            return null;
        }

        return $location->id;
    }
}
