<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use App\Services\WhatsAppCloud;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Candidate $candidate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformRolesSeeder::class);
        config(['whatsapp.enabled' => true, 'whatsapp.api_version' => 'v26.0', 'whatsapp.access_token' => 'secret-token', 'whatsapp.phone_number_id' => '123', 'whatsapp.business_account_id' => '456', 'whatsapp.app_secret' => 'app-secret', 'whatsapp.verify_token' => 'verify-secret']);
        $this->admin = $this->user('admin');
        $this->candidate = Candidate::forceCreate([
            'candidate_code' => 'EXO-CAN-000001', 'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211', 'gender' => 'Female', 'age' => 24,
            'qualification' => 'ITI', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'current_location' => 'Jaipur', 'permanent_location' => 'Jaipur', 'preferred_locations' => ['Jaipur'], 'relocation_preference' => 'No', 'skills' => 'Machines', 'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000, 'availability' => 'Yes', 'preferred_industry' => 'Manufacturing', 'languages' => ['Hindi'], 'training_status' => 'Not enrolled', 'whatsapp_consent' => true,
        ]);
        $this->actingAs($this->admin);
        Http::preventStrayRequests();
        $this->fakeMeta();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);

        return $user->refresh();
    }

    private function fakeMeta($sendResponse = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [
                ['name' => 'interview_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, your interview is scheduled.']]],
                ['name' => 'pending_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'PENDING', 'components' => [['type' => 'BODY', 'text' => 'Hello']]],
                ['name' => 'media_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Hello']]],
            ]]),
            'graph.facebook.com/v26.0/123/messages' => $sendResponse ?? Http::response(['messages' => [['id' => 'wamid.test']]]),
        ]);
    }

    private function url(string $audience = 'admin'): string
    {
        return "/$audience-api/candidates/EXO-CAN-000001/whatsapp";
    }

    private function payload(?string $id = null): array
    {
        return ['request_id' => $id ?? (string) Str::uuid(), 'template_name' => 'interview_update', 'language' => 'en', 'parameters' => ['Asha']];
    }

    public function test_phone_normalization_preserves_explicit_international_numbers(): void
    {
        $cloud = app(WhatsAppCloud::class);
        $this->assertSame('919876543211', $cloud->recipient('98765 43211'));
        $this->assertSame('4412345678', $cloud->recipient('+44 1234 5678'));
    }

    public function test_candidate_directory_filters_sorting_and_global_counts_use_saved_records(): void
    {
        $other = $this->candidate->replicate();
        $other->forceFill(['candidate_code' => 'EXO-CAN-000002', 'first_name' => 'Ravi', 'whatsapp' => '9876543212', 'availability' => 'No', 'whatsapp_consent' => false, 'profile_status' => 'inactive', 'state' => 'Maharashtra', 'qualification' => '12th', 'training_status' => 'Ongoing', 'training_center' => 'Pune Skill Center'])->save();
        $this->getJson('/admin-api/candidates?consent=yes&availability[]=Yes')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('counts.all', 2)->assertJsonPath('counts.training', 1)->assertJsonPath('counts.inactive', 1)->assertJsonPath('filters.centers.0', 'Pune Skill Center');
        $this->getJson('/admin-api/candidates?states[]=Maharashtra&qualifications[]=12th&statuses[]=inactive')->assertOk()->assertJsonPath('data.0.first_name', 'Ravi')->assertJsonPath('total', 1);
        $this->getJson('/admin-api/candidates?search=Asha%20Sharma')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/admin-api/candidates?search=%25')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/admin-api/candidates?sort=first_name&direction=desc&per_page=50')->assertOk()->assertJsonPath('data.0.first_name', 'Ravi')->assertJsonPath('per_page', 50);
        $this->getJson('/admin-api/candidates?sort=password')->assertUnprocessable();
        $this->getJson('/admin-api/candidates?from=2026-10-02&to=2026-09-01')->assertUnprocessable();
    }

    public function test_candidate_management_is_audited_and_deactivation_blocks_whatsapp(): void
    {
        $this->patchJson('/admin-api/candidates/EXO-CAN-000001', ['availability' => 'Available after training', 'skills' => 'Machine operation', 'expected_monthly_salary' => 20000])->assertOk();
        $this->assertDatabaseHas('candidates', ['id' => $this->candidate->id, 'expected_monthly_salary' => 20000]);
        $this->postJson('/admin-api/candidates/actions', ['ids' => [$this->candidate->id], 'action' => 'disable'])->assertOk();
        $this->postJson($this->url(), $this->payload())->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.candidate_disable', 'subject_id' => $this->candidate->id]);
        $this->postJson('/admin-api/candidates/actions', ['ids' => [$this->candidate->id, 999], 'action' => 'activate'])->assertUnprocessable();
        $this->assertSame('inactive', $this->candidate->fresh()->profile_status);
        $this->postJson('/admin-api/candidates/actions', ['ids' => [$this->candidate->id], 'action' => 'activate'])->assertOk();
        DB::table('user_permissions')->insert(['user_id' => $this->admin->id, 'permission_id' => DB::table('permissions')->where('key', 'candidates.update')->value('id'), 'allowed' => false]);
        $this->getJson('/admin-api/candidates')->assertOk()->assertJsonPath('can_manage', false);
        $this->patchJson('/admin-api/candidates/EXO-CAN-000001', ['availability' => 'Yes', 'skills' => 'X', 'expected_monthly_salary' => 100])->assertForbidden();
        $this->postJson('/admin-api/candidates/actions', ['ids' => [$this->candidate->id], 'action' => 'disable'])->assertForbidden();
    }

    public function test_candidate_csv_matches_filters_and_protects_sensitive_data_and_formulas(): void
    {
        $this->candidate->update(['first_name' => '=HYPERLINK', 'skills' => 'private skills']);
        $response = $this->get('/admin-api/candidates/export?consent=yes')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('EXO-CAN-000001', $csv);
        $this->assertStringNotContainsString('private skills', $csv);
        $this->assertStringNotContainsString('password', $csv);
        $this->assertStringNotContainsString('aadhaar', $csv);
        $this->get('/admin-api/candidates/export?consent=no')->assertOk();
        $this->actingAs($this->user('employer'))->get('/admin-api/candidates/export')->assertForbidden();
    }

    public function test_admin_reads_real_candidates_and_only_approved_supported_templates(): void
    {
        $this->getJson('/admin-api/candidates')->assertOk()->assertJsonPath('data.0.candidate_code', 'EXO-CAN-000001')->assertJsonMissingPath('data.0.password');
        $this->getJson('/admin-api/candidates/EXO-CAN-000001')->assertOk()->assertJsonMissingPath('candidate.aadhaar_number');
        $this->getJson($this->url())->assertOk()->assertJsonPath('configured', true)->assertJsonCount(1, 'templates')->assertJsonPath('templates.0.parameter_count', 1);
        config(['whatsapp.enabled' => false]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('configured', false)->assertJsonPath('missing.0', 'WHATSAPP_ENABLED');
        $this->postJson($this->url(), $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_sends_exact_meta_payload_once_and_records_no_variables_or_credentials(): void
    {
        $payload = $this->payload();
        $this->postJson($this->url(), $payload)->assertOk()->assertJsonPath('message.status', 'accepted');
        $this->postJson($this->url(), $payload)->assertOk()->assertJsonPath('message.status', 'accepted');
        Http::assertSentCount(2); // one template GET, one message POST
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://graph.facebook.com/v26.0/123/messages' && $r->hasHeader('Authorization', 'Bearer secret-token') && $r['to'] === '919876543211' && $r['type'] === 'template' && $r['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'Asha']);
        $this->assertDatabaseCount('whatsapp_messages', 1);
        $record = (array) DB::table('whatsapp_messages')->first();
        $this->assertArrayNotHasKey('parameters', $record);
        $this->assertStringNotContainsString('secret-token', json_encode($record));
        $this->postJson($this->url(), $this->payload())->assertStatus(429);
    }

    public function test_consent_and_parameter_validation_block_sending(): void
    {
        $this->postJson($this->url(), [...$this->payload(), 'parameters' => []])->assertUnprocessable();
        $this->postJson($this->url(), [...$this->payload(), 'template_name' => 'pending_update'])->assertUnprocessable();
        $this->candidate->update(['whatsapp_consent' => false]);
        $this->postJson($this->url(), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('candidate');
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_employer_cannot_access_admin_directory_or_unreleased_candidates(): void
    {
        $employer = $this->user('employer');
        $id = DB::table('employers')->insertGetId(['name' => 'Acme', 'code' => 'ACME', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_memberships')->insert(['user_id' => $employer->id, 'employer_id' => $id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($employer)->getJson('/admin-api/candidates')->assertForbidden();
        $this->getJson($this->url('employer'))->assertNotFound();
        $job = DB::table('job_posts')->insertGetId(['employer_id' => $id, 'title' => 'Operator', 'openings' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $application = DB::table('applications')->insertGetId(['job_post_id' => $job, 'candidate_id' => $this->candidate->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($this->url('employer'), $this->payload())->assertNotFound();
        DB::table('interviews')->insert(['application_id' => $application, 'scheduled_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($this->url('employer'), $this->payload())->assertOk();
        $other = $this->user('employer');
        $otherId = DB::table('employers')->insertGetId(['name' => 'Other', 'code' => 'OTHER', 'status' => 'verified']);
        DB::table('organization_memberships')->insert(['user_id' => $other->id, 'employer_id' => $otherId, 'status' => 'active']);
        $this->actingAs($other)->getJson($this->url('employer'))->assertNotFound();
        $this->actingAs($this->user('training_partner'))->getJson('/admin-api/candidates')->assertForbidden();
    }

    private function webhook(array $value, string $waba = '456')
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => $waba, 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123'], ...$value]]]]]]);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')], $body);
    }

    public function test_signed_webhooks_handle_early_duplicate_and_out_of_order_receipts(): void
    {
        $this->postJson('/api/webhooks/whatsapp', ['object' => 'whatsapp_business_account'])->assertForbidden();
        $this->webhook(['statuses' => [['id' => 'wamid.test', 'status' => 'read', 'timestamp' => '200']]])->assertOk();
        $this->postJson($this->url(), $this->payload())->assertOk()->assertJsonPath('message.status', 'read');
        $this->webhook(['statuses' => [['id' => 'wamid.test', 'status' => 'sent', 'timestamp' => '100']]])->assertOk();
        $this->webhook(['statuses' => [['id' => 'wamid.test', 'status' => 'delivered', 'timestamp' => '300']]])->assertOk();
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'read']);
        $this->assertDatabaseCount('whatsapp_receipts', 1);
    }

    public function test_webhook_verification_and_opt_out_are_scoped_to_configured_assets(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-secret&hub.challenge=abc123')->assertOk()->assertSee('abc123');
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc123')->assertForbidden();
        $stop = ['messages' => [['from' => '919876543211', 'type' => 'text', 'text' => ['body' => 'STOP']]]];
        $this->webhook($stop, 'other-waba')->assertOk();
        $this->assertTrue($this->candidate->fresh()->whatsapp_consent);
        $this->webhook($stop)->assertOk();
        $this->assertFalse($this->candidate->fresh()->whatsapp_consent);
        $this->postJson($this->url(), $this->payload())->assertUnprocessable();
    }

    public function test_meta_errors_and_network_timeouts_are_not_reported_as_success_or_retried(): void
    {
        $this->fakeMeta(Http::response(['error' => ['code' => 131030, 'message' => 'private provider details']], 400));
        $this->postJson($this->url(), $this->payload())->assertStatus(202)->assertJsonPath('message.status', 'failed')->assertJsonPath('message.error_code', '131030')->assertDontSee('private provider details');
        $this->travel(7)->seconds();
        $this->fakeMeta(Http::failedConnection());
        $payload = $this->payload();
        $this->postJson($this->url(), $payload)->assertStatus(202)->assertJsonPath('message.status', 'unknown');
        $before = Http::recorded()->count();
        $this->postJson($this->url(), $payload)->assertStatus(202)->assertJsonPath('message.status', 'unknown');
        $this->assertSame($before, Http::recorded()->count());
    }
}
