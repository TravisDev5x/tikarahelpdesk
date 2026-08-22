<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class IncidentAttachmentController extends Controller
{
    public function store(Request $request, Incident $incident)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        Gate::authorize('update', $incident);

        $data = $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $saved = [];
        foreach ($request->file('attachments', []) as $file) {
            // Auditoría de bugs críticos (2026-08-22): mismo fix que
            // TicketAttachmentController::store() -- disco 'local', solo
            // accesible vía download() autenticado, no más 'public'.
            $path = $file->store("incidents/{$incident->id}", ['disk' => 'local']);
            $attachment = IncidentAttachment::create([
                'incident_id' => $incident->id,
                'uploaded_by' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'file_name' => basename($path),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'disk' => 'local',
            ]);
            $saved[] = $attachment;
        }

        return response()->json($saved, 201);
    }

    public function destroy(Incident $incident, IncidentAttachment $attachment)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        Gate::authorize('update', $incident);

        if ($attachment->incident_id !== $incident->id) {
            return response()->json(['message' => 'Adjunto no valido'], 404);
        }

        if ($attachment->file_path) {
            Storage::disk($attachment->disk ?: 'public')->delete($attachment->file_path);
        }
        $attachment->delete();

        return response()->noContent();
    }

    public function download(Incident $incident, IncidentAttachment $attachment)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        Gate::authorize('view', $incident);

        if ((int) $attachment->incident_id !== (int) $incident->id) {
            return response()->json(['message' => 'Adjunto no valido'], 404);
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
