<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('training_batches', function (Blueprint $table) {
            $table->string('sector')->nullable();
            $table->string('trainer')->nullable();
            $table->string('trainer_phone', 20)->nullable();
        });
        Schema::create('candidate_batch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_batch_id')->nullable()->constrained('training_batches')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_batch_assignments');
        Schema::table('training_batches', fn (Blueprint $table) => $table->dropColumn(['sector', 'trainer', 'trainer_phone']));
    }
};
