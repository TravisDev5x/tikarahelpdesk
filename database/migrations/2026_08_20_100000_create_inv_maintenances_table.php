<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenimientos de activos (port desde HelpdeskECD2026, fase 5). A
 * diferencia de inv_movements/inv_component_movements, NO es una bitácora
 * inmutable -- es un recurso editable con ciclo de vida propio (end_date
 * null = abierto). Abrir uno SÍ genera un InvMovement tipo MAINTENANCE
 * (ver comentario en 2026_08_19_100000_create_inv_movements_table.php);
 * cerrar (fijar end_date) no duplica un movimiento nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->foreignId('origin_id')->nullable()->constrained('inv_maintenance_origins')->nullOnDelete();
            $table->foreignId('modality_id')->nullable()->constrained('inv_maintenance_modalities')->nullOnDelete();
            $table->string('title');
            $table->text('diagnosis')->nullable();
            $table->text('solution')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('logged_by')->constrained('users');
            $table->foreignId('client_id')->constrained('clients');
            $table->timestamps();
            $table->index(['client_id', 'asset_id']);
            $table->index(['asset_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_maintenances');
    }
};
