<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Estado global "Rechazado" (final) para el flujo de aceptar/rechazar en
 * la tabla de tickets -- rechazar cambia ticket_state_id a este estado +
 * guarda el motivo como nota vía el mismo TicketController::update() que
 * ya existe (ticket_state_id + note en un solo request), sin endpoint
 * nuevo. client_id/operator_user_id NULL = catálogo global, mismo
 * criterio que Abierto/En progreso/Cerrado/etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ticket_states')->insertOrIgnore([
            'name' => 'Rechazado',
            'code' => 'rechazado',
            'is_active' => true,
            'is_final' => true,
            'client_id' => null,
            'operator_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ticket_states')->where('code', 'rechazado')->delete();
    }
};
