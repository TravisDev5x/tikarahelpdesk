<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 2.2 (ITAM -- bajas estructuradas). El
 * InvMovement tipo BAJA (bitácora inmutable, "esto pasó") sigue siendo la
 * fuente de verdad de que el activo se dio de baja -- esta tabla es su
 * detalle estructurado 1:1 (method/autorización/valor residual), que antes
 * no existía (retire() solo pedía un reason de texto libre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('inv_movements')->nullOnDelete();
            $table->string('method', 40);
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('residual_value', 12, 2)->nullable();
            $table->foreignId('client_id')->constrained('clients');
            $table->timestamps();
            $table->index(['client_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_disposals');
    }
};
