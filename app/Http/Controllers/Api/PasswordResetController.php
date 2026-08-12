<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TenantClientResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(protected TenantClientResolver $tenantResolver) {}

    /**
     * Solicitar restablecimiento de contraseña.
     * Acepta correo (envía enlace) o número de empleado (crea solicitud para que un admin restablezca y comunique por medios empresariales).
     */
    public function forgot(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string|max:255',
            'hcaptcha_token' => 'nullable|string',
        ]);

        $identifier = trim($request->input('identifier'));
        if ($identifier === '') {
            return response()->json(['message' => 'Indica tu correo o número de empleado.'], 422);
        }

        // Verificar hCaptcha si está configurado
        $secret = config('services.hcaptcha.secret');
        if ($secret) {
            $token = $request->input('hcaptcha_token');
            if (!$token) {
                return response()->json(['message' => 'Falta validación de captcha'], 422);
            }
            $resp = Http::asForm()->post('https://hcaptcha.com/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
            if (!$resp->ok() || !$resp->json('success')) {
                return response()->json(['message' => 'Captcha inválido'], 422);
            }
        }

        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;

        if ($isEmail) {
            $user = User::where('email', $identifier)->first();
            if ($user && $user->email) {
                $status = Password::sendResetLink(['email' => $identifier]);
                return $status === Password::RESET_LINK_SENT
                    ? response()->json(['message' => __('passwords.sent')])
                    : response()->json(['message' => __('passwords.throttled')], 429);
            }
            DB::table('admin_notifications')->insert([
                'type' => 'password_reset_missing_email',
                'payload' => json_encode(['requested_email' => $identifier, 'at' => now()->toIso8601String()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return response()->json(['message' => __('passwords.sent')], 202);
        }

        // Identificador como número de empleado: crear solicitud para que admin restablezca (usuario puede no tener correo)
        $user = User::where('employee_number', $identifier)->first();
        if ($user) {
            DB::table('admin_notifications')->insert([
                'type' => 'password_reset_request',
                'client_id' => $this->tenantResolver->resolve($user),
                'payload' => json_encode([
                    'user_id' => $user->id,
                    'requested_at' => now()->toIso8601String(),
                    'requested_via' => 'employee_number',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => __('passwords.sent')], 202);
    }

    /**
     * Restablecer contraseña usando token.
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => [
                'required', 'confirmed', 'min:12',
                'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
            ],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    'force_password_change' => false,
                ])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => __('passwords.reset')])
            : response()->json(['message' => __('passwords.token')], 422);
    }
}
