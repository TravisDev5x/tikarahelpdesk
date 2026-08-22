<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvIntegrationRequest;
use App\Models\InvIntegration;
use App\Services\Integrations\AdConnectionTester;
use App\Services\Integrations\EntraIdConnectionTester;
use App\Services\Integrations\IntuneConnectionTester;
use App\Services\TenantClientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class InvIntegrationController extends Controller
{
    /** Campos considerados "secretos" por proveedor -- nunca se devuelven, solo se reporta si ya existen (has_*). */
    private const SECRET_FIELDS = [
        'entra_id' => ['client_secret'],
        'intune' => ['client_secret'],
        'ad' => ['bind_password'],
    ];

    public const PROVIDERS = ['entra_id', 'intune', 'ad'];

    public function index(TenantClientResolver $resolver): JsonResponse
    {
        $clientId = $this->resolveClientOrAbort($resolver);

        $rows = InvIntegration::where('client_id', $clientId)->get()->keyBy('provider');

        return response()->json(
            collect(self::PROVIDERS)->map(fn ($provider) => $this->present($provider, $rows->get($provider)))->values()
        );
    }

    public function store(StoreInvIntegrationRequest $request, TenantClientResolver $resolver, string $provider): JsonResponse
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            abort(404);
        }

        $clientId = $this->resolveClientOrAbort($resolver);
        $data = $request->validated();

        $row = InvIntegration::firstOrNew(['client_id' => $clientId, 'provider' => $provider]);
        $existingConfig = $row->exists ? ($row->config ?? []) : [];

        // Auditoría de bugs críticos (2026-08-22): antes solo se protegían
        // los SECRET_FIELDS -- cualquier otro campo (use_ssl, port, base_dn,
        // etc.) que el cliente no mandara en el payload se perdía sin aviso
        // en cada re-guardado, porque $data reemplazaba a $existingConfig
        // por completo. Ahora se parte de lo ya guardado y solo se
        // sobreescribe lo que realmente vino en el request.
        $data = array_merge($existingConfig, $data);

        // Campos secretos vacíos/omitidos = conservar el valor ya guardado
        // (con el merge de arriba esto ya casi nunca se dispara si el
        // frontend manda el string vacío explícito, pero se deja como
        // defensa adicional).
        foreach (self::SECRET_FIELDS[$provider] ?? [] as $secretField) {
            if (! filled($data[$secretField] ?? null)) {
                if (array_key_exists($secretField, $existingConfig)) {
                    $data[$secretField] = $existingConfig[$secretField];
                } else {
                    unset($data[$secretField]);
                }
            }
        }

        $result = $this->tester($provider)->test($data);

        $row->fill([
            'config' => $data,
            'status' => $result->ok ? 'connected' : 'error',
            'status_message' => $result->message,
            'last_tested_at' => now(),
            'created_by' => $row->exists ? $row->created_by : Auth::id(),
        ]);
        $row->save();

        return response()->json($this->present($provider, $row));
    }

    public function test(TenantClientResolver $resolver, string $provider): JsonResponse
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            abort(404);
        }

        $clientId = $this->resolveClientOrAbort($resolver);
        $row = InvIntegration::where('client_id', $clientId)->where('provider', $provider)->first();

        if (! $row) {
            return response()->json(['message' => 'Esta integración todavía no está configurada.'], 422);
        }

        $result = $this->tester($provider)->test($row->config ?? []);

        $row->update([
            'status' => $result->ok ? 'connected' : 'error',
            'status_message' => $result->message,
            'last_tested_at' => now(),
        ]);

        return response()->json($this->present($provider, $row));
    }

    public function destroy(TenantClientResolver $resolver, string $provider): JsonResponse
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            abort(404);
        }

        $clientId = $this->resolveClientOrAbort($resolver);
        InvIntegration::where('client_id', $clientId)->where('provider', $provider)->delete();

        return response()->json($this->present($provider, null));
    }

    private function tester(string $provider): AdConnectionTester|EntraIdConnectionTester|IntuneConnectionTester
    {
        return match ($provider) {
            'entra_id' => App::make(EntraIdConnectionTester::class),
            'intune' => App::make(IntuneConnectionTester::class),
            'ad' => App::make(AdConnectionTester::class),
        };
    }

    private function resolveClientOrAbort(TenantClientResolver $resolver): int
    {
        $clientId = $resolver->resolve(Auth::user());
        abort_if($clientId === null, 404, 'Tu cuenta no tiene una empresa asociada.');

        return $clientId;
    }

    /**
     * Auditoría de bugs críticos (2026-08-22): antes solo devolvía los
     * has_<secreto> booleanos -- el formulario nunca tenía forma de
     * prellenarse con los valores no-secretos ya guardados (host, tenant_id,
     * use_ssl, etc.), así que cada re-guardado los volvía a mandar en blanco
     * y los borraba en silencio. Ahora los campos no-secretos van directo en
     * la respuesta (mismas keys que field.key en integrationProviders.js);
     * los secretos siguen sin exponerse jamás, solo el booleano has_*.
     *
     * @return array<string, mixed>
     */
    private function present(string $provider, ?InvIntegration $row): array
    {
        $config = $row?->config ?? [];
        $secretFields = self::SECRET_FIELDS[$provider] ?? [];

        $hasFields = [];
        foreach ($secretFields as $secretField) {
            $hasFields["has_{$secretField}"] = filled($config[$secretField] ?? null);
        }

        $nonSecretValues = collect($config)->except($secretFields)->all();

        return [
            'provider' => $provider,
            'status' => $row?->status ?? 'not_configured',
            'status_message' => $row?->status_message,
            'last_tested_at' => $row?->last_tested_at?->toIso8601String(),
            ...$nonSecretValues,
            ...$hasFields,
        ];
    }
}
