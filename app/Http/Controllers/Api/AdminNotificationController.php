<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClientScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Fuga cross-tenant corregida 2026-08-12: admin_notifications no tenía
 * client_id, así que cualquier admin de tenant (el bypass de
 * EnsurePermissionOrAdmin da acceso a todo usuario con rol 'admin', sin
 * importar el permiso pedido) veía y podía resolver solicitudes de reset de
 * contraseña de OTROS tenants -- toma de cuenta cross-tenant completa. Ver
 * auditoría de la misma fecha.
 */
class AdminNotificationController extends Controller
{
    public function __construct(protected ClientScopeService $clientScope) {}

    /** Filtro por tenant del actor; null real (no 0 filas) para operador de plataforma sin scope. */
    private function scopeQuery(Request $request, \Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
    {
        $actor = $request->user();
        if ($this->clientScope->bypassesClientScope($actor)) {
            return $query;
        }

        $clientId = $this->clientScope->resolveUserClientId($actor);
        if (! $clientId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('client_id', $clientId);
    }

    public function index(Request $request)
    {
        $items = $this->scopeQuery($request, DB::table('admin_notifications'))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $row->payload = $row->payload ? json_decode($row->payload, true) : [];
                // Enriquecer con datos de usuario sugerido si existe
                $uid = $row->payload['user_id'] ?? $row->payload['userId'] ?? null;
                if ($uid) {
                    $user = User::find($uid);
                    if ($user) {
                        $row->payload['user_name'] = $user->name;
                        $row->payload['user_email'] = $user->email;
                        $row->payload['user_employee_number'] = $user->employee_number;
                    }
                }
                return $row;
            });

        return response()->json(['notifications' => $items]);
    }

    public function markRead(Request $request, $id)
    {
        $updated = $this->scopeQuery($request, DB::table('admin_notifications'))
            ->where('id', $id)
            ->update(['read_at' => now()]);

        if (! $updated) {
            return response()->json(['message' => 'Notificación no encontrada'], 404);
        }

        return response()->json(['message' => 'Notificación marcada como leída']);
    }

    public function resolvePasswordReset(Request $request, $id)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'password' => [
                'required', 'confirmed', 'min:12',
                'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
            ],
            'communication_method' => 'required|in:whatsapp_empresarial,telefono_empresarial,personal,personalmente',
            'comment' => 'nullable|string|max:500',
        ]);

        $row = $this->scopeQuery($request, DB::table('admin_notifications'))
            ->where('id', $id)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Notificación no encontrada'], 404);
        }

        // Defensa en profundidad: aunque la notificación sea del tenant del
        // actor, el user_id lo manda el cliente en el body -- confirmar que
        // también es accesible para el actor antes de tocar su contraseña.
        if (! $this->clientScope->assertUserAccessible($request->user(), (int) $data['user_id'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $user = User::findOrFail($data['user_id']);
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'force_password_change' => true,
        ])->save();

        $payload = $row->payload ? json_decode($row->payload, true) : [];
        $payload['resolved_at'] = now()->toIso8601String();
        $payload['communication_method'] = $data['communication_method'];
        $payload['comment'] = $data['comment'] ?? null;

        DB::table('admin_notifications')->where('id', $id)->update([
            'read_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode($payload),
        ]);

        return response()->json(['message' => 'Contraseña restablecida. Comunica la nueva contraseña al empleado por el medio indicado.']);
    }
}
