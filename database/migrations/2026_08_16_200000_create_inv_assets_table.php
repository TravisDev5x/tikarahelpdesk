<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de activos (port desde HelpdeskECD2026, fase 2). A diferencia de
 * los catálogos de fase 1 (operador-compartidos), esto es dato operativo
 * scoped por client_id — mismo criterio que tickets/incidents. site_id ya
 * trae client_id/customer_id propios (App\Models\Site), así que no se porta
 * el concepto de "company" de Helpdesk (companies era el catálogo de
 * empresas de un Helpdesk single-tenant; en Tikara esa distinción ya la
 * resuelve site.client_id/site.customer_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('internal_tag');
            $table->string('serial')->nullable();
            $table->string('name');
            $table->foreignId('category_id')->constrained('inv_categories');
            $table->foreignId('status_id')->constrained('inv_statuses');
            $table->foreignId('label_id')->nullable()->constrained('inv_labels')->nullOnDelete();
            $table->string('condition')->nullable(); // NUEVO | BUENO | REGULAR | MALO | PARA_PIEZAS
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->json('specs')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->string('supplier')->nullable();
            $table->string('invoice_number')->nullable();
            // Caché del responsable actual -- no se llena hasta fase 3
            // (asignar/devolver), aquí solo se crea la columna.
            $table->foreignId('current_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('client_id')->constrained('clients');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'category_id']);
            $table->index(['client_id', 'status_id']);
            $table->index(['client_id', 'site_id']);
            // Únicos por tenant, no globales -- dos clientes distintos
            // pueden repetir el mismo número de serie/tag sin conflicto.
            $table->unique(['client_id', 'uuid']);
            $table->unique(['client_id', 'internal_tag']);
            $table->unique(['client_id', 'serial']);
        });

        Schema::create('inv_asset_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inv_asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->string('path');
            $table->string('disk')->default('public');
            $table->string('type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_asset_images');
        Schema::dropIfExists('inv_assets');
    }
};
