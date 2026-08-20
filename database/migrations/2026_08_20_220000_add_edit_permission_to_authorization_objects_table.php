<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC v2: el catálogo de objetos (full_permission/read_permission) solo
 * soportaba 2 niveles (Full/Solo lectura). Inventario retoma sus permisos
 * granulares (fase 7.4) con un modelo de 3 niveles genuino: ver / editar
 * (sin eliminar) / gestionar todo -- se agrega edit_permission, nullable
 * y opcional para todos los demás objetos existentes (ninguno lo usa hoy,
 * solo "Activos" de Inventario).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorization_objects', function (Blueprint $table) {
            $table->string('edit_permission')->nullable()->after('read_permission');
        });
    }

    public function down(): void
    {
        Schema::table('authorization_objects', function (Blueprint $table) {
            $table->dropColumn('edit_permission');
        });
    }
};
