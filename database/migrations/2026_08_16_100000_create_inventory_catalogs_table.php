<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos base del módulo Inventario (portado desde HelpdeskECD2026, fase 1).
 * Mismo patrón operador/cliente que el resto de App\Services\OperatorCatalogScopeService::CATALOG_TABLES
 * — ver docs/CATALOG_TENANCY_MODEL.md. `users`/`clients` ya existen para esta fecha de
 * migración, así que operator_user_id/client_id se crean directo aquí (a diferencia de
 * campaigns/areas/positions, que los agregan en una migración aparte por orden histórico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable(); // HARDWARE | SOFTWARE | CONSUMIBLE
            $table->string('prefix', 20)->nullable();
            $table->boolean('require_specs')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'operator_user_id']);
        });

        Schema::create('inv_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('badge_class')->nullable();
            $table->boolean('assignable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'operator_user_id']);
        });

        Schema::create('inv_labels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'operator_user_id']);
        });

        Schema::create('inv_maintenance_origins', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'operator_user_id']);
        });

        Schema::create('inv_maintenance_modalities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'operator_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_maintenance_modalities');
        Schema::dropIfExists('inv_maintenance_origins');
        Schema::dropIfExists('inv_labels');
        Schema::dropIfExists('inv_statuses');
        Schema::dropIfExists('inv_categories');
    }
};
