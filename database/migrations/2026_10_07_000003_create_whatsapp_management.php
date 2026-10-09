<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_templates')) Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            $t->string('system_key')->unique();
            $t->string('name');
            $t->string('language', 20);
            $t->string('category', 30);
            $t->text('body');
            $t->string('review_status', 20)->default('pending');
            $t->string('meta_status', 30)->default('NOT_SUBMITTED');
            $t->string('meta_id')->nullable();
            $t->string('account_id', 40)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
        foreach (\App\Services\WhatsAppTemplateCatalog::all() as $row) {
            DB::table('whatsapp_templates')->insertOrIgnore(['system_key' => $row['key'], 'name' => $row['name'], 'language' => $row['language'], 'category' => $row['category'], 'body' => $row['body'], 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! Schema::hasColumn('whatsapp_messages', 'payload')) Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->unsignedBigInteger('candidate_id')->nullable()->change();
            $t->text('payload')->nullable(); // Encrypted variables required for queue and resend.
            $t->string('source', 80)->default('manual');
            $t->string('event_key', 191)->nullable()->unique();
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedBigInteger('retry_of')->nullable()->index();
            $t->unsignedBigInteger('training_center_id')->nullable()->index();
            $t->timestamp('available_at')->nullable()->index();
            $t->text('error_message')->nullable();
            $t->index(['status', 'created_at']);
        });
        if (! Schema::hasTable('whatsapp_opt_outs')) Schema::create('whatsapp_opt_outs', function (Blueprint $t) {
            $t->string('recipient', 15)->primary();
            $t->timestamps();
        });
        if (! Schema::hasTable('whatsapp_center_preferences')) Schema::create('whatsapp_center_preferences', function (Blueprint $t) {
            $t->foreignId('training_center_id')->primary()->constrained()->cascadeOnDelete();
            $t->boolean('consent')->default(false);
            $t->timestamps();
        });
        if (! Schema::hasColumn('candidates', 'job_interest_status')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE candidates ADD job_interest_status VARCHAR(30) NOT NULL DEFAULT 'no_response', ALGORITHM=INSTANT");
                Schema::table('candidates', fn (Blueprint $t) => $t->index('job_interest_status'));
            } else Schema::table('candidates', fn (Blueprint $t) => $t->string('job_interest_status', 30)->default('no_response')->index());
        }
        if (! Schema::hasColumn('applications', 'interview_availability')) {
            if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE applications ADD interview_availability VARCHAR(30) NULL, ALGORITHM=INSTANT');
            else Schema::table('applications', fn (Blueprint $t) => $t->string('interview_availability', 30)->nullable());
        }
        if (! Schema::hasColumn('interviews', 'attendance_response')) Schema::table('interviews', fn (Blueprint $t) => $t->string('attendance_response', 30)->nullable());
        if (! Schema::hasColumn('candidate_followups', 'workflow_key')) Schema::table('candidate_followups', fn (Blueprint $t) => $t->string('workflow_key', 191)->nullable()->unique());
        if (! Schema::hasTable('retention_responses')) Schema::create('retention_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('placement_outcome_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('day');
            $t->string('status', 30);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['placement_outcome_id', 'day']);
        });
        if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE interviews MODIFY scheduled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        DB::table('communication_settings')->insertOrIgnore(['channel' => 'whatsapp_workflow', 'payload' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['start_at' => now()->toDateTimeString()])), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_responses');
        Schema::table('candidate_followups', function (Blueprint $t) { $t->dropUnique(['workflow_key']); $t->dropColumn('workflow_key'); });
        Schema::table('interviews', fn (Blueprint $t) => $t->dropColumn('attendance_response'));
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn('interview_availability'));
        Schema::table('candidates', function (Blueprint $t) { $t->dropIndex(['job_interest_status']); $t->dropColumn('job_interest_status'); });
        Schema::dropIfExists('whatsapp_center_preferences');
        Schema::dropIfExists('whatsapp_opt_outs');
        Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->dropUnique(['event_key']);
            $t->dropIndex(['status', 'created_at']);
            $t->dropIndex(['retry_of']);
            $t->dropIndex(['training_center_id']);
            $t->dropIndex(['available_at']);
            $t->dropColumn(['payload', 'source', 'event_key', 'attempts', 'retry_of', 'training_center_id', 'available_at', 'error_message']);
        });
        Schema::dropIfExists('whatsapp_templates');
    }
};
