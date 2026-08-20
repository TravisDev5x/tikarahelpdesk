<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export del dashboard de alertas de Inventario (fase 7.2, port desde
 * HelpdeskECD2026 -- InventoryMonitorExportController). A diferencia del
 * original ("una alerta por descarga", porque su UI es un selector de
 * tipo), aquí un solo workbook con las 4 alertas de fase 7.1 como hojas
 * separadas + un resumen -- coherente con que Monitor.jsx ya las muestra
 * juntas como 4 tarjetas.
 */
class InvMonitorExport
{
    public function __construct(private array $alerts) {}

    public function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $this->buildSummarySheet($spreadsheet->getActiveSheet());
        $this->buildWarrantySheet($spreadsheet->createSheet());
        $this->buildUnassignedSheet($spreadsheet->createSheet());
        $this->buildTransfersSheet($spreadsheet->createSheet());
        $this->buildMaintenanceSheet($spreadsheet->createSheet());
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildSummarySheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Resumen');
        $this->writeHeadings($sheet, ['Alerta', 'Cantidad'], 1);

        $rows = [
            ['Garantías por vencer (15 días)', count($this->alerts['warrantyExpiring'])],
            ['Activos sin responsable', count($this->alerts['unassigned'])],
            ['Traslados repetidos (24h)', count($this->alerts['repeatedTransfers'])],
            ['Mantenimientos estancados (30 días)', count($this->alerts['staleMaintenances'])],
        ];
        foreach ($rows as $i => $row) {
            $sheet->setCellValue('A'.($i + 2), $row[0]);
            $sheet->setCellValue('B'.($i + 2), $row[1]);
        }
        $sheet->setCellValue('A7', 'Generado');
        $sheet->setCellValue('B7', now()->format('Y-m-d H:i:s'));
        foreach (['A', 'B'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function buildWarrantySheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Garantías por vencer');
        $this->writeHeadings($sheet, ['ID', 'Etiqueta interna', 'Nombre', 'Vence'], 1);

        $row = 2;
        foreach ($this->alerts['warrantyExpiring'] as $a) {
            $sheet->setCellValue('A'.$row, $a->id);
            $sheet->setCellValue('B'.$row, $a->internal_tag);
            $sheet->setCellValue('C'.$row, $a->name);
            $sheet->setCellValue('D'.$row, (string) $a->warranty_expiry);
            $row++;
        }
        $this->autosize($sheet, 4);
    }

    private function buildUnassignedSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Sin responsable');
        $this->writeHeadings($sheet, ['ID', 'Etiqueta interna', 'Nombre'], 1);

        $row = 2;
        foreach ($this->alerts['unassigned'] as $a) {
            $sheet->setCellValue('A'.$row, $a->id);
            $sheet->setCellValue('B'.$row, $a->internal_tag);
            $sheet->setCellValue('C'.$row, $a->name);
            $row++;
        }
        $this->autosize($sheet, 3);
    }

    private function buildTransfersSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Traslados repetidos');
        $this->writeHeadings($sheet, ['ID', 'Etiqueta interna', 'Nombre', 'Traslados (24h)'], 1);

        $row = 2;
        foreach ($this->alerts['repeatedTransfers'] as $a) {
            $sheet->setCellValue('A'.$row, $a['id']);
            $sheet->setCellValue('B'.$row, $a['internal_tag']);
            $sheet->setCellValue('C'.$row, $a['name']);
            $sheet->setCellValue('D'.$row, $a['transfer_count']);
            $row++;
        }
        $this->autosize($sheet, 4);
    }

    private function buildMaintenanceSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Mantenimientos estancados');
        $this->writeHeadings($sheet, ['ID mantenimiento', 'Etiqueta del activo', 'Activo', 'Título', 'Inicio'], 1);

        $row = 2;
        foreach ($this->alerts['staleMaintenances'] as $m) {
            $sheet->setCellValue('A'.$row, $m->id);
            $sheet->setCellValue('B'.$row, $m->asset?->internal_tag);
            $sheet->setCellValue('C'.$row, $m->asset?->name);
            $sheet->setCellValue('D'.$row, $m->title);
            $sheet->setCellValue('E'.$row, (string) $m->start_date);
            $row++;
        }
        $this->autosize($sheet, 5);
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

    private function autosize(Worksheet $sheet, int $colCount): void
    {
        foreach (range(1, $colCount) as $col) {
            $sheet->getColumnDimension($this->colLetter($col))->setAutoSize(true);
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
        (new Xlsx($this->buildSpreadsheet()))->save($path);
    }
}
