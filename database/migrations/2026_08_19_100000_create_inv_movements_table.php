<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de movimientos de activos (port desde HelpdeskECD2026, fase 3).
 * Inmutable -- solo insert, nunca update/delete (ver InvMovementController,
 * sin métodos update/destroy a propósito). Solo 4 tipos por ahora
 * (CHECKOUT/CHECKIN/TRASLADO/BAJA); MAINTENANCE es de fase 5, AUDIT no
 * tiene acción de UI clara en el original -- ninguno de los dos se porta
 * todavía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->string('type'); // CHECKOUT | CHECKIN | TRASLADO | BAJA
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('previous_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_id')->constrained('users');
            $table->string('responsiva_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('client_id')->constrained('clients');
            $table->dateTime('date');
            $table->timestamps();
            $table->index(['client_id', 'asset_id']);
            $table->index(['asset_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_movements');
    }
};
