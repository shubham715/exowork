<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('applications', fn (Blueprint $t) => $t->json('screening_answers')->nullable()); }
    public function down(): void { Schema::table('applications', fn (Blueprint $t) => $t->dropColumn('screening_answers')); }
};
