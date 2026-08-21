<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario (fase 1, crítico): inv_maintenances era el único
 * modelo operativo del módulo sin soft delete -- InvMaintenanceController::destroy()
 * borraba la fila para siempre, perdiendo diagnóstico/costo/técnico de una
 * reparación real. Mismo patrón que inv_assets/inv_components.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_maintenances', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('inv_maintenances', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
