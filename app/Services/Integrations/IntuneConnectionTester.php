<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;

class IntuneConnectionTester extends MicrosoftGraphConnectionTester
{
    protected function graphCheck(string $token): ConnectionTestResult
    {
        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices', ['$top' => 1]);

        if (! $response->successful()) {
            return ConnectionTestResult::failure(
                'Token obtenido pero sin permiso de Intune (DeviceManagementManagedDevices.Read.All) en la app registrada.'
            );
        }

        return ConnectionTestResult::success('Conectado a Intune (Microsoft Graph).');
    }
}
