<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->string('provider', 20)->default('meta');
            $t->string('provider_account_id', 80)->nullable();
        });
        Schema::create('whatsapp_provider_aliases', function (Blueprint $t) {
            $t->string('meta_message_id')->primary();
            $t->string('provider_message_id')->index();
            $t->string('account_id', 80);
        });
        Schema::create('whatsapp_inbound_messages', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20);
            $t->string('account_id', 80);
            $t->string('provider_message_id');
            $t->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $t->string('sender', 15)->index();
            $t->string('type', 30);
            $t->text('content'); // Encrypted; do not store the raw provider payload.
            $t->string('context_id')->nullable();
            $t->unsignedBigInteger('message_timestamp');
            $t->string('outcome', 40)->default('needs_review');
            $t->timestamps();
            $t->unique(['account_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_messages');
        Schema::dropIfExists('whatsapp_provider_aliases');
        Schema::table('whatsapp_messages', fn (Blueprint $t) => $t->dropColumn(['provider', 'provider_account_id']));
    }
};
