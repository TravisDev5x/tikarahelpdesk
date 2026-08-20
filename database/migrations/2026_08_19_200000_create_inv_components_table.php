<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Componentes de activos (port desde HelpdeskECD2026, fase 4). Tabla propia
 * `inv_components`, no la `components` híbrida V1+V2 de Helpdesk -- mismo
 * criterio que fase 1 (solo se porta v2), sin las columnas legado
 * (producto_id, costo, fecha_ingreso, owner, company_id, user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained('inv_assets')->nullOnDelete();
            $table->foreignId('origin_asset_id')->nullable()->constrained('inv_assets')->nullOnDelete();
            $table->string('name');
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('serie')->nullable();
            $table->string('capacidad')->nullable();
            $table->text('observacion')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('client_id')->constrained('clients');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'asset_id']);
        });

        Schema::create('inv_component_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('inv_components')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('inv_assets')->nullOnDelete();
            $table->foreignId('origin_asset_id')->nullable()->constrained('inv_assets')->nullOnDelete();
            $table->string('type'); // ASIGNAR | RETIRAR | BAJA | EXTRACCION
            $table->foreignId('admin_id')->constrained('users');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('client_id')->constrained('clients');
            $table->dateTime('date');
            $table->timestamps();
            $table->index(['client_id', 'component_id']);
            $table->index(['component_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_component_movements');
        Schema::dropIfExists('inv_components');
    }
};
