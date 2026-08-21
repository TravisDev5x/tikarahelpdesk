<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesInvAssetAccess;
use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvAssetImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InvAssetImageController extends Controller
{
    use AuthorizesInvAssetAccess;

    public function store(Request $request, InvAsset $inv_asset)
    {
        $this->authorizeAssetAccess($inv_asset);

        $data = $request->validate([
            'images' => 'required|array',
            'images.*' => 'image|max:5120',
        ]);

        $saved = [];
        foreach ($request->file('images', []) as $file) {
            // Auditoría de Inventario (fase 1, crítico): antes se guardaba
            // en disco 'public' (storage/app/public, symlinkeado y servido
            // directo por el webserver) -- cualquiera con la URL veía la
            // foto sin sesión ni pertenecer al tenant, sin pasar por
            // ningún middleware de Laravel. 'local' (storage/app/private)
            // no tiene symlink público; solo show() de abajo, autenticado
            // y con el mismo chequeo de tenant que store()/destroy(), sirve
            // el archivo.
            $path = $file->store("inv-assets/{$inv_asset->id}", ['disk' => 'local']);
            $saved[] = InvAssetImage::create([
                'inv_asset_id' => $inv_asset->id,
                'path' => $path,
                'disk' => 'local',
            ]);
        }

        return response()->json($saved, 201);
    }

    /**
     * Sirve el archivo autenticado -- disk por fila (image->disk), así que
     * también sirve las imágenes ya subidas antes de este fix bajo 'public'
     * sin necesidad de moverlas físicamente.
     */
    public function show(InvAsset $inv_asset, InvAssetImage $image)
    {
        $this->authorizeAssetAccess($inv_asset);

        if ((int) $image->inv_asset_id !== (int) $inv_asset->id) {
            abort(404, 'Imagen no válida');
        }

        $disk = Storage::disk($image->disk ?: 'public');
        abort_unless($disk->exists($image->path), 404, 'Archivo no encontrado');

        return $disk->response($image->path);
    }

    public function destroy(InvAsset $inv_asset, InvAssetImage $image)
    {
        $this->authorizeAssetAccess($inv_asset);

        if ((int) $image->inv_asset_id !== (int) $inv_asset->id) {
            return response()->json(['message' => 'Imagen no válida'], 404);
        }

        if ($image->path) {
            Storage::disk($image->disk ?: 'public')->delete($image->path);
        }
        $image->delete();

        return response()->noContent();
    }
}
