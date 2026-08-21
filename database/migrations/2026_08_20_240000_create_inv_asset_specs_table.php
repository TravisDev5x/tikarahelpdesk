<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 2.1 (ITAM -- ficha técnica estructurada).
 * Reemplaza inv_assets.specs (json de un solo campo libre {notes: string},
 * ver InvAssetController::packSpecs()) por EAV ligero -- una fila por
 * atributo, en vez de columnas fijas que engordarían inv_assets con
 * decenas de campos que no aplican a todas las categorías (consumibles no
 * necesitan CPU/RAM). El schema de qué keys existen por tipo de categoría
 * vive en PHP (App\Support\Inventory\AssetSpecSchema), no en esta tabla.
 *
 * client_id denormalizado desde inv_assets -- mismo patrón ya usado por
 * inv_movements/inv_maintenances/inv_components, necesario para que
 * Auditable resuelva el tenant del log sin tener que cargar la relación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_asset_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->string('key', 60);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'key']);
            $table->index(['client_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_asset_specs');
    }
};
