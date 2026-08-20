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
            $path = $file->store("inv-assets/{$inv_asset->id}", ['disk' => 'public']);
            $saved[] = InvAssetImage::create([
                'inv_asset_id' => $inv_asset->id,
                'path' => $path,
                'disk' => 'public',
            ]);
        }

        return response()->json($saved, 201);
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
