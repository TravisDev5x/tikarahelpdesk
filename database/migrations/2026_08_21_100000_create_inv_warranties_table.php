<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 2.3 (garantías como entidad propia).
 * inv_assets.warranty_expiry sigue siendo el campo rápido que ya alimenta
 * InvMonitorAlertsService::warrantyExpiring() -- esta tabla es un registro
 * más rico y OPCIONAL para el caso real de varias garantías por activo
 * (fabricante + extendida + de una reparación), no lo reemplaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->string('provider');
            $table->string('warranty_number')->nullable();
            $table->text('coverage')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at');
            $table->foreignId('client_id')->constrained('clients');
            $table->timestamps();
            $table->index(['client_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_warranties');
    }
};
