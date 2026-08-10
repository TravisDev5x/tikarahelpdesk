<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7 (onboarding): máquina de estados retomable para el alta de un
 * tenant nuevo. Empieza en 'tenant_named' (no 'registered') porque el
 * Client todavía no existe en el sub-paso 7.1 (registro + consentimiento
 * de privacidad vive en `users`, no aquí) -- la fila de Client nace
 * exactamente en el paso que le da nombre, y TenantOnboardingController
 * lo fija explícitamente al crearla.
 *
 * Default 'completed': cualquier Client creado por un camino que NO es
 * este flujo (seeders, alta manual de super_admin, etc.) no está "a medio
 * onboarding" -- incluye a los 3 clients de dev ya existentes, que quedan
 * en 'completed' automáticamente sin necesidad de un UPDATE explícito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('onboarding_step', 30)->default('completed')->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('onboarding_step');
        });
    }
};
