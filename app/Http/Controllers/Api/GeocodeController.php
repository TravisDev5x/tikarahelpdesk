<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Maps\GoogleGeocodingService;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    public function __invoke(Request $request, GoogleGeocodingService $geocoding)
    {
        $data = $request->validate([
            'address' => 'required|string|max:500',
        ]);

        $result = $geocoding->geocode($data['address']);

        if (! $result) {
            return response()->json([
                'message' => 'No se pudo ubicar esa dirección en el mapa.',
            ], 422);
        }

        return response()->json($result);
    }
}
