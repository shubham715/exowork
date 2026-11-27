<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CampaignEmailSender;
use App\Services\CandidateTemplateResolver;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppTemplateCatalog;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CandidateMessagingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformRolesSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($this->admin->refresh());
        config(['mail.default' => 'smtp', 'whatsapp.http_cron_enabled' => true, 'whatsapp.provider' => 'meta', 'whatsapp.enabled' => true, 'whatsapp.api_version' => 'v26.0', 'whatsapp.access_token' => 'test', 'whatsapp.phone_number_id' => '123', 'whatsapp.business_account_id' => '456', 'whatsapp.app_secret' => 'test', 'whatsapp.verify_token' => 'test']);
        Http::fake(['graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [
            ['name' => 'candidate_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, visit {{2}}.']]],
        ]])]);
        $this->candidate = Candidate::forceCreate(['candidate_code' => 'EXO-CAN-000001', 'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211', 'email' => 'asha@example.com', 'gender' => 'Female', 'age' => 24, 'qualification' => '12th pass', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'current_location' => 'Private address', 'permanent_location' => 'Private address', 'preferred_locations' => [], 'relocation_preference' => '', 'skills' => '', 'experience_type' => '', 'expected_monthly_salary' => null, 'availability' => 'No', 'preferred_industry' => '', 'languages' => [], 'training_status' => '', 'email_consent' => true, 'whatsapp_consent' => true]);
    }

    private function preview(string $channel = 'whatsapp', array $extra = [])
    {
        $payload = ['channel' => $channel, 'request_key' => (string) Str::uuid(), 'ids' => [$this->candidate->id], ...($channel === 'email' ? ['subject' => 'Hello {{first_name}}', 'body' => 'Dear {{name}}, ID {{candidate_code}} in {{district}}.'] : ['template_name' => 'candidate_update', 'language' => 'en', 'variables' => [['field' => 'name'], ['field' => 'custom', 'value' => 'our office']]]), ...$extra];

        return $this->postJson('/admin-api/candidate-notifications/preview', $payload);
    }

    public function test_directory_search_and_role_permissions(): void
    {
        $this->getJson('/admin-api/candidate-notifications?search=Asha%20Sharma')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.current_location')->assertJsonMissingPath('data.0.import_data');
        $this->getJson('/admin-api/candidate-notifications?search=none')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/admin-api/candidate-notifications/context?channel=whatsapp')->assertOk()->assertJsonPath('templates.0.name', 'candidate_update');
        $this->actingAs(User::factory()->create());
        $this->getJson('/admin-api/candidate-notifications')->assertForbidden();
        $this->preview()->assertForbidden();
    }

    public function test_whatsapp_preview_resolves_fields_then_sends_once_through_the_existing_outbox(): void
    {
        $preview = $this->preview()->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('rows.0.body', 'Hello Asha Sharma, visit our office.')->json();
        $this->assertDatabaseCount('whatsapp_messages', 0);
        $stored = DB::table('candidate_message_batches')->value('payload');
        $this->assertStringNotContainsString('Asha', $stored);
        $url = '/admin-api/candidate-notifications/'.$preview['id'].'/send';
        $this->postJson($url)->assertOk()->assertJsonPath('queued', 1);
        $this->postJson($url)->assertOk()->assertJsonPath('queued', 1);
        $this->assertDatabaseCount('whatsapp_messages', 1);
        $record = DB::table('whatsapp_messages')->first();
        $this->assertSame('manual', $record->source);
        $this->assertSame($this->admin->id, $record->actor_user_id);
        $this->assertSame(['Asha Sharma', 'our office'], json_decode(Crypt::decryptString($record->payload), true)['parameters']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_invalid_recipients_are_visible_in_preview_and_consent_is_checked_again(): void
    {
        $other = $this->candidate->replicate(['candidate_code']);
        $other->forceFill(['candidate_code' => 'EXO-CAN-000002', 'whatsapp' => '9876543212', 'email' => 'other@example.com', 'whatsapp_consent' => false])->save();
        $preview = $this->preview('whatsapp', ['ids' => [$this->candidate->id, $other->id]])->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('skipped', 1)->json();
        $this->candidate->update(['whatsapp_consent' => false]);
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertOk()->assertJsonPath('queued', 0)->assertJsonPath('skipped', 2);
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_email_uses_previewed_personalization_and_existing_delivery_history(): void
    {
        $preview = $this->preview('email')->assertOk()->assertJsonPath('rows.0.subject', 'Hello Asha')->assertJsonPath('rows.0.body', 'Dear Asha Sharma, ID EXO-CAN-000001 in Jaipur.')->json();
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertOk()->assertJsonPath('queued', 1);
        $this->assertDatabaseCount('email_campaigns', 1);
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->candidate->update(['first_name' => 'Changed']);
        Mail::shouldReceive('raw')->once()->withArgs(function ($body, $callback) {
            return $body === 'Dear Asha Sharma, ID EXO-CAN-000001 in Jaipur.' && is_callable($callback);
        });
        app(CampaignEmailSender::class)->process(DB::table('email_campaigns')->value('id'));
        $this->assertDatabaseHas('email_deliveries', ['status' => 'accepted']);
        app(CampaignEmailSender::class)->process(DB::table('email_campaigns')->value('id'));
    }

    public function test_unknown_placeholders_missing_values_and_contact_changes_do_not_send(): void
    {
        $this->preview('email', ['body' => 'Hello {{password}}'])->assertUnprocessable();
        $this->preview('email', ['body' => 'Hello {{training_center}}'])->assertOk()->assertJsonPath('ready', 0)->assertJsonPath('skipped', 1);
        $preview = $this->preview('email')->assertOk()->json();
        $this->candidate->update(['email' => 'changed@example.com']);
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertOk()->assertJsonPath('queued', 0)->assertJsonPath('skipped', 1);
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_email_processing_is_idempotent_and_rechecks_consent_at_delivery(): void
    {
        $preview = $this->preview('email')->assertOk()->json();
        $url = '/admin-api/candidate-notifications/'.$preview['id'];
        $this->postJson($url.'/send')->assertOk();
        $this->candidate->update(['email_consent' => false]);
        Mail::partialMock()->shouldNotReceive('raw');
        $this->postJson($url.'/process')->assertOk()->assertJsonPath('rows.0.status', 'skipped');
        $this->postJson($url.'/process')->assertOk()->assertJsonPath('rows.0.status', 'skipped');
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_a_changed_template_requires_a_new_preview(): void
    {
        $preview = $this->preview()->assertOk()->json();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [
            ['name' => 'candidate_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Different {{1}}, visit {{2}}.']]],
        ]])]);
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_template_changes_after_queueing_cannot_send_different_content(): void
    {
        $preview = $this->preview()->assertOk()->json();
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [
            ['name' => 'candidate_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Changed {{1}}, visit {{2}}.']]],
        ]])]);
        (new SendWhatsAppMessage(DB::table('whatsapp_messages')->value('id')))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'failed', 'error_code' => 'TEMPLATE_NOT_APPROVED']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_email_permissions_and_opt_outs_are_respected(): void
    {
        DB::table('whatsapp_opt_outs')->insert(['recipient' => '919876543211', 'created_at' => now(), 'updated_at' => now()]);
        $this->preview()->assertOk()->assertJsonPath('ready', 0);
        DB::table('user_permissions')->insert(['user_id' => $this->admin->id, 'permission_id' => DB::table('permissions')->where('key', 'masters.manage')->value('id'), 'allowed' => false]);
        $this->preview('email')->assertForbidden();
        $this->getJson('/admin-api/candidate-notifications')->assertOk()->assertJsonPath('can_email', false);
    }

    public function test_batches_are_bound_to_the_sender_and_expire(): void
    {
        $preview = $this->preview()->assertOk()->json();
        DB::table('candidate_message_batches')->where('id', $preview['id'])->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertUnprocessable();
        $other = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $other->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($other->refresh())->getJson('/admin-api/candidate-notifications/'.$preview['id'])->assertNotFound();
    }

    private function systemTemplate(string $key): array
    {
        $spec = WhatsAppTemplateCatalog::get($key);
        DB::table('whatsapp_templates')->where('system_key', $key)->update(['name' => $spec['name'], 'language' => $spec['language'], 'body' => $spec['body'], 'review_status' => 'approved', 'meta_status' => 'APPROVED', 'account_id' => '456']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [
            ['name' => $spec['name'], 'language' => $spec['language'], 'category' => $spec['category'], 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => $spec['body']]]],
        ]])]);

        return ['template_name' => $spec['name'], 'language' => $spec['language'], 'variables' => []];
    }

    private function selectedJob(): int
    {
        $employer = DB::table('employers')->insertGetId(['code' => 'EMP-TEMPLATE', 'name' => 'Sample Industries', 'status' => 'verified', 'contact_name' => 'Hiring coordinator', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('job_posts')->insertGetId(['employer_id' => $employer, 'title' => 'Sample Industries - Warehouse Assistant', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'salary_min' => 18000, 'salary_max' => 22000, 'status' => 'active', 'application_deadline' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_job_template_requires_job_and_fills_all_five_values_from_real_records(): void
    {
        $job = $this->selectedJob();
        $template = $this->systemTemplate('job_opportunity');
        $this->getJson('/admin-api/candidate-notifications/context?channel=whatsapp')->assertOk()->assertJsonPath('templates.0.requires_job', true);
        $this->getJson('/admin-api/candidate-notifications/jobs')->assertOk()->assertJsonPath('jobs.0.role', 'Warehouse Assistant');
        $this->preview('whatsapp', $template)->assertUnprocessable();
        $preview = $this->preview('whatsapp', $template + ['job_id' => $job])->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('rows.0.parameters.0', 'Asha Sharma')->assertJsonPath('rows.0.parameters.1', 'Warehouse Assistant')->assertJsonPath('rows.0.parameters.2', 'Jaipur')->assertJsonPath('rows.0.parameters.3', 'INR 18,000 - 22,000')->json();
        $this->assertStringNotContainsString('Sample Industries', $preview['rows'][0]['body']);
        DB::table('job_posts')->where('id', $job)->update(['status' => 'paused']);
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_every_candidate_system_template_has_an_explicit_context_plan(): void
    {
        $resolver = app(CandidateTemplateResolver::class);
        foreach (WhatsAppTemplateCatalog::all() as $spec) {
            if ($spec['authentication']) {
                continue;
            }
            $plan = $resolver->describe(['name' => $spec['name'], 'language' => $spec['language'], 'body' => $spec['body'], 'parameter_count' => count($spec['labels'])]);
            $this->assertSame($spec['key'] === 'center_placement_update' ? 'unsupported' : 'automatic', $plan['context_mode'], $spec['key']);
            $this->assertCount(count($spec['labels']), $plan['labels']);
        }
    }

    public function test_profile_training_and_video_templates_ask_only_for_required_inputs(): void
    {
        $welcome = $this->systemTemplate('welcome');
        $this->preview('whatsapp', $welcome)->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('rows.0.parameters.0', 'Asha Sharma');
        $training = $this->systemTemplate('training_reconnect');
        $this->preview('whatsapp', $training)->assertOk()->assertJsonPath('ready', 0);
        $this->candidate->update(['batch_end_date' => now()->addMonth()->toDateString()]);
        $this->preview('whatsapp', $training)->assertOk()->assertJsonPath('ready', 1);
        $career = $this->systemTemplate('career_intro');
        $this->preview('whatsapp', $career)->assertUnprocessable();
        $this->preview('whatsapp', $career + ['inputs' => ['career_video_url' => 'https://example.com/career']])->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('rows.0.parameters.1', 'https://example.com/career');
    }

    public function test_interview_templates_resolve_each_recipients_records_and_reject_ambiguous_schedules(): void
    {
        $job = $this->selectedJob();
        $template = $this->systemTemplate('interview_availability') + ['job_id' => $job];
        $this->preview('whatsapp', $template)->assertOk()->assertJsonPath('ready', 0);
        $application = DB::table('applications')->insertGetId(['candidate_id' => $this->candidate->id, 'job_post_id' => $job, 'status' => 'interested', 'created_at' => now(), 'updated_at' => now()]);
        $this->preview('whatsapp', $template)->assertOk()->assertJsonPath('ready', 1);
        $template = $this->systemTemplate('interview_scheduled') + ['job_id' => $job];
        $this->preview('whatsapp', $template)->assertOk()->assertJsonPath('ready', 0);
        $interview = DB::table('interviews')->insertGetId(['application_id' => $application, 'scheduled_at' => now()->addDays(2), 'location' => 'Jaipur office', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        $preview = $this->preview('whatsapp', $template)->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('rows.0.parameters.2', 'Sample Industries')->assertJsonPath('rows.0.parameters.4', 'Jaipur office')->json();
        DB::table('interviews')->insert(['application_id' => $application, 'scheduled_at' => now()->addDays(3), 'location' => 'Other office', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        $this->preview('whatsapp', $template)->assertOk()->assertJsonPath('ready', 0);
        DB::table('interviews')->where('id', '!=', $interview)->update(['status' => 'cancelled']);
        $reminder = $this->systemTemplate('interview_reminder') + ['job_id' => $job];
        $this->preview('whatsapp', $reminder)->assertOk()->assertJsonPath('ready', 1);
        $this->systemTemplate('interview_scheduled');
        DB::table('interviews')->where('id', $interview)->update(['location' => 'Changed after preview']);
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertOk()->assertJsonPath('queued', 0)->assertJsonPath('skipped', 1);
    }

    public function test_selection_joining_and_retention_use_the_actual_outcome_even_for_closed_jobs(): void
    {
        $job = $this->selectedJob();
        $application = DB::table('applications')->insertGetId(['candidate_id' => $this->candidate->id, 'job_post_id' => $job, 'status' => 'interested', 'created_at' => now(), 'updated_at' => now()]);
        $selection = $this->systemTemplate('selection_update') + ['job_id' => $job];
        $this->preview('whatsapp', $selection)->assertOk()->assertJsonPath('ready', 0);
        $placement = DB::table('placement_outcomes')->insertGetId(['application_id' => $application, 'status' => 'selected', 'created_at' => now(), 'updated_at' => now()]);
        $this->preview('whatsapp', $selection)->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('rows.0.parameters.3', 'Selected');
        $joining = $this->systemTemplate('joining_update') + ['job_id' => $job];
        $this->preview('whatsapp', $joining)->assertOk()->assertJsonPath('ready', 0);
        DB::table('placement_outcomes')->where('id', $placement)->update(['status' => 'joining_pending', 'expected_joining_on' => now()->addWeek()->toDateString()]);
        $this->preview('whatsapp', $joining)->assertOk()->assertJsonPath('ready', 1);
        DB::table('placement_outcomes')->where('id', $placement)->update(['status' => 'joined', 'joined_on' => now()->subDays(35)->toDateString()]);
        DB::table('job_posts')->where('id', $job)->update(['status' => 'closed']);
        foreach ([7, 15, 30] as $day) {
            $this->preview('whatsapp', $this->systemTemplate('retention_day_'.$day) + ['job_id' => $job])->assertOk()->assertJsonPath('ready', 1);
        }
    }

    public function test_autofilled_context_is_rechecked_by_the_worker_after_queueing(): void
    {
        $template = $this->systemTemplate('welcome');
        $preview = $this->preview('whatsapp', $template)->assertOk()->json();
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertOk()->assertJsonPath('queued', 1);
        $this->candidate->update(['first_name' => 'Changed after preview']);
        (new SendWhatsAppMessage(DB::table('whatsapp_messages')->value('id')))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'skipped', 'error_code' => 'CONTEXT_CHANGED']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_custom_template_can_map_job_fields_and_requires_a_job_only_when_needed(): void
    {
        $job = $this->selectedJob();
        $variables = [['field' => 'name'], ['field' => 'job_role']];
        $this->preview('whatsapp', ['variables' => $variables])->assertUnprocessable();
        $preview = $this->preview('whatsapp', ['variables' => $variables, 'job_id' => $job])->assertOk()->assertJsonPath('ready', 1)->assertJsonPath('rows.0.parameters.1', 'Warehouse Assistant')->json();
        $this->postJson('/admin-api/candidate-notifications/'.$preview['id'].'/send')->assertOk();
        DB::table('job_posts')->where('id', $job)->update(['status' => 'paused']);
        (new SendWhatsAppMessage(DB::table('whatsapp_messages')->value('id')))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'skipped', 'error_code' => 'JOB_CHANGED']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }
}
