<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Candidate;
use App\Models\User;
use App\Services\WhatsAppOutbox;
use App\Services\WhatsAppTemplateCatalog;
use App\Services\WhatsAppWorkflow;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformRolesSeeder::class);
        $this->admin = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($this->admin->refresh());
        config(['whatsapp.enabled' => true, 'whatsapp.api_version' => 'v26.0', 'whatsapp.access_token' => 'secret-token', 'whatsapp.phone_number_id' => '123', 'whatsapp.business_account_id' => '456', 'whatsapp.app_secret' => 'app-secret', 'whatsapp.verify_token' => 'verify-secret']);
        Http::preventStrayRequests();
    }

    private function meta(string $status = 'APPROVED', ?string $body = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*456/message_templates*' => Http::response(['data' => array_map(fn ($t) => ['id' => (string) (100 + array_search($t, WhatsAppTemplateCatalog::all())), 'name' => $t['name'], 'language' => $t['language'], 'category' => $t['category'], 'status' => $status, 'components' => [['type' => 'BODY', 'text' => $body ?? $t['body']]]], WhatsAppTemplateCatalog::all())]),
            '*123/messages' => Http::response(['messages' => [['id' => 'wamid.'.Str::uuid()]]]),
        ]);
    }

    private function candidate(array $overrides = []): Candidate
    {
        return Candidate::forceCreate(array_replace(['candidate_code' => 'EXO-CAN-000001', 'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211', 'gender' => 'Female', 'age' => 24, 'qualification' => 'ITI', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'current_location' => 'Jaipur', 'permanent_location' => 'Jaipur', 'preferred_locations' => ['Jaipur'], 'relocation_preference' => 'No', 'skills' => 'Machines', 'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000, 'availability' => 'Yes', 'preferred_industry' => 'Manufacturing', 'languages' => ['Hindi'], 'training_status' => 'Not enrolled', 'whatsapp_consent' => true], $overrides))->refresh();
    }

    private function enable(string $key = 'welcome'): object
    {
        $t = DB::table('whatsapp_templates')->where('system_key', $key)->first();
        DB::table('whatsapp_templates')->where('id', $t->id)->update(['review_status' => 'approved', 'meta_status' => 'APPROVED', 'account_id' => '456']);
        return DB::table('whatsapp_templates')->find($t->id);
    }

    public function test_library_is_available_without_meta_credentials_and_requires_admin_role(): void
    {
        config(['whatsapp.enabled' => false]);
        $this->getJson('/admin-api/whatsapp/templates')->assertOk()->assertJsonCount(14, 'templates')->assertJsonPath('configured', false);
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create())->getJson('/admin-api/whatsapp/templates')->assertForbidden();
    }

    public function test_admin_cannot_fake_meta_approval_or_approve_changed_content(): void
    {
        $t = DB::table('whatsapp_templates')->first();
        $this->meta('PENDING');
        $this->patchJson('/admin-api/whatsapp/templates/'.$t->id.'/review', ['status' => 'approved'])->assertUnprocessable();
        $this->meta('APPROVED', 'Changed content');
        $this->patchJson('/admin-api/whatsapp/templates/'.$t->id.'/review', ['status' => 'approved'])->assertUnprocessable();
        $this->assertDatabaseHas('whatsapp_templates', ['id' => $t->id, 'meta_status' => 'CONTENT_MISMATCH', 'review_status' => 'pending']);
    }

    public function test_sync_approval_rejection_and_account_switch_are_audited(): void
    {
        $this->meta(); $t = DB::table('whatsapp_templates')->first();
        $this->patchJson('/admin-api/whatsapp/templates/'.$t->id.'/review', ['status' => 'approved'])->assertOk();
        $this->assertDatabaseHas('whatsapp_templates', ['id' => $t->id, 'review_status' => 'approved', 'meta_status' => 'APPROVED']);
        DB::table('whatsapp_templates')->where('id', $t->id)->update(['account_id' => '999']);
        $this->postJson('/admin-api/whatsapp/sync')->assertOk();
        $this->assertDatabaseHas('whatsapp_templates', ['id' => $t->id, 'review_status' => 'pending']);
        $this->patchJson('/admin-api/whatsapp/templates/'.$t->id.'/review', ['status' => 'rejected'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.template_rejected']);
    }

    public function test_template_test_is_queued_encrypted_and_idempotent(): void
    {
        Queue::fake(); $this->meta(); $t = $this->enable();
        $payload = ['request_id' => (string) Str::uuid(), 'number' => '+91 98765 43210', 'consent' => true, 'parameters' => ['Asha', 'https://example.com/candidate/consent']];
        $this->postJson('/admin-api/whatsapp/templates/'.$t->id.'/test', $payload)->assertAccepted()->assertJsonPath('message.status', 'queued')->assertJsonMissingPath('message.payload');
        $this->postJson('/admin-api/whatsapp/templates/'.$t->id.'/test', $payload)->assertOk();
        $this->assertDatabaseCount('whatsapp_messages', 1);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        $r = DB::table('whatsapp_messages')->first();
        $this->assertStringNotContainsString('Asha', $r->payload);
        $this->assertSame($payload['parameters'], json_decode(Crypt::decryptString($r->payload), true)['parameters']);
        Http::assertSentCount(1); // approval lookup only; no outbound send in the page request
    }

    public function test_test_validates_consent_and_variables(): void
    {
        $this->meta(); $t = $this->enable();
        $payload = ['request_id' => (string) Str::uuid(), 'number' => '9876543210', 'consent' => false, 'parameters' => ['Asha']];
        $this->postJson('/admin-api/whatsapp/templates/'.$t->id.'/test', $payload)->assertUnprocessable();
        $this->postJson('/admin-api/whatsapp/templates/'.$t->id.'/test', [...$payload, 'consent' => true])->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_worker_rechecks_consent_and_claims_a_message_once(): void
    {
        Queue::fake(); $this->meta(); $t = $this->enable(); $c = $this->candidate();
        $r = app(WhatsAppOutbox::class)->enqueue('919876543211', (array) $t, ['Asha', 'https://example.com'], ['candidate_id' => $c->id]);
        $c->update(['whatsapp_consent' => false]);
        app()->call([new SendWhatsAppMessage($r->id), 'handle']);
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $r->id, 'status' => 'skipped']);
        Http::assertNothingSent();
        $c->update(['whatsapp_consent' => true]);
        $this->travel(7)->seconds();
        $r2 = app(WhatsAppOutbox::class)->enqueue('919876543211', (array) $t, ['Asha', 'https://example.com'], ['candidate_id' => $c->id]);
        app()->call([new SendWhatsAppMessage($r2->id), 'handle']);
        app()->call([new SendWhatsAppMessage($r2->id), 'handle']);
        Http::assertSentCount(2); // one approval lookup, one send
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $r2->id, 'status' => 'accepted', 'attempts' => 1]);
    }

    public function test_failed_resend_preserves_history_and_cannot_duplicate_or_resend_unknown(): void
    {
        Queue::fake(); $t = $this->enable();
        $r = app(WhatsAppOutbox::class)->enqueue('919876543210', (array) $t, ['Asha', 'https://example.com']);
        DB::table('whatsapp_messages')->where('id', $r->id)->update(['status' => 'failed']);
        $this->postJson('/admin-api/whatsapp/resend', ['ids' => [$r->id]])->assertAccepted()->assertJsonPath('results.0.queued', true);
        $this->postJson('/admin-api/whatsapp/resend', ['ids' => [$r->id]])->assertAccepted()->assertJsonPath('results.0.queued', false);
        $this->assertDatabaseCount('whatsapp_messages', 2);
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $r->id, 'status' => 'failed']);
        DB::table('whatsapp_messages')->where('id', $r->id)->update(['status' => 'unknown']);
        $this->postJson('/admin-api/whatsapp/resend', ['ids' => [$r->id]])->assertAccepted()->assertJsonPath('results.0.queued', false);
    }

    public function test_logs_filter_paginate_and_never_expose_encrypted_variables(): void
    {
        Queue::fake(); $t = $this->enable();
        $r = app(WhatsAppOutbox::class)->enqueue('919876543210', (array) $t, ['private-variable', 'https://example.com'], ['source' => 'test']);
        $this->getJson('/admin-api/whatsapp/logs?status=queued&search=9876543210')->assertOk()->assertJsonPath('logs.total', 1)->assertJsonMissingPath('logs.data.0.payload')->assertDontSee('private-variable');
        $this->getJson('/admin-api/whatsapp/logs?status=failed')->assertOk()->assertJsonPath('logs.total', 0);
        $this->getJson('/admin-api/whatsapp/logs?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/admin-api/whatsapp/logs/'.$r->id)->assertOk()->assertJsonMissingPath('message.payload');
    }

    public function test_welcome_automation_is_deduplicated_and_ignores_historical_candidates(): void
    {
        Queue::fake(); $this->enable();
        $old = $this->candidate(['created_at' => now()->subYear()]);
        app(WhatsAppWorkflow::class)->run();
        $this->assertDatabaseCount('whatsapp_messages', 0);
        $old->forceFill(['created_at' => now()])->save();
        app(WhatsAppWorkflow::class)->run(); app(WhatsAppWorkflow::class)->run();
        $this->assertDatabaseCount('whatsapp_messages', 1);
        $this->assertDatabaseHas('whatsapp_messages', ['source' => 'welcome', 'event_key' => 'welcome:'.$old->id]);
    }

    public function test_candidate_response_is_scoped_and_creates_followup(): void
    {
        $c = $this->candidate();
        $employer = DB::table('employers')->insertGetId(['name' => 'Acme', 'code' => 'ACME', 'status' => 'verified']);
        $job = DB::table('job_posts')->insertGetId(['employer_id' => $employer, 'title' => 'Operator', 'openings' => 1, 'status' => 'active']);
        $a = DB::table('applications')->insertGetId(['candidate_id' => $c->id, 'job_post_id' => $job]);
        $this->actingAs($c, 'candidate')->postJson('/candidate-api/employment/respond', ['kind' => 'availability', 'id' => $a, 'status' => 'within_3_days'])->assertOk();
        $this->assertDatabaseHas('candidate_followups', ['application_id' => $a, 'workflow_key' => 'availability:'.$a, 'status' => 'open']);
        $this->postJson('/candidate-api/employment/respond', ['kind' => 'availability', 'id' => 999, 'status' => 'later'])->assertNotFound();
        $this->postJson('/candidate-api/employment/respond', ['kind' => 'interest', 'status' => 'interested'])->assertOk();
        $this->assertDatabaseHas('candidates', ['id' => $c->id, 'job_interest_status' => 'interested']);
    }

    public function test_interview_reminders_center_updates_and_retention_are_deduplicated(): void
    {
        Queue::fake();
        foreach (['interview_scheduled', 'interview_reminder', 'center_placement_update', 'joining_update', 'retention_day_7'] as $key) $this->enable($key);
        app(\App\Services\CommunicationSettings::class)->save('whatsapp_workflow', ['start_at' => now()->subDay()->toDateTimeString(), 'preparation_video_url' => 'https://example.com/video']);
        $partner = DB::table('training_partners')->insertGetId(['name' => 'Partner', 'code' => 'TP']);
        $center = DB::table('training_centers')->insertGetId(['training_partner_id' => $partner, 'name' => 'Center', 'code' => 'CTR', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'spoc_phone' => '9876543210']);
        DB::table('whatsapp_center_preferences')->insert(['training_center_id' => $center, 'consent' => true]);
        $c = $this->candidate(['training_center_id' => $center]);
        $employer = DB::table('employers')->insertGetId(['name' => 'Acme', 'code' => 'ACME', 'status' => 'verified', 'contact_name' => 'Recruiter']);
        $job = DB::table('job_posts')->insertGetId(['employer_id' => $employer, 'title' => 'Operator', 'openings' => 1, 'status' => 'active']);
        $a = DB::table('applications')->insertGetId(['candidate_id' => $c->id, 'job_post_id' => $job]);
        DB::table('interviews')->insert(['application_id' => $a, 'scheduled_at' => now('Asia/Kolkata')->addDay()->setTime(11, 0)->utc(), 'location' => 'Office', 'status' => 'scheduled']);
        DB::table('placement_outcomes')->insert(['application_id' => $a, 'status' => 'joined', 'joined_on' => now('Asia/Kolkata')->subDays(7)->toDateString(), 'updated_at' => now()]);
        app(WhatsAppWorkflow::class)->run(); app(WhatsAppWorkflow::class)->run();
        $this->assertDatabaseCount('whatsapp_messages', 6); // interview + reminder + 2 center updates + joining + retention
        $this->assertDatabaseHas('whatsapp_messages', ['source' => 'retention_day_7']);
        $this->assertDatabaseHas('whatsapp_messages', ['source' => 'center_placement_update', 'training_center_id' => $center, 'candidate_id' => null]);
        $scheduled = DB::table('whatsapp_messages')->where('source', 'interview_scheduled')->first();
        $variables = json_decode(Crypt::decryptString($scheduled->payload), true)['parameters'];
        $this->assertStringContainsString('11:00 AM', $variables[3]);
    }

    public function test_admin_saves_real_interviews_and_staff_confirmed_placements(): void
    {
        $c = $this->candidate();
        $employer = DB::table('employers')->insertGetId(['name' => 'Acme', 'code' => 'ACME', 'status' => 'verified']);
        $job = DB::table('job_posts')->insertGetId(['employer_id' => $employer, 'title' => 'Operator', 'openings' => 1]);
        $a = DB::table('applications')->insertGetId(['candidate_id' => $c->id, 'job_post_id' => $job]);
        $this->postJson('/admin-api/employment', ['kind' => 'interview', 'application_id' => $a, 'scheduled_at' => now('Asia/Kolkata')->addDays(2)->format('Y-m-d\TH:i'), 'location' => 'Office', 'status' => 'scheduled'])->assertOk();
        $this->assertDatabaseHas('interviews', ['application_id' => $a, 'status' => 'scheduled']);
        $this->postJson('/admin-api/employment', ['kind' => 'placement', 'application_id' => $a, 'status' => 'joined'])->assertUnprocessable();
        $this->postJson('/admin-api/employment', ['kind' => 'placement', 'application_id' => $a, 'status' => 'joined', 'joined_on' => now('Asia/Kolkata')->toDateString()])->assertOk();
        $this->assertDatabaseHas('placement_outcomes', ['application_id' => $a, 'status' => 'joined', 'verified_by_user_id' => $this->admin->id]);
        $this->getJson('/admin-api/employment')->assertOk()->assertJsonCount(1, 'interviews')->assertJsonCount(1, 'placements');
    }

    public function test_readiness_defers_jobs_until_fifteen_day_boundary(): void
    {
        Queue::fake(); $this->enable('training_reconnect'); $this->enable('career_intro');
        app(\App\Services\CommunicationSettings::class)->save('whatsapp_workflow', ['start_at' => now()->toDateTimeString(), 'career_video_url' => 'https://example.com/video']);
        $c = $this->candidate(['job_interest_status' => 'interested', 'training_status' => 'Ongoing', 'batch_end_date' => now('Asia/Kolkata')->addDays(16)->toDateString()]);
        app(WhatsAppWorkflow::class)->run();
        $this->assertDatabaseHas('whatsapp_messages', ['source' => 'training_reconnect']);
        $this->assertDatabaseMissing('whatsapp_messages', ['source' => 'career_intro']);
        $c->update(['batch_end_date' => now('Asia/Kolkata')->addDays(15)->toDateString()]);
        app(WhatsAppWorkflow::class)->run();
        $this->assertDatabaseHas('whatsapp_messages', ['source' => 'career_intro']);
    }
}
