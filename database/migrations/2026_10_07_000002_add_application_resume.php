<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('applications', function (Blueprint $table) {
        $table->string('resume_path')->nullable();
        $table->string('resume_source', 20)->nullable();
        $table->string('resume_name')->nullable();
    }); }
    public function down(): void { Schema::table('applications', fn (Blueprint $table) => $table->dropColumn(['resume_path', 'resume_source', 'resume_name'])); }
};
