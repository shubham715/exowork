<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('organization', 160)->nullable();
            $table->string('phone', 10);
            $table->string('audience', 24);
            $table->text('message')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_enquiries');
    }
};
