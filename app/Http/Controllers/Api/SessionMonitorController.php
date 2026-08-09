<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClientScopeService;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SessionMonitorController extends Controller
{
    public function __construct(
        protected ClientScopeService $clientScope
    ) {}

    /**
     * Lista sesiones activas (usuarios con sesión abierta).
     * Solo expone: usuario, última actividad, IP, navegador. No expone session id ni payload.
     * Requiere permiso users.manage.
     */
    public function index(Request $request)
    {
        if (config('session.driver') !== 'database') {
            return response()->json([
                'sessions' => [],
                'total' => 0,
                'message' => 'El monitor de sesiones requiere SESSION_DRIVER=database.',
            ]);
        }

        $actor = Auth::user();
        if (! $actor) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        $table = config('session.table', 'sessions');
        $lifetime = (int) config('session.lifetime', 120);
        $minActivity = now()->subMinutes($lifetime)->timestamp;

        $allowedUsers = User::query();
        $this->clientScope->applyUserScope($allowedUsers, $actor);

        $sessions = DB::table($table)
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $minActivity)
            ->whereIn('user_id', $allowedUsers->select('users.id'))
            ->join('users', $table.'.user_id', '=', 'users.id')
            ->select(
                'users.id as user_id',
                'users.first_name',
                'users.paternal_last_name',
                'users.maternal_last_name',
                'users.name as name_legacy',
                'users.email',
                'users.employee_number',
                'users.avatar_path',
                'users.availability',
                'users.last_login_at',
                $table.'.ip_address',
                $table.'.user_agent',
                $table.'.last_activity'
            )
            ->orderByDesc($table.'.last_activity')
            ->get();

        $list = $sessions->map(function ($row) {
            $lastLoginAt = $row->last_login_at ? \Illuminate\Support\Carbon::parse($row->last_login_at) : null;
            $fullName = trim(($row->first_name ?? '').' '.($row->paternal_last_name ?? '').' '.($row->maternal_last_name ?? ''));
            if ($fullName === '') {
                $fullName = (string) ($row->name_legacy ?? '');
            }
            $avatarPath = $row->avatar_path ?? null;
            $avatarUrl = is_string($avatarPath) && trim($avatarPath) !== ''
                ? rtrim(config('app.url'), '/').'/storage/'.ltrim($avatarPath, '/')
                : null;

            return [
                'user_id' => $row->user_id,
                'name' => $fullName,
                'email' => $row->email ?? $row->employee_number,
                'employee_number' => $row->employee_number,
                'avatar_path' => $avatarPath,
                'avatar_url' => $avatarUrl,
                'availability' => $row->availability ?? 'disconnected',
                'last_activity' => (int) $row->last_activity,
                'last_activity_iso' => date('c', (int) $row->last_activity),
                'last_login_at' => $lastLoginAt ? $lastLoginAt->getTimestamp() : null,
                'last_login_iso' => $lastLoginAt ? $lastLoginAt->toIso8601String() : null,
                'ip_address' => $row->ip_address ?? '',
                'browser' => UserAgentParser::browser($row->user_agent ?? ''),
            ];
        });

        return response()->json([
            'sessions' => $list,
            'total' => $list->count(),
        ]);
    }

    /**
     * Fuerza el cierre de sesión de un usuario eliminando sus sesiones de la tabla de sesiones.
     * No expone session IDs y requiere permiso users.manage (mismo grupo de rutas).
     */
    public function logoutUser(Request $request)
    {
        if (config('session.driver') !== 'database') {
            return response()->json([
                'message' => 'El cierre remoto de sesión requiere SESSION_DRIVER=database.',
            ], 400);
        }

        $actor = Auth::user();
        if (! $actor) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        if (! $this->clientScope->assertUserAccessible($actor, (int) $validated['user_id'])) {
            return response()->json(['message' => 'No tienes acceso a este usuario.'], 403);
        }

        $table = config('session.table', 'sessions');

        DB::table($table)
            ->where('user_id', $validated['user_id'])
            ->delete();

        return response()->json([
            'message' => 'Sesiones del usuario cerradas',
        ]);
    }

}
