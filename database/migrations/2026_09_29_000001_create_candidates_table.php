<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('candidate_code')->nullable()->unique();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('whatsapp', 15)->unique();
            $table->string('alternate_mobile', 15)->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('gender', 24);
            $table->unsignedTinyInteger('age');
            $table->string('qualification', 60);
            $table->string('state', 80);
            $table->string('district', 80);
            $table->string('current_location');
            $table->string('permanent_location');
            $table->json('preferred_locations');
            $table->string('relocation_preference', 40);
            $table->text('skills');
            $table->string('experience_type', 20);
            $table->text('experience_details')->nullable();
            $table->unsignedInteger('expected_monthly_salary');
            $table->string('availability', 30);
            $table->string('preferred_industry', 60);
            $table->json('languages');
            $table->string('training_status', 20);
            $table->string('training_partner')->nullable();
            $table->string('training_center')->nullable();
            $table->string('batch_code')->nullable();
            $table->date('batch_end_date')->nullable();
            $table->text('aadhaar_number')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('certificate_path')->nullable();
            $table->boolean('location_consent')->default(false);
            $table->boolean('photo_consent')->default(false);
            $table->boolean('call_consent')->default(false);
            $table->boolean('sms_consent')->default(false);
            $table->boolean('whatsapp_consent')->default(false);
            $table->boolean('email_consent')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('consent_notice_version', 20)->default('1.0');
            $table->string('registration_source', 20)->default('public');
            $table->ipAddress('registration_ip')->nullable();
            $table->timestamps();

            $table->index(['district', 'availability']);
            $table->index(['preferred_industry', 'training_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
