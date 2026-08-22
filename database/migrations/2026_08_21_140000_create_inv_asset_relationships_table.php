<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 3.2 (CMDB -- relaciones entre activos,
 * Sección I del informe). No confundir con inv_components (fase 4): eso
 * es para partes NO independientes (RAM/batería/SSD, sin ciclo de vida
 * propio como activo); esto es para activos independientes vinculados
 * entre sí (laptop+dock+monitor, servidor+UPS+storage).
 *
 * child_asset_id != parent_asset_id se valida en el controlador, no aquí
 * -- mismo criterio que LocationController::update() para parent_id
 * (CHECK constraint no es portable de forma simple entre SQLite/Postgres
 * vía Schema Builder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_asset_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->foreignId('child_asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->string('relationship_type', 40);
            $table->text('notes')->nullable();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['parent_asset_id', 'child_asset_id']);
            $table->index(['client_id', 'parent_asset_id']);
            $table->index(['client_id', 'child_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_asset_relationships');
    }
};
