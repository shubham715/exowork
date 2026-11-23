<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->longText('import_data')->nullable();
            $table->string('import_source')->nullable();
            $table->unsignedTinyInteger('age')->nullable()->change();
            $table->unsignedInteger('expected_monthly_salary')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('candidates', fn (Blueprint $table) => $table->dropColumn(['import_data', 'import_source']));
    }
};
