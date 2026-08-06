<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backstop a nivel de BD contra tickets duplicados por reintento de webhook
 * de correo entrante (Mailgun reintenta si la respuesta tarda o no es 2xx).
 * ProcessInboundTicket ya hace un chequeo previo por origin_message_id, pero
 * ese chequeo por sí solo tiene ventana de carrera entre dos entregas casi
 * simultáneas -- este constraint es lo que realmente lo hace imposible.
 *
 * NULL no colisiona consigo mismo en un unique index (Postgres y SQLite:
 * "NULLs are distinct"), así que tickets creados por portal/agente
 * (origin_message_id NULL) no se ven afectados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unique(['client_id', 'origin_message_id'], 'tickets_client_origin_message_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique('tickets_client_origin_message_id_unique');
        });
    }
};
