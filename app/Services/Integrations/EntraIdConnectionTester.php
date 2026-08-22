<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;

class EntraIdConnectionTester extends MicrosoftGraphConnectionTester
{
    protected function graphCheck(string $token): ConnectionTestResult
    {
        $response = Http::withToken($token)->get('https://graph.microsoft.com/v1.0/organization');

        if (! $response->successful()) {
            return ConnectionTestResult::failure(
                'Token obtenido pero sin permiso para leer la organización en Graph (revisa los permisos de la app registrada).'
            );
        }

        $name = $response->json('value.0.displayName');

        return ConnectionTestResult::success(
            $name ? "Conectado a Entra ID -- {$name}." : 'Conectado a Entra ID.'
        );
    }
}
