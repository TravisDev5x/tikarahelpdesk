<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de rendimiento/datos del dashboard operativo (2026-08-10):
 * ResolbebController calculaba "hoy"/"esta semana"/"este mes" con
 * Carbon::now() -- siempre UTC (config('app.timezone')) -- mientras el
 * negocio opera en hora de México. Un ticket creado a las 19:00 hora
 * México ya cae en el día siguiente en UTC, y aparecía en el balde
 * equivocado de "Tendencia de resolución"/"Top Sedes"/"MTTR semanal".
 *
 * Sin UI de configuración todavía (deliberado, alcance de este sprint) --
 * solo la columna con el default correcto para todos los tenants
 * existentes y nuevos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('business_timezone', 64)->default('America/Mexico_City')->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('business_timezone');
        });
    }
};
