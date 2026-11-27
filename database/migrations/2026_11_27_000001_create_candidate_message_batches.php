<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_message_batches', function (Blueprint $table) {
            $table->id('audit_id');
            $table->uuid('id')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->string('channel', 12);
            $table->string('status', 20)->default('preview');
            $table->longText('payload');
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::table('email_deliveries', fn (Blueprint $table) => $table->longText('rendered_payload')->nullable());
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_message_batches');
        Schema::table('email_deliveries', fn (Blueprint $table) => $table->dropColumn('rendered_payload'));
    }
};
