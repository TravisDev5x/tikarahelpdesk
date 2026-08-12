<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Notifications\Security\OauthAutoLinkNotification;
use App\Services\TenantClientResolver;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Autogestión de seguridad de cuenta: sesiones propias, cuentas conectadas
 * (Google/Microsoft), historial de accesos y solicitud de baja. Distinto de
 * SessionMonitorController (ese es la vista admin de sesiones de otros
 * usuarios); aquí un usuario solo ve/gestiona lo suyo, sin scope de cliente.
 */
class ProfileSecurityController extends Controller
{
    public function __construct(protected TenantClientResolver $tenantResolver) {}

    /**
     * Sesiones activas del usuario autenticado (un renglón por dispositivo/navegador).
     */
    public function sessions(Request $request)
    {
        if (config('session.driver') !== 'database') {
            return response()->json([
                'sessions' => [],
                'message' => 'El listado de sesiones requiere SESSION_DRIVER=database.',
            ]);
        }

        $user = Auth::user();
        $table = config('session.table', 'sessions');
        $currentId = $request->session()->getId();
        $lifetime = (int) config('session.lifetime', 120);
        $minActivity = now()->subMinutes($lifetime)->timestamp;

        $rows = DB::table($table)
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $minActivity)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $list = $rows->map(fn ($row) => [
            'id' => $row->id,
            'ip_address' => $row->ip_address ?? '',
            'browser' => UserAgentParser::browser($row->user_agent ?? ''),
            'is_mobile' => UserAgentParser::isMobile($row->user_agent ?? ''),
            'last_activity' => (int) $row->last_activity,
            'is_current' => $row->id === $currentId,
        ]);

        return response()->json(['sessions' => $list]);
    }

    /**
     * Cierra una sesión específica (no la actual: para eso está el logout normal).
     */
    public function revokeSession(Request $request, string $id)
    {
        if (config('session.driver') !== 'database') {
            return response()->json(['message' => 'No disponible con este SESSION_DRIVER.'], 400);
        }

        if ($id === $request->session()->getId()) {
            return response()->json(['message' => 'Usa "Cerrar sesión" para tu sesión actual.'], 422);
        }

        $user = Auth::user();
        $table = config('session.table', 'sessions');

        $deleted = DB::table($table)
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Sesión no encontrada.'], 404);
        }

        return response()->json(['message' => 'Sesión cerrada']);
    }

    /**
     * Cierra todas las sesiones del usuario excepto la actual.
     */
    public function revokeOtherSessions(Request $request)
    {
        if (config('session.driver') !== 'database') {
            return response()->json(['message' => 'No disponible con este SESSION_DRIVER.'], 400);
        }

        $user = Auth::user();
        $table = config('session.table', 'sessions');
        $currentId = $request->session()->getId();

        $count = DB::table($table)
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentId)
            ->delete();

        return response()->json(['message' => "Se cerraron {$count} sesión(es)", 'count' => $count]);
    }

    /**
     * Últimos accesos (contraseña / Google / Microsoft) vía audit_logs
     * (auditable_type sintético 'user_login', mismo patrón que
     * TenantContextService::logBoundaryViolation para eventos que no son
     * cambios de modelo).
     */
    public function loginHistory(Request $request)
    {
        $user = Auth::user();

        $items = AuditLog::where('auditable_type', 'user_login')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['action', 'ip_address', 'created_at'])
            ->map(fn ($log) => [
                'method' => $log->action,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['history' => $items]);
    }

    /**
     * Desvincula una cuenta OAuth (Google/Microsoft). Siempre seguro: el
     * usuario conserva su contraseña (nunca es nula) como método de acceso.
     */
    public function unlinkConnection(Request $request, string $provider)
    {
        if (! in_array($provider, ['google', 'microsoft'], true)) {
            return response()->json(['message' => 'Proveedor no soportado.'], 422);
        }

        $user = Auth::user();
        $field = $provider === 'google' ? 'google_id' : 'microsoft_id';
        $user->forceFill([$field => null])->save();

        return response()->json(['message' => 'Cuenta desvinculada', $field => null]);
    }

    /**
     * Solicitud de baja de cuenta: no borra nada -- crea un admin_notification
     * (mismo patrón que las solicitudes de restablecimiento de contraseña en
     * PasswordResetController) para que un administrador la revise y ejecute
     * manualmente. Eliminar en cascada tickets/auditoría/asignaciones de un
     * usuario no es una acción de un click.
     */
    public function requestDeletion(Request $request)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();

        DB::table('admin_notifications')->insert([
            'type' => 'account_deletion_request',
            'client_id' => $this->tenantResolver->resolve($user),
            'payload' => json_encode([
                'user_id' => $user->id,
                'requested_at' => now()->toIso8601String(),
                'reason' => $data['reason'] ?? null,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::channel('single')->info('Solicitud de eliminación de cuenta', ['user_id' => $user->id]);

        return response()->json(['message' => 'Tu solicitud fue enviada. Un administrador la revisará.']);
    }

    /**
     * Registra un login exitoso en audit_logs (auditable_type 'user_login').
     * Llamado desde AuthController::login y los callbacks de Google/Microsoft.
     * Nunca debe romper el flujo de login si falla (try/catch), igual que
     * TenantContextService::logBoundaryViolation.
     */
    public static function logLogin(\App\Models\User $user, string $method, ?string $ip = null, ?string $userAgent = null): void
    {
        try {
            AuditLog::create([
                'user_id' => $user->id,
                'client_id' => $user->client_id ?? $user->site?->client_id,
                'auditable_type' => 'user_login',
                'auditable_id' => $user->id,
                'action' => $method,
                'ip_address' => $ip ?? request()?->ip(),
                'user_agent' => $userAgent ?? request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Login audit log failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Registra en audit_logs que se vinculó un proveedor OAuth (google/microsoft)
     * a una cuenta -- vía login (auto-vínculo por coincidencia de correo) o vía
     * el botón "Conectar" en Mi perfil. `viaLogin=true` además notifica al
     * dueño de la cuenta: ese caso puede no haber sido iniciado por él mismo
     * (a diferencia de "Conectar", que siempre es una acción propia estando ya
     * autenticado), así que el vínculo nunca debe quedar silencioso.
     */
    public static function logOauthLink(\App\Models\User $user, string $provider, bool $viaLogin, ?string $ip = null): void
    {
        try {
            AuditLog::create([
                'user_id' => $user->id,
                'client_id' => $user->client_id ?? $user->site?->client_id,
                'auditable_type' => 'oauth_link',
                'auditable_id' => $user->id,
                'action' => $provider,
                'new_values' => ['via' => $viaLogin ? 'login_auto_link' : 'profile_connect'],
                'ip_address' => $ip ?? request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::error('OAuth link audit log failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        if ($viaLogin) {
            try {
                $user->notify(new OauthAutoLinkNotification($provider, $ip ?? request()?->ip()));
            } catch (\Throwable $e) {
                Log::error('OAuth auto-link notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
