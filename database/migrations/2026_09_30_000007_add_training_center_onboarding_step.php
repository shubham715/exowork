<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_centers', function (Blueprint $table) {
            $table->unsignedTinyInteger('onboarding_step')->default(3)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('training_centers', fn (Blueprint $table) => $table->dropColumn('onboarding_step'));
    }
};
