<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuota de activos de inventario por plan, mismo criterio que
 * max_clients/max_users/max_agents (null = ilimitado). Ver plan "Cuota de
 * activos por plan" -- previo a la fase 7 del port de Inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_assets')->nullable()->after('max_agents');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('max_assets');
        });
    }
};
