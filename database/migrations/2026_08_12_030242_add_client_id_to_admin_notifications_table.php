<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fuga cross-tenant: admin_notifications no tenía client_id, así que
 * AdminNotificationController mostraba (y permitía resolver) solicitudes
 * de reset de contraseña / baja de cuenta de CUALQUIER tenant al admin de
 * CUALQUIER OTRO tenant (el bypass de EnsurePermissionOrAdmin da acceso a
 * todo usuario con rol 'admin', y todo tenant tiene uno). Ver auditoría
 * 2026-08-12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained('clients')->nullOnDelete();
        });

        // Backfill best-effort: para las filas ya existentes con
        // payload.user_id, atribuir el client_id directo de ese usuario.
        // password_reset_missing_email no tiene user_id (el correo no
        // coincidió con nadie) -- queda client_id=null a propósito, solo
        // visible para operadores de plataforma (bypassesClientScope), que
        // es el único que puede darle seguimiento a un intento huérfano.
        $rows = DB::table('admin_notifications')->whereNull('client_id')->get(['id', 'payload']);
        foreach ($rows as $row) {
            $payload = $row->payload ? json_decode($row->payload, true) : null;
            $userId = $payload['user_id'] ?? null;
            if (! $userId) {
                continue;
            }
            $clientId = DB::table('users')->where('id', $userId)->value('client_id');
            if ($clientId) {
                DB::table('admin_notifications')->where('id', $row->id)->update(['client_id' => $clientId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
