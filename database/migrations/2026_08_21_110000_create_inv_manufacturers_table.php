<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 2.3 (catálogo de fabricantes). Misma forma
 * y patrón operador/tenant que inv_labels (el catálogo más simple que ya
 * existe -- ver 2026_08_16_100000_create_inventory_catalogs_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'operator_user_id']);
        });

        Schema::table('inv_assets', function (Blueprint $table) {
            $table->foreignId('manufacturer_id')->nullable()->after('category_id')->constrained('inv_manufacturers')->nullOnDelete();
            $table->string('model')->nullable()->after('manufacturer_id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manufacturer_id');
            $table->dropColumn('model');
        });
        Schema::dropIfExists('inv_manufacturers');
    }
};
