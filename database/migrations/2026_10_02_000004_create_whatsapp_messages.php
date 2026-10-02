<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employer_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('recipient', 15);
            $table->string('template_name');
            $table->string('language', 20);
            $table->string('category', 30);
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('status', 30)->default('submitting');
            $table->unsignedBigInteger('status_timestamp')->default(0);
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
            $table->index(['candidate_id', 'created_at']);
        });
        // Receipts may arrive before the outbound HTTP response is saved.
        Schema::create('whatsapp_receipts', function (Blueprint $table) {
            $table->string('provider_message_id')->primary();
            $table->string('status', 30);
            $table->unsignedBigInteger('status_timestamp');
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_receipts');
        Schema::dropIfExists('whatsapp_messages');
    }
};
