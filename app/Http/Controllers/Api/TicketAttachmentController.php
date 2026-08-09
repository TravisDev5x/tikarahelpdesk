<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class TicketAttachmentController extends Controller
{
    public function store(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        Gate::authorize('attach', $ticket);

        $data = $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $saved = [];
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("tickets/{$ticket->id}", ['disk' => 'public']);
            $attachment = TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'uploaded_by' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'file_name' => basename($path),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'disk' => 'public',
            ]);
            $saved[] = $attachment;
        }

        return response()->json($saved, 201);
    }

    public function destroy(Ticket $ticket, TicketAttachment $attachment)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        Gate::authorize('update', $ticket);

        if ((int) $attachment->ticket_id !== (int) $ticket->id) {
            return response()->json(['message' => 'Adjunto no válido'], 404);
        }

        if ($attachment->file_path) {
            Storage::disk($attachment->disk ?: 'public')->delete($attachment->file_path);
        }
        $attachment->delete();

        return response()->noContent();
    }

    public function download(Ticket $ticket, TicketAttachment $attachment)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        Gate::authorize('view', $ticket);

        if ((int) $attachment->ticket_id !== (int) $ticket->id) {
            return response()->json(['message' => 'Adjunto no válido'], 404);
        }

        if (!$attachment->file_path || !Storage::disk($attachment->disk ?: 'public')->exists($attachment->file_path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        return Storage::disk($attachment->disk ?: 'public')->download(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type]
        );
    }
}
