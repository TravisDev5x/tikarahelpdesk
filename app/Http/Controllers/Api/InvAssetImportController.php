<?php

namespace App\Http\Controllers\Api;

use App\Exports\InvAssetImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Services\InvAssetImportService;
use App\Services\OperatorCatalogScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Import masivo de activos desde Excel/CSV (fase 6, port desde
 * HelpdeskECD2026). Toda la lógica de parseo/validación vive en
 * InvAssetImportService -- este controlador solo sube el archivo y
 * genera la plantilla descargable.
 */
class InvAssetImportController extends Controller
{
    public function store(Request $request, InvAssetImportService $importer)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $result = $importer->import($request->file('file'), Auth::user());

        return response()->json($result, 201);
    }

    public function template(OperatorCatalogScopeService $catalogScope)
    {
        $export = new InvAssetImportTemplateExport(Auth::user(), $catalogScope);
        $filename = 'plantilla_import_activos.xlsx';
        $tempName = 'inv_asset_import_template_'.substr(uniqid('', true), -8).'.xlsx';
        $path = storage_path('app'.DIRECTORY_SEPARATOR.$tempName);
        $export->exportToPath($path);

        if (ob_get_length() > 0) {
            ob_end_clean();
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
