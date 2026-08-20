<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export general de activos (fase 7.2, port desde HelpdeskECD2026 --
 * InventoryExportController). Mismo estilo que TicketAuditExport
 * (Spreadsheet/Xlsx a mano, sin Laravel-Excel). 4 hojas: Resumen, Todos
 * los activos, Por categoría, Por estatus -- adaptado al esquema real de
 * Tikara (sin "Empresa"/"Owner"/"Medio", conceptos que no existen aquí).
 */
class InvAssetExport
{
    private const ASSET_HEADINGS = [
        'ID', 'Etiqueta interna', 'Nombre', 'Serie', 'Categoría', 'Estatus', 'Etiqueta',
        'Condición', 'Sede', 'Ubicación', 'Responsable', 'Costo', 'Fecha de compra',
        'Vencimiento de garantía', 'Proveedor', 'Número de factura', 'Alta',
    ];

    public function __construct(private Collection $assets, private array $filters = []) {}

    public function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $this->buildSummarySheet($spreadsheet->getActiveSheet());
        $this->buildAssetsSheet($spreadsheet->createSheet());
        $this->buildByCategorySheet($spreadsheet->createSheet());
        $this->buildByStatusSheet($spreadsheet->createSheet());
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildSummarySheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Resumen');
        $assigned = $this->assets->whereNotNull('current_user_id')->count();

        $rows = [
            ['Generado', now()->format('Y-m-d H:i:s')],
            ['Total activos', $this->assets->count()],
            ['Asignados', $assigned],
            ['Sin asignar', $this->assets->count() - $assigned],
            ['Costo total', number_format((float) $this->assets->sum('cost'), 2)],
            ['Categoría', $this->filters['category'] ?? 'Todas'],
            ['Estatus', $this->filters['status'] ?? 'Todos'],
            ['Sede', $this->filters['site'] ?? 'Todas'],
            ['Responsable', $this->filters['assigned'] ?? 'Todos'],
            ['Búsqueda', $this->filters['search'] ?? '—'],
        ];

        $this->writeHeadings($sheet, ['Campo', 'Valor'], 1);
        foreach ($rows as $i => $row) {
            $sheet->setCellValue('A'.($i + 2), $row[0]);
            $sheet->setCellValue('B'.($i + 2), $row[1]);
        }
        foreach (['A', 'B'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function buildAssetsSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Todos los activos');
        $this->writeHeadings($sheet, self::ASSET_HEADINGS, 1);

        $row = 2;
        foreach ($this->assets as $asset) {
            $sheet->setCellValue('A'.$row, $asset->id);
            $sheet->setCellValue('B'.$row, $asset->internal_tag);
            $sheet->setCellValue('C'.$row, $asset->name);
            $sheet->setCellValue('D'.$row, $asset->serial);
            $sheet->setCellValue('E'.$row, $asset->category?->name);
            $sheet->setCellValue('F'.$row, $asset->status?->name);
            $sheet->setCellValue('G'.$row, $asset->label?->name);
            $sheet->setCellValue('H'.$row, $asset->condition);
            $sheet->setCellValue('I'.$row, $asset->site?->name);
            $sheet->setCellValue('J'.$row, $asset->location?->name);
            $sheet->setCellValue('K'.$row, $this->userLabel($asset->currentUser));
            $sheet->setCellValue('L'.$row, $asset->cost);
            $sheet->setCellValue('M'.$row, (string) $asset->purchase_date);
            $sheet->setCellValue('N'.$row, (string) $asset->warranty_expiry);
            $sheet->setCellValue('O'.$row, $asset->supplier);
            $sheet->setCellValue('P'.$row, $asset->invoice_number);
            $sheet->setCellValue('Q'.$row, $asset->created_at?->format('Y-m-d H:i:s'));
            $row++;
        }
        foreach (range(1, count(self::ASSET_HEADINGS)) as $col) {
            $sheet->getColumnDimension($this->colLetter($col))->setAutoSize(true);
        }
    }

    private function buildByCategorySheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Por categoría');
        $this->writeHeadings($sheet, ['Categoría', 'Cantidad', 'Costo total', 'Asignados', 'Sin asignar'], 1);

        $row = 2;
        foreach ($this->assets->groupBy(fn ($a) => $a->category?->name ?? 'Sin categoría') as $name => $group) {
            $assigned = $group->whereNotNull('current_user_id')->count();
            $sheet->setCellValue('A'.$row, $name);
            $sheet->setCellValue('B'.$row, $group->count());
            $sheet->setCellValue('C'.$row, number_format((float) $group->sum('cost'), 2));
            $sheet->setCellValue('D'.$row, $assigned);
            $sheet->setCellValue('E'.$row, $group->count() - $assigned);
            $row++;
        }
        foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function buildByStatusSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Por estatus');
        $this->writeHeadings($sheet, ['Estatus', 'Cantidad', 'Costo total', '% del total'], 1);

        $total = max($this->assets->count(), 1);
        $row = 2;
        foreach ($this->assets->groupBy(fn ($a) => $a->status?->name ?? 'Sin estatus') as $name => $group) {
            $sheet->setCellValue('A'.$row, $name);
            $sheet->setCellValue('B'.$row, $group->count());
            $sheet->setCellValue('C'.$row, number_format((float) $group->sum('cost'), 2));
            $sheet->setCellValue('D'.$row, number_format($group->count() * 100 / $total, 1).'%');
            $row++;
        }
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function userLabel(?\App\Models\User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return trim(implode(' ', array_filter([$user->first_name, $user->paternal_last_name, $user->maternal_last_name])));
    }

    private function writeHeadings(Worksheet $sheet, array $headings, int $row): void
    {
        $colCount = count($headings);
        foreach ($headings as $col => $heading) {
            $sheet->setCellValue($this->colLetter($col + 1).$row, $heading);
        }
        $range = 'A'.$row.':'.$this->colLetter($colCount).$row;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
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
        (new Xlsx($this->buildSpreadsheet()))->save($path);
    }
}
