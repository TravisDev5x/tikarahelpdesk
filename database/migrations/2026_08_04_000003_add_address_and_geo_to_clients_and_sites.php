<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dirección + coordenadas para mostrar el mapa en info de cliente/sede y
 * que los técnicos puedan navegar hasta ahí. clients no tenía ninguna
 * columna de dirección; sites ya tenía `address`/`city`, solo le faltan
 * las coordenadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('address')->nullable()->after('website');
            $table->decimal('latitude', 10, 8)->nullable()->after('address');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('address');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['address', 'latitude', 'longitude']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
