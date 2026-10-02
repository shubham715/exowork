<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('admin_review_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('profile_version', 40);
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'subject_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('admin_review_reads'); }
};
