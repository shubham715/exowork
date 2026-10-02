<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('job_engagement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_post_id')->constrained('job_posts')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('type', 20);
            $table->date('event_date');
            $table->timestamp('created_at');
            $table->index(['job_post_id', 'event_date', 'type']);
        });
        Schema::create('job_candidate_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_post_id')->constrained('job_posts')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['job_post_id', 'candidate_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('job_candidate_skips');
        Schema::dropIfExists('job_engagement_events');
    }
};
