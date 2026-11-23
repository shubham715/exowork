<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            $table->unsignedInteger('profile_credits')->default(100);
            $table->unsignedInteger('profile_credits_used')->default(0);
        });
        Schema::create('hiring_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('default_profile_credits');
        });
        Schema::create('job_candidate_profiles', function (Blueprint $table) {
            $table->foreignId('job_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('shortlisted_at')->nullable();
            $table->primary(['job_post_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_candidate_profiles');
        Schema::dropIfExists('hiring_settings');
        Schema::table('job_posts', fn (Blueprint $table) => $table->dropColumn(['profile_credits', 'profile_credits_used']));
    }
};
