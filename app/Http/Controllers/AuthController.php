<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Mail\VerifyEmail;
use App\Services\OnboardingRedirectService;
use App\Services\TenantContextService;

class AuthController extends Controller
{
    public function login(Request $request, TenantContextService $tenantContext)
    {
        $request->validate([
            'identifier' => ['required'],
            'password'   => ['required'],
        ]);

        $input = trim($request->input('identifier'));

        // Detectar si es email o numero de empleado
        $fieldType = filter_var($input, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'employee_number';

        $user = User::where($fieldType, $input)->first();
        $credentialsValid = $user && Hash::check($request->password, $user->password);

        $tenantContext->resolve($request);
        if ($user && ! $tenantContext->userCanAccessCurrentPortal($user)) {
            Log::channel('single')->warning('Login rechazado: portal incorrecto', [
                'user_id' => $user->id,
                'host'    => $request->getHost(),
            ]);
            $tenantContext->logBoundaryViolation($user, 'login_rejected', $tenantContext->current()->clientId, [
                'host' => $request->getHost(),
            ]);
            // Solo revelamos el motivo exacto cuando las credenciales son correctas.
            // Si el password es incorrecto, devolvemos el mismo 422 genérico para
            // evitar enumeración cross-tenant.
            if ($credentialsValid) {
                return response()->json([
                    'errors' => ['root' => 'No tienes acceso a este portal. Inicia sesión en la URL de tu organización.'],
                ], 403);
            }
            return response()->json(['errors' => ['root' => 'Credenciales inválidas']], 422);
        }

        if (! $credentialsValid) {
            Log::channel('single')->warning('Login fallido', [
                'identifier_type' => $fieldType,
                'ip'              => $request->ip(),
            ]);
            return response()->json(['errors' => ['root' => 'Credenciales inválidas']], 422);
        }

        if ($user->is_blacklisted) {
            return response()->json([
                'errors' => ['root' => 'Tu cuenta está vetada. Contacta al administrador']
            ], 403);
        }

        // Solo pending_email y blocked no pueden entrar. pending_admin puede entrar y ver app con mensaje de espera.
        if (in_array($user->status, ['pending_email', 'blocked'], true)) {
            $message = match ($user->status) {
                'pending_email' => 'Verifica tu correo para activar la cuenta',
                'blocked' => 'Tu cuenta está bloqueada',
                default => 'Tu cuenta no está activa',
            };
            return response()->json([
                'errors' => ['root' => $message]
            ], 403);
        }

        if ($user->status === 'active' && $user->email && is_null($user->email_verified_at)) {
            return response()->json([
                'errors' => ['root' => 'Verifica tu correo para activar la cuenta']
            ], 403);
        }

        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // Actualizar información de última conexión (compatible con monitor de sesiones)
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $authUser = Auth::user()->load([
            'roles:id,name,guard_name',
            'site:id,name,client_id',
            'site.client:id,name',
            'operatorProfile:id,user_id',
        ]);
        $authPayload = $authUser->toArray();
        $authPayload['client_id'] = $authUser->site?->client_id;
        $authPayload['client_name'] = $authUser->site?->client?->name;
        $permissions = $authUser->getAllPermissions()->pluck('name')->values();

        $onboarding = app(OnboardingRedirectService::class);

        return response()->json([
            'user' => $authPayload,
            'roles' => $authUser->roles->pluck('name'),
            'permissions' => $permissions,
            'onboarding_redirect' => $onboarding->redirectPath($authUser),
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'paternal_last_name' => ['required', 'string', 'max:255'],
            'maternal_last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'digits:10'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'password' => [
                'required',
                'string',
                'min:12',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
                'confirmed',
            ],
        ]);

        $sedeId = $validated['site_id'] ?? \App\Models\Site::where('code', 'REMOTO')->value('id');

        if ($request->filled('plan')) {
            session(['invited_plan_slug' => $request->input('plan')]);
        }

        $user = User::create([
            'employee_number' => null,
            'first_name' => $validated['first_name'],
            'paternal_last_name' => $validated['paternal_last_name'],
            'maternal_last_name' => $validated['maternal_last_name'] ?? null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => 'pending_email',
            'site_id' => $sedeId,
            'client_id' => null,
            'is_operator' => false,
            'onboarding_completed' => false,
        ]);

        $token = Str::uuid()->toString();
        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mailSent = false;
        $url = url("/verify-email?token={$token}");
        try {
            Mail::to($user->email)->send(new VerifyEmail($url));
            $mailSent = true;
        } catch (\Throwable $e) {
            Log::channel('single')->warning('Envío de correo de verificación fallido', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $mailSent
                ? 'Registro creado. Revisa tu correo para activar tu cuenta.'
                : 'Registro creado. No se pudo enviar el correo de verificacion. Contacta al administrador.',
            'redirect_url' => url('/verify-email'),
            'onboarding_after_verify' => true,
        ], 201);
    }

    public function verifyEmail(Request $request)
    {
        $token = $request->query('token');
        if (!$token) {
            return response()->json(['message' => 'Token invalido'], 400);
        }

        $record = DB::table('email_verification_tokens')
            ->where('token', $token)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Token invalido'], 400);
        }

        if (now()->gt($record->expires_at)) {
            DB::table('email_verification_tokens')->where('token', $token)->delete();
            return response()->json(['message' => 'Token expirado'], 400);
        }

        $user = User::find($record->user_id);
        if (!$user) {
            DB::table('email_verification_tokens')->where('token', $token)->delete();
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        DB::transaction(function () use ($user, $token) {
            $user->email_verified_at = now();
            $user->status = 'active';

            $isInvited = UserInvitation::query()
                ->where('email', $user->email)
                ->where('status', 'accepted')
                ->exists();

            if (! $isInvited && $user->roles()->count() === 0) {
                // RBAC v2 (Fase 6, Paso 3) -- CASO ESPECIAL, sin resolver del
                // todo: en este punto del flujo (verificación de email de un
                // registro nuevo, no invitado) el usuario TODAVÍA NO tiene
                // client_id -- User::register() lo crea con client_id=null
                // (línea ~156) y el Client real se crea después, en el
                // wizard de onboarding (OperatorOnboardingController). No
                // hay tenant al que scoped-ear este rol todavía.
                //
                // Uso el centinela de plataforma como placeholder explícito
                // (no un lookup sin team_id, que filtraría por CUALQUIER
                // 'admin' de CUALQUIER tenant -- la fuga cross-tenant que
                // Paso 3 existe para cerrar). Esto es una decisión de
                // bootstrap provisional, NO una resolución completa: cuando
                // el usuario complete el onboarding y su Client exista de
                // verdad, esta asignación sigue viviendo en el team_id
                // centinela, no en el de su tenant real -- alguien tiene que
                // decidir si el onboarding debe re-scopear esta asignación
                // en ese momento, o si el auto-promote a 'admin' debería
                // moverse por completo al momento de creación del Client en
                // vez de vivir aquí. Señalado explícitamente, no resuelto.
                setPermissionsTeamId(config('tenancy.super_admin_team_id'));
                $adminRole = Role::where('team_id', config('tenancy.super_admin_team_id'))
                    ->where('name', 'admin')
                    ->where('guard_name', 'web')
                    ->first();
                if ($adminRole) {
                    $user->syncRoles([$adminRole]);
                }
            }

            $user->save();

            DB::table('email_verification_tokens')->where('token', $token)->delete();
        });

        $user->refresh()->load([
            'roles:id,name,guard_name',
            'operatorProfile:id,user_id',
        ]);

        $onboarding = app(OnboardingRedirectService::class);
        $redirect = $onboarding->redirectPath($user) ?? '/home';

        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $authPayload = $user->toArray();
        $authPayload['client_id'] = $user->site?->client_id;

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Correo verificado correctamente.',
                'onboarding_redirect' => $redirect,
                'user' => $authPayload,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            ]);
        }

        return redirect($redirect);
    }

    public function logout(Request $request)
    {
        // Cierre de sesión explícito con el guard de sesión (web)
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Sesion cerrada'
        ]);
    }

    /**
     * Ping ligero para heartbeat: actualiza last_activity de la sesión sin devolver datos.
     * Mejora la precisión del monitor de sesiones cuando el usuario tiene la app abierta.
     */
    public function ping()
    {
        return response()->noContent();
    }
}
