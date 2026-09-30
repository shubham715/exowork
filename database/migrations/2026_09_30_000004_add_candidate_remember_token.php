<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', fn (Blueprint $table) => $table->rememberToken());
    }

    public function down(): void
    {
        Schema::table('candidates', fn (Blueprint $table) => $table->dropRememberToken());
    }
};
