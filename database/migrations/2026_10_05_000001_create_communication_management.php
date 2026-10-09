<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_settings', function (Blueprint $table) {
            $table->string('channel')->primary();
            $table->longText('payload');
            $table->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('system_key', 40)->nullable()->unique();
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        foreach ([
            ['system_key' => null, 'name' => 'General update', 'subject' => 'An update from {{app_name}}', 'body' => "Hello {{name}},\n\nWe have an update for you.\n\nRegards,\n{{app_name}}"],
            ['system_key' => 'candidate_invitation', 'name' => 'Candidate registration invitation', 'subject' => 'You are invited to register with {{center_name}} | {{app_name}}', 'body' => "{{center_name}} invites you to create your candidate profile on {{app_name}}.\n\nRegister your profile: {{registration_url}}\n\nYour registration will be linked to this training center with Ongoing training status. Choose your batch, complete your profile and review your consent preferences.\n\nIf you were not expecting this invitation, you can ignore this email."],
            ['system_key' => 'password_reset', 'name' => 'Employer password reset', 'subject' => 'Reset your {{app_name}} password', 'body' => "Hello {{name}},\n\nWe received a password reset request for your account.\n\nReset your password: {{reset_url}}\n\nThis link expires in {{expiry_minutes}} minutes.\n\nIf you did not request this reset, you can ignore this email."],
        ] as $template) {
            DB::table('email_templates')->insert([...$template, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('status', 20)->default('pending');
            $table->string('recipient_type', 30);
            $table->unsignedBigInteger('recipient_id');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('communication_settings');
    }
};
