<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvMovement;
use App\Services\ClientScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV del historial de asignaciones/movimientos (fase 7.2, port
 * desde HelpdeskECD2026 -- InventoryAssignmentHistoryExportController).
 * A diferencia del original (delimitador ";", pensado para Excel regional
 * de Windows), aquí "," -- Tikara no tiene ese problema. Sin UI de
 * filtros todavía (ver plan), pero el backend ya los acepta.
 */
class InvMovementExportController extends Controller
{
    public function __construct(protected ClientScopeService $clientScope) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $user = Auth::user();

        $query = $this->clientScope->applyInventoryMovementScope(
            InvMovement::query()->with(['asset:id,name,internal_tag', 'user', 'previousUser', 'admin']),
            $user
        );

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('asset', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('internal_tag', 'like', "%{$search}%");
            });
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('admin_id')) {
            $query->where('admin_id', $request->input('admin_id'));
        }
        if ($request->filled('batch_uuid')) {
            $query->where('batch_uuid', $request->input('batch_uuid'));
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        $filename = 'historial_asignaciones_'.now()->format('Ymd_His').'.csv';
        $headings = [
            'ID', 'Fecha', 'Tipo', 'Activo ID', 'Etiqueta del activo', 'Nombre del activo',
            'Usuario nuevo', 'Usuario anterior', 'Registrado por', 'Motivo', 'Notas', 'Lote UUID',
        ];

        return response()->streamDownload(function () use ($query, $headings) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($handle, $headings);

            foreach ($query->orderByDesc('date')->cursor() as $m) {
                fputcsv($handle, [
                    $m->id,
                    $m->date?->format('Y-m-d H:i:s'),
                    $m->type,
                    $m->asset_id,
                    $m->asset?->internal_tag,
                    $m->asset?->name,
                    $this->userLabel($m->user),
                    $this->userLabel($m->previousUser),
                    $this->userLabel($m->admin),
                    $m->reason,
                    $m->notes,
                    $m->batch_uuid,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function userLabel(?\App\Models\User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return trim(implode(' ', array_filter([$user->first_name, $user->paternal_last_name, $user->maternal_last_name])));
    }
}
