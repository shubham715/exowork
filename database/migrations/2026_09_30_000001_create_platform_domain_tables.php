<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed')->default(true);
            $table->primary(['user_id', 'permission_id']);
        });

        Schema::create('training_partners', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('district', 80)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('training_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_partner_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('state', 80);
            $table->string('district', 80);
            $table->text('address')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['training_partner_id', 'status']);
        });
        Schema::create('employers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('industry', 80)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_partner_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('training_center_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('membership_role', 40)->default('member');
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('training_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_center_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('job_role');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 30)->default('planned');
            $table->timestamps();
            $table->unique(['training_center_id', 'code']);
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('training_partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('profile_status', 30)->default('active')->index();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->softDeletes();
            $table->index(['training_center_id', 'training_batch_id']);
        });
        Schema::create('candidate_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 80);
            $table->boolean('granted');
            $table->string('notice_version', 20);
            $table->string('source', 30);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['candidate_id', 'purpose', 'recorded_at']);
        });
        Schema::create('candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['candidate_id', 'type']);
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('industry', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('district', 80)->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('openings')->default(1);
            $table->unsignedInteger('salary_min')->nullable();
            $table->unsignedInteger('salary_max')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['district', 'status']);
        });
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_post_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('interested')->index();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['candidate_id', 'job_post_id']);
        });
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('mode', 30)->nullable();
            $table->string('location')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'scheduled_at']);
        });
        Schema::create('candidate_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 30);
            $table->string('status', 30)->default('open');
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status', 'due_at']);
        });
        Schema::create('placement_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->date('offered_on')->nullable();
            $table->date('joined_on')->nullable();
            $table->unsignedInteger('monthly_salary')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->json('changes')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'placement_outcomes', 'candidate_followups', 'interviews', 'applications', 'job_posts', 'candidate_documents', 'candidate_consents'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('training_partner_id');
            $table->dropConstrainedForeignId('training_center_id');
            $table->dropConstrainedForeignId('training_batch_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('assigned_agent_id');
            $table->dropColumn(['profile_status', 'phone_verified_at', 'last_active_at', 'deleted_at']);
        });
        foreach (['training_batches', 'organization_memberships', 'employers', 'training_centers', 'training_partners', 'user_permissions', 'user_roles', 'role_permissions', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
