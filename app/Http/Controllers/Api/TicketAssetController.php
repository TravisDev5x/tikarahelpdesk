<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvAsset;
use App\Models\InvAssetTicket;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Relación Activo↔Ticket (auditoría de Inventario, fase 3.1 -- Sección L,
 * la brecha crítica del informe). Vive del lado de Tickets, gateado por
 * TicketPolicy -- mismo patrón que TicketAttachmentController (Gate
 * dentro del método, sin middleware perm:). Vincular un activo a un
 * ticket NO exige ningún permiso de Inventario -- exige poder adjuntar
 * cosas a ESE ticket, igual que subir un archivo.
 */
class TicketAssetController extends Controller
{
    public function store(Request $request, Ticket $ticket)
    {
        Gate::authorize('attach', $ticket);

        $data = $request->validate([
            'asset_id' => 'required|exists:inv_assets,id',
            'notes' => 'nullable|string|max:2000',
        ]);

        $asset = InvAsset::findOrFail($data['asset_id']);
        if ((int) $asset->client_id !== (int) $ticket->client_id) {
            return response()->json(['message' => 'El activo no pertenece a este tenant'], 422);
        }

        $alreadyLinked = InvAssetTicket::where('inv_asset_id', $asset->id)->where('ticket_id', $ticket->id)->exists();
        if ($alreadyLinked) {
            return response()->json(['message' => 'Este activo ya está vinculado a este ticket'], 422);
        }

        $link = InvAssetTicket::create([
            'inv_asset_id' => $asset->id,
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'linked_by' => Auth::id(),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($link->load('asset:id,name,internal_tag'), 201);
    }

    public function destroy(Ticket $ticket, InvAssetTicket $link)
    {
        Gate::authorize('update', $ticket);

        if ((int) $link->ticket_id !== (int) $ticket->id) {
            return response()->json(['message' => 'Enlace no válido'], 404);
        }

        $link->delete();

        return response()->noContent();
    }

    /** Búsqueda ligera para el selector -- acotada al tenant del ticket, no al scope de inventario del usuario. */
    public function search(Request $request, Ticket $ticket)
    {
        Gate::authorize('view', $ticket);

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        return InvAsset::where('client_id', $ticket->client_id)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('internal_tag', 'like', "%{$q}%")
                    ->orWhere('serial', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'internal_tag', 'category_id']);
    }
}
