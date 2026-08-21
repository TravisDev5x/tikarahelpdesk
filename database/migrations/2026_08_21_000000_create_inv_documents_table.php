<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de Inventario, fase 2.2 (ITAM -- documentos y evidencias).
 * Polimórfica (documentable_type/id) para no bloquear adjuntar a otros
 * modelos (ej. InvMaintenance) sin migración nueva el día que se pida --
 * en este pase solo InvAsset la usa (ver InvAssetDocumentController).
 *
 * disk = 'local' desde el día uno (storage/app/private, sin symlink
 * público) -- misma lección ya aplicada a inv_asset_images en fase 1, que
 * antes vivía en disco 'public' sin control de acceso al archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_documents', function (Blueprint $table) {
            $table->id();
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('type', 40);
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_documents');
    }
};
