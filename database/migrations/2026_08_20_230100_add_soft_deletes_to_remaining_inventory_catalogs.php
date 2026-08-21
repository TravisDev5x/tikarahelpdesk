<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario (fase 1, crítico): inv_categories/inv_labels ya
 * tenían soft delete desde la migración original; inv_statuses,
 * inv_maintenance_origins e inv_maintenance_modalities no -- sus 3
 * controladores hacían el mismo ->delete() que los otros dos, pero sin el
 * trait era un hard delete real. inv_statuses está protegido por FK
 * (inv_assets.status_id sin nullOnDelete -- Postgres rechaza el borrado
 * mientras esté en uso), pero inv_maintenance_origins/_modalities sí tienen
 * nullOnDelete en inv_maintenances -- un borrado ahí perdía silenciosamente
 * el origen/modalidad de mantenimientos históricos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_statuses', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('inv_maintenance_origins', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('inv_maintenance_modalities', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('inv_statuses', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('inv_maintenance_origins', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('inv_maintenance_modalities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
