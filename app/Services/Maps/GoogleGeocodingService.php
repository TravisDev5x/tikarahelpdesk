<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocodifica una dirección de texto a lat/lng vía Google Geocoding API.
 * Llamada SIEMPRE server-side -- la key (services.google_maps.server_key)
 * nunca se manda al navegador. Ver docs/GOOGLE_MAPS_SETUP.md para cómo
 * habilitar la API y restringir la key.
 */
class GoogleGeocodingService
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * @return array{lat: float, lng: float, formatted_address: string}|null
     */
    public function geocode(string $address): ?array
    {
        $key = config('services.google_maps.server_key');
        if (! $key) {
            Log::warning('GoogleGeocodingService: GOOGLE_MAPS_SERVER_KEY no configurada');

            return null;
        }

        $address = trim($address);
        if ($address === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->get(self::ENDPOINT, [
                'address' => $address,
                'key' => $key,
                'language' => 'es',
            ]);
        } catch (\Throwable $e) {
            Log::warning('GoogleGeocodingService: request falló', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('GoogleGeocodingService: HTTP no exitoso', ['status' => $response->status()]);

            return null;
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'OK' || empty($data['results'][0])) {
            Log::info('GoogleGeocodingService: sin resultados', [
                'address' => $address,
                'status' => $data['status'] ?? 'unknown',
            ]);

            return null;
        }

        $result = $data['results'][0];
        $location = $result['geometry']['location'] ?? null;

        if (! $location) {
            return null;
        }

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
            'formatted_address' => (string) ($result['formatted_address'] ?? $address),
        ];
    }
}
