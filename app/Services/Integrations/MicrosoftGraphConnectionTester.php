<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entra ID e Intune comparten el mismo flujo OAuth2 client-credentials
 * contra Microsoft Graph -- lo único que cambia entre uno y otro es qué
 * endpoint de Graph se llama para confirmar que el token realmente tiene
 * el permiso esperado (ver graphCheck() en cada subclase).
 */
abstract class MicrosoftGraphConnectionTester
{
    abstract protected function graphCheck(string $token): ConnectionTestResult;

    public function test(array $config): ConnectionTestResult
    {
        $tenantId = trim((string) ($config['tenant_id'] ?? ''));
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            return ConnectionTestResult::failure('Faltan tenant_id, client_id o client_secret.');
        }

        try {
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                ]
            );
        } catch (Throwable $e) {
            return ConnectionTestResult::failure('No se pudo contactar a Microsoft: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? $response->body();

            return ConnectionTestResult::failure('Microsoft rechazó las credenciales: '.Str::limit((string) $error, 200));
        }

        $token = $response->json('access_token');
        if (! $token) {
            return ConnectionTestResult::failure('Microsoft no devolvió un token de acceso.');
        }

        return $this->graphCheck($token);
    }
}
