<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('training_centers', function (Blueprint $table) { $table->unsignedBigInteger('training_partner_id')->nullable()->change(); }); }
    public function down(): void { /* Independent centers must remain valid when rolling back. */ }
};
