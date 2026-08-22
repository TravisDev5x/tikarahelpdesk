<?php

namespace App\Http\Controllers\Inertia;

use App\Http\Controllers\Controller;
use App\Models\InvIntegration;
use App\Services\TenantClientResolver;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvIntegrationPageController extends Controller
{
    private const SECRET_FIELDS = [
        'entra_id' => ['client_secret'],
        'intune' => ['client_secret'],
        'ad' => ['bind_password'],
    ];

    public function __invoke(TenantClientResolver $resolver): Response
    {
        $clientId = $resolver->resolve(Auth::user());
        $rows = $clientId
            ? InvIntegration::where('client_id', $clientId)->get()->keyBy('provider')
            : collect();

        $integrations = collect(['entra_id', 'intune', 'ad'])->map(function (string $provider) use ($rows) {
            $row = $rows->get($provider);
            $config = $row?->config ?? [];
            $hasFields = [];
            foreach (self::SECRET_FIELDS[$provider] as $secretField) {
                $hasFields["has_{$secretField}"] = filled($config[$secretField] ?? null);
            }

            return [
                'provider' => $provider,
                'status' => $row?->status ?? 'not_configured',
                'status_message' => $row?->status_message,
                'last_tested_at' => $row?->last_tested_at?->toIso8601String(),
                ...$hasFields,
            ];
        })->values();

        return Inertia::render('Inventory/Config/Integrations', [
            'integrations' => $integrations,
        ]);
    }
}
