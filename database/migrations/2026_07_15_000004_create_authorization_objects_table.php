<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC v2 (Fase 6), Paso 4: catálogo GLOBAL (no por tenant -- el árbol de
 * módulos es el mismo para todos) de "objetos de autorización", capa de
 * presentación sobre el catálogo de permisos de Spatie ya existente. NO
 * introduce permisos nuevos -- full_permission/read_permission siempre
 * referencian nombres que ya existen en la tabla `permissions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_objects', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->foreignId('parent_id')->nullable()->constrained('authorization_objects')->nullOnDelete();
            $table->string('full_permission')->nullable();
            $table->string('read_permission')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_objects');
    }
};
