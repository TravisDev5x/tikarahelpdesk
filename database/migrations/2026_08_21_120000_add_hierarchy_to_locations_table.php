<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 2.3 (ubicación jerárquica). `locations` es
 * compartida con Users (users.location_id) y Tickets (tickets.location_id)
 * -- este cambio es puramente aditivo: parent_id nulo = exactamente el
 * comportamiento de hoy, ningún controlador fuera de Inventario/Locations
 * necesita cambiar porque ninguno le importa la jerarquía, solo
 * location_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('site_id')->constrained('locations')->nullOnDelete();
            $table->string('type', 40)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('type');
        });
    }
};
