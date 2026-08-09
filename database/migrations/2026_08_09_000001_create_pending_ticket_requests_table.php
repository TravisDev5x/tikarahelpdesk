<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_ticket_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->string('reason', 20); // unregistered | inactive | wrong_tenant
            $table->foreignId('matched_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin_message_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('resulting_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            // NULLs son distintos en Postgres/MySQL -- reintentos de webhook sin
            // origin_message_id no colisionan entre sí; los que sí lo traen
            // quedan protegidos contra duplicados, mismo patrón que
            // 2026_08_06_000001_add_unique_origin_message_id_to_tickets.php.
            $table->unique(['client_id', 'origin_message_id'], 'pending_ticket_requests_client_message_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_ticket_requests');
    }
};
