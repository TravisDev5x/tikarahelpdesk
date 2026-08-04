<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationAcceptanceService;
use App\Services\TenantContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class MicrosoftAuthController extends Controller
{
    public function redirect(Request $request)
    {
        if (! $this->microsoftConfigured()) {
            abort(503, 'Inicio de sesión con Microsoft no configurado.');
        }

        $intent = $request->query('intent', 'login');
        $token = $request->query('token');

        $request->session()->put('microsoft_oauth_intent', $intent);
        if ($intent === 'invitation' && is_string($token) && $token !== '') {
            $request->session()->put('microsoft_oauth_invitation_token', $token);
        }

        return Socialite::driver('azure')
            ->scopes(['openid', 'profile', 'email', 'User.Read'])
            ->redirect();
    }

    public function callback(
        Request $request,
        TenantContextService $tenantContext,
        InvitationAcceptanceService $acceptance
    ) {
        if (! $this->microsoftConfigured()) {
            return redirect()->route('login')->with('error', 'Microsoft no está configurado.');
        }

        try {
            $microsoftUser = Socialite::driver('azure')->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'No se pudo completar el inicio de sesión con Microsoft.');
        }

        $email = strtolower(trim((string) ($microsoftUser->user['mail'] ?? $microsoftUser->getEmail())));
        if ($email === '') {
            return redirect()->route('login')->with('error', 'Microsoft no proporcionó un correo válido.');
        }

        $intent = (string) $request->session()->pull('microsoft_oauth_intent', 'login');
        $invitationToken = $request->session()->pull('microsoft_oauth_invitation_token');

        if ($intent === 'invitation' && is_string($invitationToken) && $invitationToken !== '') {
            return $this->handleInvitation($request, $acceptance, $tenantContext, $microsoftUser, $email, $invitationToken);
        }

        return $this->handleLogin($request, $tenantContext, $microsoftUser, $email);
    }

    protected function handleLogin(
        Request $request,
        TenantContextService $tenantContext,
        $microsoftUser,
        string $email
    ) {
        $user = User::where('microsoft_id', $microsoftUser->getId())->first()
            ?? User::where('email', $email)->first();

        if (! $user) {
            return redirect()->route('login')->with(
                'error',
                'No hay cuenta registrada con este correo. Acepta una invitación o contacta al administrador.'
            );
        }

        if ($user->is_blacklisted || $user->status === 'blocked') {
            return redirect()->route('login')->with('error', 'Tu cuenta está bloqueada.');
        }

        if (in_array($user->status, ['pending_email'], true)) {
            return redirect()->route('login')->with('error', 'Verifica tu correo para activar la cuenta.');
        }

        if (! $user->microsoft_id) {
            $user->forceFill(['microsoft_id' => $microsoftUser->getId()])->save();
        }

        $tenantContext->resolve($request);
        if (! $tenantContext->userCanAccessCurrentPortal($user)) {
            Log::channel('single')->warning('Microsoft login rechazado: portal incorrecto', [
                'user_id' => $user->id,
                'host' => $request->getHost(),
            ]);

            return redirect()->route('login')->with(
                'error',
                'No tienes acceso a este portal. Inicia sesión en la URL de tu organización.'
            );
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    protected function handleInvitation(
        Request $request,
        InvitationAcceptanceService $acceptance,
        TenantContextService $tenantContext,
        $microsoftUser,
        string $email,
        string $token
    ) {
        $invitation = UserInvitation::query()
            ->where('token', $token)
            ->where('status', UserInvitation::STATUS_PENDING)
            ->first();

        if (! $invitation || $invitation->isExpired()) {
            return redirect()->route('invitation.accept', ['token' => $token])
                ->with('error', 'Esta invitación no es válida o ha expirado.');
        }

        if (strtolower($invitation->email) !== $email) {
            return redirect()->route('invitation.accept', ['token' => $token])
                ->with('error', 'El correo de Microsoft debe coincidir con el de la invitación ('.$invitation->email.').');
        }

        $tenantContext->resolve($request);

        [$firstName, $paternalLastName, $maternalLastName] = $this->splitMicrosoftName($microsoftUser);

        try {
            $user = $acceptance->accept($invitation, [
                'first_name' => $firstName,
                'paternal_last_name' => $paternalLastName,
                'maternal_last_name' => $maternalLastName,
                'microsoft_id' => $microsoftUser->getId(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'No se pudo aceptar la invitación.';

            return redirect()->route('invitation.accept', ['token' => $token])->with('error', $message);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    protected function splitMicrosoftName($microsoftUser): array
    {
        $given = trim((string) ($microsoftUser->user['givenName'] ?? ''));
        $family = trim((string) ($microsoftUser->user['surname'] ?? ''));

        if ($given !== '' && $family !== '') {
            $parts = preg_split('/\s+/', $family, 2) ?: [$family];

            return [$given, $parts[0], $parts[1] ?? null];
        }

        $full = trim((string) ($microsoftUser->getName() ?: 'Usuario'));
        $chunks = preg_split('/\s+/', $full, 3) ?: [$full];

        return [
            $chunks[0] ?? 'Usuario',
            $chunks[1] ?? '.',
            $chunks[2] ?? null,
        ];
    }

    protected function microsoftConfigured(): bool
    {
        return (bool) config('services.azure.client_id') && (bool) config('services.azure.client_secret');
    }
}
