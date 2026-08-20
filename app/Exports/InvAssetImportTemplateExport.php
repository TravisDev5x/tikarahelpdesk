<?php

namespace App\Exports;

use App\Models\InvCategory;
use App\Models\InvLabel;
use App\Models\InvStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\OperatorCatalogScopeService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Plantilla descargable para el import masivo de activos (fase 6). Mismo
 * estilo que TicketAuditExport (Spreadsheet/Xlsx a mano, sin Laravel-Excel).
 * Los encabezados de la hoja "Activos" deben coincidir exactamente con
 * InvAssetImportService::COLUMNS (case-insensitive, sin acentos).
 */
class InvAssetImportTemplateExport
{
    private const HEADINGS = [
        'Etiqueta interna', 'Nombre', 'Categoría', 'Estatus', 'Etiqueta',
        'Condición', 'Serie', 'Sede', 'Ubicación', 'Costo',
        'Fecha de compra', 'Vencimiento de garantía', 'Proveedor',
        'Número de factura', 'Especificaciones', 'Notas',
    ];

    private const EXAMPLE_ROW = [
        'LAP-0001', 'Laptop Dell Latitude 5420', 'Laptops', 'Disponible', '',
        'BUENO', 'SN123456', 'Oficina principal', '', '15000',
        '2024-01-15', '2027-01-15', 'CompuMax', 'F-2024-001', '16GB RAM, 512GB SSD', '',
    ];

    public function __construct(private User $user, private OperatorCatalogScopeService $catalogScope) {}

    public function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $this->buildAssetsSheet($spreadsheet->getActiveSheet());
        $this->buildCatalogSheet($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildAssetsSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $sheet->setTitle('Activos');
        $colCount = count(self::HEADINGS);

        foreach (self::HEADINGS as $col => $heading) {
            $sheet->setCellValue($this->colLetter($col + 1).'1', $heading);
        }
        $sheet->getStyle('A1:'.$this->colLetter($colCount).'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$this->colLetter($colCount).'1')
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');

        foreach (self::EXAMPLE_ROW as $col => $value) {
            $sheet->setCellValue($this->colLetter($col + 1).'2', $value);
        }

        foreach (range(1, $colCount) as $col) {
            $sheet->getColumnDimension($this->colLetter($col))->setAutoSize(true);
        }
    }

    /** Hoja de referencia: nombres de catálogo que el import reconocerá. */
    private function buildCatalogSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $sheet->setTitle('Catálogos');

        $categories = $this->catalogScope->apply(InvCategory::query()->where('is_active', true), $this->user, 'inv_categories')->pluck('name');
        $statuses = $this->catalogScope->apply(InvStatus::query()->where('is_active', true), $this->user, 'inv_statuses')->pluck('name');
        $labels = $this->catalogScope->apply(InvLabel::query()->where('is_active', true), $this->user, 'inv_labels')->pluck('name');
        $sites = Site::where('is_active', true)->orderBy('name')->pluck('name');

        $columns = [
            'Categorías' => $categories,
            'Estatus' => $statuses,
            'Etiquetas' => $labels,
            'Sedes' => $sites,
        ];

        $col = 1;
        foreach ($columns as $heading => $values) {
            $letter = $this->colLetter($col);
            $sheet->setCellValue($letter.'1', $heading);
            $sheet->getStyle($letter.'1')->getFont()->setBold(true);
            $row = 2;
            foreach ($values as $value) {
                $sheet->setCellValue($letter.$row, $value);
                $row++;
            }
            $sheet->getColumnDimension($letter)->setAutoSize(true);
            $col++;
        }
    }

    private function colLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex > 0) {
            $colIndex--;
            $letter = chr(65 + ($colIndex % 26)).$letter;
            $colIndex = (int) floor($colIndex / 26);
        }

        return $letter ?: 'A';
    }

    public function exportToPath(string $path): void
    {
        $writer = new Xlsx($this->buildSpreadsheet());
        $writer->save($path);
    }
}
