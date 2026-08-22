<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->text('config')->nullable();
            $table->string('status', 20)->default('not_configured');
            $table->text('status_message')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_integrations');
    }
};
