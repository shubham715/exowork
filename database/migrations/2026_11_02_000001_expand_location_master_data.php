<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\CandidateMasterDataSeeder)->run();
    }

    public function down(): void
    {
        // Shared choices may already be referenced by saved profiles.
    }
};
