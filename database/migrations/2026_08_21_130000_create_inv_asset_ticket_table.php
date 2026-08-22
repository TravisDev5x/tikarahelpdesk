<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 3.1 (CMDB -- relación Activo↔Ticket). La
 * brecha crítica de todo el informe: hasta ahora no había ninguna
 * referencia entre `tickets` e `inv_assets`. Tabla de enlace propia, no
 * belongsToMany -- mismo criterio que inv_movements/inv_component_movements
 * (un modelo real con metadata propia, auditable), no una pivote "mágica".
 * timestamps() completo aunque el registro no se edite (no se actualiza,
 * se borra y se vuelve a crear) -- mismo criterio que inv_movements, que
 * tampoco distingue y siempre trae created_at/updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_asset_ticket', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inv_asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('linked_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['inv_asset_id', 'ticket_id']);
            $table->index(['client_id', 'ticket_id']);
            $table->index(['client_id', 'inv_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_asset_ticket');
    }
};
