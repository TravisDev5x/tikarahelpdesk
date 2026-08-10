<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\UserInvitation;

class OnboardingRedirectService
{
    public function wasInvited(User $user): bool
    {
        if (! $user->email) {
            return false;
        }

        return UserInvitation::query()
            ->where('email', $user->email)
            ->where('status', 'accepted')
            ->exists();
    }

    /**
     * Ruta de onboarding requerida, o null si puede continuar al dashboard.
     * Única fuente de verdad de "a qué paso del wizard le toca ir" --
     * TenantOnboardingController se la pregunta en cada show()/store() en
     * vez de reimplementar la máquina de estados ahí.
     *
     * Fase 7 (2026-08-09): ya no ramifica por is_operator/operatorProfile --
     * ese wizard (y sus 2 pasos) se retiró. is_operator/OperatorProfile en
     * sí NO se tocaron -- esta rama solo dejó de existir porque la ruta a
     * la que apuntaba ('/onboarding/clients') ya no existe.
     *
     * Fase 7.3-7.5: extiende la máquina de estados más allá de
     * 'tenant_named'. 'modality_set' persistido entre requests SIEMPRE
     * implica mode msp/hybrid -- si el usuario elige 'internal',
     * storeModality() avanza a 'customers_skipped' en el mismo request
     * (7.5 no aplica), así que 'modality_set' nunca queda "colgado" para
     * un tenant interno.
     */
    public function redirectPath(User $user): ?string
    {
        if ($this->wasInvited($user)) {
            return null;
        }

        if ($user->onboarding_completed) {
            return null;
        }

        if ($user->client_id === null) {
            return '/onboarding';
        }

        $client = Client::find($user->client_id);
        if (! $client) {
            return '/onboarding';
        }

        return match ($client->onboarding_step) {
            'tenant_named' => '/onboarding/company',
            'company_data' => '/onboarding/modality',
            'modality_set' => '/onboarding/customers',
            // customers_added, customers_skipped, completed, o cualquier
            // estado más allá de lo construido hasta ahora -> ya no hay
            // paso del wizard pendiente en este sprint.
            default => null,
        };
    }

    public function shouldBypassRoute(string $path): bool
    {
        $path = trim($path, '/');

        $exact = [
            'onboarding',
            'logout',
            'login',
            'register',
            'register/accept',
            'verify-email',
            'landing',
            '',
            'manual',
            'check-auth',
        ];

        if (in_array($path, $exact, true)) {
            return true;
        }

        if (str_starts_with($path, 'api')) {
            return true;
        }

        if (str_starts_with($path, 'onboarding/')) {
            return true;
        }

        // Redirects 302 en routes/inertia_legacy.php (no bloquear por onboarding incompleto).
        foreach ($this->legacyRedirectPrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function legacyRedirectPrefixes(): array
    {
        return [
            'app',
            'inicio',
            'resolvev1',
            'resolbeb/resolvev1',
            'tickets',
            'mis-tickets',
            'ticket-states',
            'ticket-types',
            'ticket-estados',
            'ticket-tipos',
            'incidentes',
            'audit-command-center',
            'audit',
            'configuracion',
            'usuarios',
            'invitaciones',
            'prioridades',
            'invitation/accept',
            'invitations/accept',
        ];
    }
}
