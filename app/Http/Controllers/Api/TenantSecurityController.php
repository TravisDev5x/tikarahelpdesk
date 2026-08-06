<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\OperatorScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TenantSecurityController extends Controller
{
    /**
     * Eventos de frontera de tenant (login rechazado por portal incorrecto,
     * acceso bloqueado de una sesión ya autenticada a un subdominio ajeno).
     * Alimentado por TenantContextService::logBoundaryViolation().
     * Mismo scope que el resto de audit_logs: super_admin ve todo, operador
     * MSP solo eventos contra sus propios clientes.
     */
    public function index(Request $request, OperatorScopeService $operatorScope)
    {
        $user = Auth::user();
        if (! $user || ! $user->can('tickets.manage_all')) {
            return response()->json(['message' => 'Solo administradores pueden ver eventos de seguridad'], 403);
        }

        $query = $operatorScope->applyOnAuditLogs(
            AuditLog::query()
                ->where('auditable_type', 'tenant_boundary')
                ->with(['user:id,name,email', 'client:id,name']),
            $user
        );

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $logs = $query->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $logs]);
    }
}
