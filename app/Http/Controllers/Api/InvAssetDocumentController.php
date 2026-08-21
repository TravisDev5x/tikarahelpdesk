<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Documentos y evidencias de un activo (auditoría de Inventario, fase 2.2)
 * -- facturas, actas de entrega/devolución, evidencia de baja, además de
 * las fotos que ya existían (InvAssetImageController). Calca ese
 * controlador ya corregido en fase 1: disco 'local' desde el alta, nunca
 * 'public', y show() sirve el archivo autenticado con el mismo chequeo de
 * tenant que store()/destroy().
 */
class InvAssetDocumentController extends Controller
{
    use AuthorizesInvAssetAccess;

    public const TYPES = ['invoice', 'warranty', 'acta_entrega', 'acta_devolucion', 'disposal_evidence', 'other'];

    /**
     * Un documento por request (a diferencia de las fotos, que sí se suben
     * en lote) -- cada documento necesita su propio `type`, así que la UI
     * natural es "elige tipo, elige archivo, sube" una vez por documento.
     */
    public function store(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);

        $data = $request->validate([
            'file' => 'required|file|max:10240',
            'type' => 'required|string|in:'.implode(',', self::TYPES),
        ]);

        $file = $data['file'];
        $path = $file->store("inv-assets/{$inv_asset->id}/documents", ['disk' => 'local']);
        $document = InvDocument::create([
            'documentable_type' => InvAsset::class,
            'documentable_id' => $inv_asset->id,
            'client_id' => $inv_asset->client_id,
            'type' => $data['type'],
            'path' => $path,
            'disk' => 'local',
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        return response()->json($document, 201);
    }

    public function show(InvAsset $inv_asset, InvDocument $document)
    {
        $this->authorizeAssetAccess($inv_asset);

        if ($document->documentable_type !== InvAsset::class || (int) $document->documentable_id !== (int) $inv_asset->id) {
            abort(404, 'Documento no válido');
        }

        $disk = Storage::disk($document->disk ?: 'local');
        abort_unless($disk->exists($document->path), 404, 'Archivo no encontrado');

        return $disk->download($document->path, $document->original_name);
    }

    public function destroy(InvAsset $inv_asset, InvDocument $document)
    {
        $this->authorizeAssetAccess($inv_asset);

        if ($document->documentable_type !== InvAsset::class || (int) $document->documentable_id !== (int) $inv_asset->id) {
            return response()->json(['message' => 'Documento no válido'], 404);
        }

        if ($document->path) {
            Storage::disk($document->disk ?: 'local')->delete($document->path);
        }
        $document->delete();

        return response()->noContent();
    }
}
