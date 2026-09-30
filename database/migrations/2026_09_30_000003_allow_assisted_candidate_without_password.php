<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', fn (Blueprint $table) => $table->string('password')->nullable()->change());
    }

    public function down(): void
    {
        // Assisted candidates may have no password; forcing NOT NULL would lose those records.
    }
};
