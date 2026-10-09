<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach (['users', 'candidates', 'employers', 'training_centers', 'training_partners', 'training_batches', 'job_posts'] as $name) {
            if (!Schema::hasColumn($name, 'demo_run_id')) Schema::table($name, fn (Blueprint $t) => $t->uuid('demo_run_id')->nullable()->index());
        }
        Schema::create('demo_credentials', function (Blueprint $t) {
            $t->id(); $t->string('section'); $t->string('entity_table'); $t->unsignedBigInteger('entity_id');
            $t->string('name'); $t->string('email'); $t->text('password'); $t->uuid('demo_run_id'); $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('demo_credentials');
        foreach (['users', 'candidates', 'employers', 'training_centers', 'training_partners', 'training_batches', 'job_posts'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('demo_run_id'));
        }
    }
};

