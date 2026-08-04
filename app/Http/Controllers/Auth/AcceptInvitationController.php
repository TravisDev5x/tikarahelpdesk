<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use App\Services\InvitationAcceptanceService;
use App\Services\TenantContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function __construct(
        protected InvitationAcceptanceService $acceptance,
        protected TenantContextService $tenantContext
    ) {}

    public function show(Request $request): Response
    {
        $token = $request->query('token');

        if (! is_string($token) || trim($token) === '') {
            return $this->errorPage();
        }

        $invitation = UserInvitation::with(['role', 'client'])
            ->where('token', $token)
            ->where('status', UserInvitation::STATUS_PENDING)
            ->first();

        if (! $invitation || $invitation->isExpired()) {
            if ($invitation && $invitation->isExpired()) {
                $invitation->update(['status' => UserInvitation::STATUS_EXPIRED]);
            }

            return $this->errorPage();
        }

        $this->tenantContext->resolve($request);
        try {
            app(\App\Services\InvitationTenancyService::class)->assertInvitationMatchesPortal($invitation);
        } catch (ValidationException) {
            return $this->errorPage('Esta invitación no corresponde a este portal.');
        }

        $googleEnabled = (bool) config('services.google.client_id');
        $microsoftEnabled = (bool) config('services.azure.client_id');

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $invitation->token,
            'email' => $invitation->email,
            'role_name' => $invitation->role?->name,
            'client_name' => $invitation->client?->name,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'assigns_role_on_accept' => $invitation->role_id !== null,
            'google_enabled' => $googleEnabled,
            'google_url' => $googleEnabled
                ? route('auth.google.redirect', ['intent' => 'invitation', 'token' => $invitation->token])
                : null,
            'microsoft_enabled' => $microsoftEnabled,
            'microsoft_url' => $microsoftEnabled
                ? route('auth.microsoft.redirect', ['intent' => 'invitation', 'token' => $invitation->token])
                : null,
            'error' => $request->session()->get('error'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|exists:user_invitations,token',
            'first_name' => 'required|string|max:255',
            'paternal_last_name' => 'required|string|max:255',
            'maternal_last_name' => 'nullable|string|max:255',
            'password' => [
                'required',
                'confirmed',
                'min:12',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
        ]);

        $this->tenantContext->resolve($request);

        $invitation = UserInvitation::with('role')
            ->where('token', $validated['token'])
            ->firstOrFail();

        $user = $this->acceptance->accept($invitation, [
            'first_name' => $validated['first_name'],
            'paternal_last_name' => $validated['paternal_last_name'],
            'maternal_last_name' => $validated['maternal_last_name'] ?? null,
            'password' => $validated['password'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    protected function errorPage(?string $message = null): Response
    {
        return Inertia::render('Auth/AcceptInvitation', [
            'token' => null,
            'email' => null,
            'role_name' => null,
            'client_name' => null,
            'expires_at' => null,
            'assigns_role_on_accept' => false,
            'google_enabled' => false,
            'google_url' => null,
            'microsoft_enabled' => false,
            'microsoft_url' => null,
            'error' => $message ?? 'Esta invitación no es válida o ha expirado',
        ]);
    }
}
