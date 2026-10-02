<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('placement_outcomes', fn (Blueprint $table) => $table->date('expected_joining_on')->nullable()->index()); }
    public function down(): void { Schema::table('placement_outcomes', fn (Blueprint $table) => $table->dropColumn('expected_joining_on')); }
};
