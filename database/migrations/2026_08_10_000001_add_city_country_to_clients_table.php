<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7, sub-paso 7.3 (datos de empresa): strings simples, sin catálogo de
 * países por ahora -- decisión explícita de alcance, no un descuido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('city')->nullable()->after('address');
            $table->string('country', 2)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['city', 'country']);
        });
    }
};
