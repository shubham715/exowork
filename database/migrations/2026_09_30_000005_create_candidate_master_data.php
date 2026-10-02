<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('slug', 100);
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'slug']);
            $table->index(['type', 'is_active', 'sort_order']);
        });
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->string('slug', 100);
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['state_id', 'slug']);
            $table->index(['state_id', 'is_active', 'sort_order']);
        });
        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('qualification_option_id')->nullable()->constrained('master_options')->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('district_is_custom')->default(false);
            $table->foreignId('experience_level_option_id')->nullable()->constrained('master_options')->nullOnDelete();
            $table->foreignId('industry_option_id')->nullable()->constrained('master_options')->nullOnDelete();
        });
        Schema::create('candidate_languages', function (Blueprint $table) {
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_option_id')->constrained('master_options')->restrictOnDelete();
            $table->primary(['candidate_id', 'language_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_languages');
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qualification_option_id');
            $table->dropConstrainedForeignId('state_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('experience_level_option_id');
            $table->dropConstrainedForeignId('industry_option_id');
            $table->dropColumn('district_is_custom');
        });
        Schema::dropIfExists('districts');
        Schema::dropIfExists('states');
        Schema::dropIfExists('master_options');
    }
};
