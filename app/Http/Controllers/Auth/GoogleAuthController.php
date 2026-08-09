<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Api\ProfileSecurityController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationAcceptanceService;
use App\Services\TenantContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        if (! $this->googleConfigured()) {
            abort(503, 'Inicio de sesión con Google no configurado.');
        }

        $intent = $request->query('intent', 'login');
        $token = $request->query('token');

        $request->session()->put('google_oauth_intent', $intent);
        if ($intent === 'invitation' && is_string($token) && $token !== '') {
            $request->session()->put('google_oauth_invitation_token', $token);
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    /**
     * Igual que redirect(), pero para vincular Google a la cuenta YA
     * autenticada desde Mi perfil (requiere middleware auth, no guest --
     * por eso es una ruta separada de /auth/google/redirect).
     */
    public function linkRedirect(Request $request)
    {
        if (! $this->googleConfigured()) {
            abort(503, 'Google no está configurado.');
        }

        $request->session()->put('google_oauth_intent', 'link');
        $request->session()->put('google_oauth_link_user_id', $request->user()->id);

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback(
        Request $request,
        TenantContextService $tenantContext,
        InvitationAcceptanceService $acceptance
    ) {
        if (! $this->googleConfigured()) {
            return redirect()->route('login')->with('error', 'Google no está configurado.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'No se pudo completar el inicio de sesión con Google.');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));
        if ($email === '') {
            return redirect()->route('login')->with('error', 'Google no proporcionó un correo válido.');
        }

        $intent = (string) $request->session()->pull('google_oauth_intent', 'login');
        $invitationToken = $request->session()->pull('google_oauth_invitation_token');
        $linkUserId = $request->session()->pull('google_oauth_link_user_id');

        if ($intent === 'link') {
            return $this->handleLink($request, $googleUser, $linkUserId);
        }

        if ($intent === 'invitation' && is_string($invitationToken) && $invitationToken !== '') {
            return $this->handleInvitation($request, $acceptance, $tenantContext, $googleUser, $email, $invitationToken);
        }

        return $this->handleLogin($request, $tenantContext, $googleUser, $email);
    }

    /**
     * Vincula Google a la cuenta que inició el flujo (guardada en sesión, no
     * "el usuario autenticado ahora mismo"): el callback es público
     * (Google no reenvía cookies de forma confiable en todos los navegadores
     * tras el redirect cross-site), así que la identidad viene de la sesión
     * del servidor, no de Auth::user().
     */
    protected function handleLink(Request $request, $googleUser, $linkUserId)
    {
        if (! $linkUserId) {
            return redirect()->route('profile.index')->with('error', 'La sesión para vincular Google expiró. Intenta de nuevo.');
        }

        $user = User::find($linkUserId);
        if (! $user) {
            return redirect()->route('login');
        }

        $claimedBy = User::where('google_id', $googleUser->getId())->first();
        if ($claimedBy && $claimedBy->id !== $user->id) {
            return redirect()->route('profile.index')->with('error', 'Esta cuenta de Google ya está vinculada a otro usuario.');
        }

        $user->forceFill(['google_id' => $googleUser->getId()])->save();
        ProfileSecurityController::logOauthLink($user, 'google', viaLogin: false, ip: $request->ip());

        return redirect()->route('profile.index')->with('success', 'Cuenta de Google vinculada.');
    }

    protected function handleLogin(
        Request $request,
        TenantContextService $tenantContext,
        $googleUser,
        string $email
    ) {
        $byGoogleId = User::where('google_id', $googleUser->getId())->first();
        $user = $byGoogleId ?? User::where('email', $email)->first();

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

        // Vínculo automático por coincidencia de correo (primera vez que este
        // usuario entra con Google): Google siempre manda email_verified en
        // /userinfo -- exigirlo evita que alguien con una cuenta de Google
        // reciente/no verificada tome el acceso de un correo que no le
        // pertenece. Si ya venía vinculado por google_id, es un login normal,
        // no un vínculo nuevo, así que no aplica este chequeo.
        if (! $byGoogleId) {
            $emailVerified = (bool) ($googleUser->user['email_verified'] ?? $googleUser->user['verified_email'] ?? false);
            if (! $emailVerified) {
                Log::channel('single')->warning('Google login: correo no verificado, se rechaza auto-vínculo', [
                    'email' => $email,
                    'target_user_id' => $user->id,
                ]);

                return redirect()->route('login')->with(
                    'error',
                    'Tu cuenta de Google no tiene el correo verificado. Inicia sesión con tu contraseña o contacta al administrador.'
                );
            }

            $user->forceFill(['google_id' => $googleUser->getId()])->save();
            ProfileSecurityController::logOauthLink($user, 'google', viaLogin: true, ip: $request->ip());
        }

        $tenantContext->resolve($request);
        if (! $tenantContext->userCanAccessCurrentPortal($user)) {
            Log::channel('single')->warning('Google login rechazado: portal incorrecto', [
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
        ProfileSecurityController::logLogin($user, 'google', $request->ip(), $request->userAgent());

        return redirect()->intended('/');
    }

    protected function handleInvitation(
        Request $request,
        InvitationAcceptanceService $acceptance,
        TenantContextService $tenantContext,
        $googleUser,
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
                ->with('error', 'El correo de Google debe coincidir con el de la invitación ('.$invitation->email.').');
        }

        $tenantContext->resolve($request);

        [$firstName, $paternalLastName, $maternalLastName] = $this->splitGoogleName($googleUser);

        try {
            $user = $acceptance->accept($invitation, [
                'first_name' => $firstName,
                'paternal_last_name' => $paternalLastName,
                'maternal_last_name' => $maternalLastName,
                'google_id' => $googleUser->getId(),
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
    protected function splitGoogleName($googleUser): array
    {
        $given = trim((string) ($googleUser->user['given_name'] ?? ''));
        $family = trim((string) ($googleUser->user['family_name'] ?? ''));

        if ($given !== '' && $family !== '') {
            $parts = preg_split('/\s+/', $family, 2) ?: [$family];

            return [$given, $parts[0], $parts[1] ?? null];
        }

        $full = trim((string) ($googleUser->getName() ?: 'Usuario'));
        $chunks = preg_split('/\s+/', $full, 3) ?: [$full];

        return [
            $chunks[0] ?? 'Usuario',
            $chunks[1] ?? '.',
            $chunks[2] ?? null,
        ];
    }

    protected function googleConfigured(): bool
    {
        return (bool) config('services.google.client_id') && (bool) config('services.google.client_secret');
    }
}
