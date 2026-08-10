<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7 (onboarding), sub-paso 7.1: consentimiento del aviso de privacidad
 * (LFPDPPP) en el registro. Nullable -- usuarios existentes no lo tienen
 * retroactivo, no se puede inventar una fecha/IP real para ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('privacy_notice_accepted_at')->nullable()->after('email_verified_at');
            $table->string('privacy_notice_version')->nullable()->after('privacy_notice_accepted_at');
            $table->string('privacy_notice_ip', 45)->nullable()->after('privacy_notice_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_notice_accepted_at', 'privacy_notice_version', 'privacy_notice_ip']);
        });
    }
};
