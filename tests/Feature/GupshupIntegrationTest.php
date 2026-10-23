<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Candidate;
use App\Models\User;
use App\Services\GupshupWhatsApp;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppOutbox;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GupshupIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Candidate $candidate;
    private string $appId = 'bcd032f4-5e7b-4929-9945-9f731b7a3af0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformRolesSeeder::class);
        config(['whatsapp.provider' => 'gupshup', 'whatsapp.enabled' => true, 'whatsapp.gupshup_app_id' => $this->appId, 'whatsapp.gupshup_app_name' => 'ExoworkNotifications', 'whatsapp.gupshup_source' => '919785262702', 'whatsapp.gupshup_api_key' => 'test-key', 'whatsapp.gupshup_webhook_secret' => str_repeat('s', 40)]);
        Queue::fake();
        config(['whatsapp.gupshup_automation_enabled' => true]);
        Http::preventStrayRequests();
        $this->candidate = Candidate::forceCreate(['candidate_code' => 'EXO-CAN-000001', 'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211', 'gender' => 'Female', 'age' => 24, 'qualification' => 'ITI', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'current_location' => 'Jaipur', 'permanent_location' => 'Jaipur', 'preferred_locations' => ['Jaipur'], 'relocation_preference' => 'No', 'skills' => 'Machines', 'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000, 'availability' => 'Yes', 'preferred_industry' => 'Manufacturing', 'languages' => ['Hindi'], 'training_status' => 'Not enrolled', 'whatsapp_consent' => true]);
    }

    private function remote(string $status = 'APPROVED', array $overrides = []): array
    {
        return array_replace(['id' => 'template-uuid', 'elementName' => 'exowork_registration_v3', 'languageCode' => 'en_US', 'category' => 'UTILITY', 'status' => $status, 'templateType' => 'TEXT', 'containerMeta' => json_encode(['data' => \App\Services\WhatsAppTemplateCatalog::get('welcome')['body']])], $overrides);
    }

    private function outbound(): object
    {
        return app(WhatsAppOutbox::class)->enqueue('919876543211', ['name' => 'exowork_welcome_v1', 'language' => 'en_US', 'category' => 'MARKETING'], ['Asha'], ['candidate_id' => $this->candidate->id, 'source' => 'welcome']);
    }

    private function event(array $payload, string $type = 'message'): array
    {
        return ['app' => 'ExoworkNotifications', 'version' => 2, 'timestamp' => now()->timestamp * 1000, 'type' => $type, 'payload' => $payload];
    }

    private function webhook(array $event)
    {
        return $this->postJson('/api/webhooks/gupshup', $event, ['X-Exowork-Webhook-Token' => str_repeat('s', 40)]);
    }

    private function admin(): void
    {
        $user = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($user->refresh());
    }

    public function test_authenticated_welcome_replies_are_scoped_deduplicated_and_encrypted(): void
    {
        $row = $this->outbound();
        DB::table('whatsapp_messages')->where('id', $row->id)->update(['status' => 'accepted']);
        $event = $this->event(['id' => 'inbound-1', 'source' => $row->recipient, 'type' => 'quick_reply', 'payload' => ['text' => 'Yes', 'postbackText' => 'exowork:welcome:'.$row->id.':yes']]);
        $this->postJson('/api/webhooks/gupshup', $event)->assertForbidden();
        $this->webhook(array_replace($event, ['app' => 'WrongApp']))->assertUnprocessable();
        $this->webhook($event)->assertOk();
        $this->assertSame('interested', $this->candidate->fresh()->job_interest_status);
        $this->assertNotSame('Yes', DB::table('whatsapp_inbound_messages')->value('content'));
        $this->candidate->update(['job_interest_status' => 'not_interested']);
        $this->webhook($event)->assertOk();
        $this->assertSame('not_interested', $this->candidate->fresh()->job_interest_status);
        $this->assertDatabaseCount('whatsapp_inbound_messages', 1);
    }

    public function test_unscoped_text_and_foreign_buttons_never_guess_a_decision_and_stop_suppresses(): void
    {
        $row = $this->outbound();
        foreach (['Yes', 'Need help'] as $n => $text) $this->webhook($this->event(['id' => 'text-'.$n, 'source' => $row->recipient, 'type' => 'text', 'payload' => ['text' => $text]]))->assertOk();
        $this->webhook($this->event(['id' => 'foreign', 'source' => '919876543212', 'type' => 'quick_reply', 'payload' => ['text' => 'Yes', 'postbackText' => 'exowork:welcome:'.$row->id.':yes']]))->assertOk();
        $this->assertSame('no_response', $this->candidate->fresh()->job_interest_status);
        $this->webhook($this->event(['id' => 'stop', 'source' => $row->recipient, 'type' => 'text', 'payload' => ['text' => 'STOP']]))->assertOk();
        $this->assertFalse($this->candidate->fresh()->whatsapp_consent);
        $this->assertDatabaseHas('whatsapp_opt_outs', ['recipient' => $row->recipient]);
        (new SendWhatsAppMessage($row->id))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $row->id, 'status' => 'skipped']);
        Http::assertNothingSent();
    }

    public function test_delivery_receipts_before_acceptance_reconcile_and_cannot_regress(): void
    {
        $row = $this->outbound();
        $this->webhook($this->event(['id' => 'wamid.a', 'gsId' => 'gs-a', 'type' => 'read', 'destination' => $row->recipient, 'payload' => ['ts' => now()->timestamp]], 'message-event'))->assertOk();
        DB::table('whatsapp_messages')->where('id', $row->id)->update(['status' => 'accepted', 'provider_message_id' => 'gs-a']);
        app(\App\Http\Controllers\WhatsAppWebhookController::class)->reconcile('gs-a');
        $this->webhook($this->event(['id' => 'wamid.a', 'type' => 'sent', 'destination' => $row->recipient, 'payload' => ['ts' => now()->timestamp]], 'message-event'))->assertOk();
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $row->id, 'status' => 'read']);
    }

    public function test_registration_sends_profile_link_without_interest_buttons_or_duplicate_submission(): void
    {
        Http::fake(['api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => [$this->remote()]]), 'api.gupshup.io/wa/api/v1/template/msg' => Http::response(['status' => 'submitted', 'messageId' => 'gs-accepted'], 202)]);
        $this->admin();
        $this->postJson('/admin-api/whatsapp/sync')->assertOk();
        $id = DB::table('whatsapp_templates')->where('system_key', 'welcome')->value('id');
        $this->patchJson('/admin-api/whatsapp/templates/'.$id.'/review', ['status' => 'approved'])->assertOk();
        $row = app(WhatsAppOutbox::class)->system('welcome', '919876543211', ['Asha', 'https://exowork.in/candidate/profile'], ['candidate_id' => $this->candidate->id]);
        (new SendWhatsAppMessage($row->id))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $row->id, 'status' => 'accepted', 'provider_message_id' => 'gs-accepted']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.gupshup.io/wa/api/v1/template/msg' && $r->hasHeader('apikey', 'test-key') && json_decode($r['template'], true) === ['id' => 'template-uuid', 'params' => ['Asha', 'https://exowork.in/candidate/profile']] && ! isset($r['postbackTexts']));
        (new SendWhatsAppMessage($row->id))->handle(app(WhatsAppCloud::class));
        $this->assertSame(1, count(Http::recorded(fn ($r) => str_contains($r->url(), '/template/msg'))));
    }

    public function test_pending_or_modified_templates_are_not_sendable(): void
    {
        Http::fake(['api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => [$this->remote('PENDING'), $this->remote('APPROVED', ['containerMeta' => json_encode(['data' => 'Different body', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes']]])])]])]);
        $this->assertSame([], app(WhatsAppCloud::class)->templates());
    }

    public function test_provider_switch_blocks_old_outbox_and_admin_api_hides_secrets(): void
    {
        $row = $this->outbound();
        config(['whatsapp.provider' => 'meta']);
        (new SendWhatsAppMessage($row->id))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $row->id, 'error_code' => 'PROVIDER_CHANGED']);
        $this->admin();
        $response = $this->getJson('/admin-api/communication')->assertOk()->assertJsonMissingPath('whatsapp.gupshup_api_key')->assertJsonMissingPath('whatsapp.gupshup_customer_secret')->assertJsonMissingPath('whatsapp.gupshup_webhook_secret');
        $this->assertStringNotContainsString('test-key', $response->getContent());
        $this->getJson('/admin-api/whatsapp/replies')->assertOk();
        auth()->logout();
        $this->getJson('/admin-api/whatsapp/replies')->assertUnauthorized();
    }

    public function test_cat_is_cached_and_customer_secret_is_not_forwarded_to_message_apis(): void
    {
        config(['whatsapp.gupshup_customer_secret' => 'customer-secret']);
        Http::fake(['api.gupshup.io/wa/app/*/auth/app-token' => Http::response(['token' => 'cat-token', 'expiresAt' => now()->addDay()->toIso8601String()]), 'api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => []])]);
        app(WhatsAppCloud::class)->templates();
        app(WhatsAppCloud::class)->templates();
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/auth/app-token') && $r->method() === 'POST' && $r->body() === '' && $r->hasHeader('Content-Type', 'application/x-www-form-urlencoded') && $r->hasHeader('X-Customer-Secret', 'customer-secret') && ! $r->hasHeader('Cookie') && ! $r->hasHeader('Authorization'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/template') && $r->hasHeader('Authorization', 'Bearer cat-token') && ! $r->hasHeader('X-Customer-Secret'));
    }

    public function test_live_millisecond_token_expiry_is_accepted_and_refreshed_before_expiry(): void
    {
        $this->freezeTime();
        config(['whatsapp.gupshup_customer_secret' => 'customer-secret']);
        Http::fake(['api.gupshup.io/wa/app/*/auth/app-token' => fn () => Http::response(['status' => 'success', 'token' => 'cat-token', 'expiresAt' => now()->addDay()->getTimestampMs()]), 'api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => []])]);
        app(WhatsAppCloud::class)->templates();
        $this->travel(23)->hours();
        app(WhatsAppCloud::class)->templates();
        Http::assertSentCount(3);
        $this->travel(56)->minutes();
        app(WhatsAppCloud::class)->templates();
        Http::assertSentCount(5);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/template') && $r->hasHeader('Authorization', 'Bearer cat-token'));
    }

    public function test_connection_diagnostics_redact_credentials_and_cache_successful_token(): void
    {
        $this->admin();
        config(['whatsapp.gupshup_customer_secret' => 'private-customer-secret']);
        Http::fake(['api.gupshup.io/wa/app/*/auth/app-token' => Http::response(['token' => 'private-token', 'expiresAt' => now()->addDay()->getTimestampMs(), 'nested' => ['accessToken' => 'private-token'], 'message' => 'private-customer-secret']), 'api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => []])]);
        $this->postJson('/admin-api/communication/gupshup/test')->assertOk()->assertJsonPath('success', true)->assertJsonPath('response.body.token', '[REDACTED]')->assertJsonPath('response.body.nested.accessToken', '[REDACTED]')->assertJsonPath('request.body', '')->assertDontSee('private-token')->assertDontSee('private-customer-secret')->assertHeader('Cache-Control', 'no-store, private');
        app(WhatsAppCloud::class)->templates();
        Http::assertSentCount(2);
    }

    public function test_connection_diagnostics_return_provider_failure_and_require_admin_permission(): void
    {
        $this->admin();
        config(['whatsapp.gupshup_customer_secret' => 'private-customer-secret']);
        Http::fake(['api.gupshup.io/wa/app/*/auth/app-token' => Http::response(['message' => 'Authentication Failed'], 401)]);
        $this->postJson('/admin-api/communication/gupshup/test')->assertOk()->assertJsonPath('success', false)->assertJsonPath('response.status', 401)->assertJsonPath('response.body.message', 'Authentication Failed');
        auth()->logout();
        $this->postJson('/admin-api/communication/gupshup/test')->assertUnauthorized();
    }

    public function test_token_errors_explain_status_without_leaking_provider_secrets(): void
    {
        $this->admin();
        config(['whatsapp.gupshup_customer_secret' => 'private-customer-secret']);
        $sequence = Http::sequence();
        foreach ([401, 403, 404, 429, 503] as $status) $sequence->push(['token' => 'provider-token-must-stay-private', 'message' => 'private-customer-secret'], $status);
        Http::fake(['api.gupshup.io/wa/app/*/auth/app-token' => $sequence]);
        foreach ([401 => 'rejected', 403 => 'access denied', 404 => 'HTTP 404', 429 => 'limit reached', 503 => 'HTTP 503'] as $status => $expected) {
            $response = $this->postJson('/admin-api/whatsapp/sync')->assertStatus(502)->assertSee($expected);
            $response->assertDontSee('provider-token-must-stay-private')->assertDontSee('private-customer-secret');
        }
    }

    public function test_malformed_token_response_never_caches_or_forwards_token(): void
    {
        $this->admin();
        config(['whatsapp.gupshup_customer_secret' => 'private-customer-secret']);
        $sequence = Http::sequence();
        foreach ([[], ['token' => ''], ['token' => 'private-token', 'expiresAt' => 'not-a-date'], ['token' => 'private-token', 'expiresAt' => now()->subMinute()->toIso8601String()]] as $payload) {
            $sequence->push($payload);
        }
        Http::fake(['api.gupshup.io/wa/app/*/auth/app-token' => $sequence]);
        foreach (['incomplete', 'incomplete', 'invalid expiry date', 'expired'] as $expected) {
            $this->postJson('/admin-api/whatsapp/sync')->assertStatus(502)->assertSee($expected)->assertDontSee('private-token')->assertDontSee('private-customer-secret');
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/template'));
    }

    public function test_pausing_automation_blocks_workflow_and_previously_queued_stage_sends(): void
    {
        $row = $this->outbound();
        config(['whatsapp.gupshup_automation_enabled' => false]);
        app(\App\Services\WhatsAppWorkflow::class)->run();
        (new SendWhatsAppMessage($row->id))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $row->id, 'error_code' => 'AUTOMATION_PAUSED']);
        Http::assertNothingSent();
    }

    public function test_gupshup_settings_can_be_saved_without_meta_credentials_and_preserve_blanked_secrets(): void
    {
        $this->admin();
        $settings = ['enabled' => true, 'provider' => 'gupshup', 'api_version' => 'v26.0', 'default_country_code' => '91', 'gupshup_app_id' => $this->appId, 'gupshup_app_name' => 'ExoworkNotifications', 'gupshup_source' => '919785262702', 'gupshup_api_key' => 'test-key', 'gupshup_webhook_secret' => str_repeat('s', 40), 'gupshup_automation_enabled' => false];
        $this->putJson('/admin-api/communication/settings/whatsapp', $settings)->assertOk();
        $settings['gupshup_api_key'] = '';
        $settings['gupshup_webhook_secret'] = '';
        $this->putJson('/admin-api/communication/settings/whatsapp', $settings)->assertOk();
        $this->assertSame('test-key', app(\App\Services\CommunicationSettings::class)->get('whatsapp')['gupshup_api_key']);
        $this->getJson('/admin-api/communication')->assertOk()->assertJsonPath('whatsapp.provider', 'gupshup')->assertJsonMissingPath('whatsapp.gupshup_api_key')->assertJsonPath('webhook_url', 'http://localhost/api/webhooks/gupshup');
        $this->travel(1)->hours();
        $settings['gupshup_automation_enabled'] = true;
        $this->putJson('/admin-api/communication/settings/whatsapp', $settings)->assertOk();
        $this->assertSame(now()->toDateTimeString(), app(\App\Services\CommunicationSettings::class)->get('whatsapp_workflow')['start_at']);
    }

    public function test_send_timeout_is_unknown_and_does_not_auto_retry(): void
    {
        Http::fake(['api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => [$this->remote()]]), 'api.gupshup.io/wa/api/v1/template/msg' => Http::failedConnection()]);
        DB::table('whatsapp_templates')->where('system_key', 'welcome')->update(['review_status' => 'approved', 'meta_status' => 'APPROVED', 'account_id' => $this->appId]);
        $row = app(WhatsAppOutbox::class)->enqueue('919876543211', ['name' => 'exowork_registration_v3', 'language' => 'en_US', 'category' => 'UTILITY'], ['Asha', 'https://exowork.in/candidate/profile'], ['candidate_id' => $this->candidate->id, 'source' => 'welcome']);
        (new SendWhatsAppMessage($row->id))->handle(app(WhatsAppCloud::class));
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $row->id, 'status' => 'unknown']);
        (new SendWhatsAppMessage($row->id))->handle(app(WhatsAppCloud::class));
        $this->assertSame(1, DB::table('whatsapp_messages')->where('id', $row->id)->value('attempts'));
    }

    public function test_retired_job_interest_welcome_cannot_be_selected_or_sent_by_workflow(): void
    {
        Http::fake(['api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => [$this->remote('APPROVED', ['elementName' => 'exowork_welcome_v1', 'containerMeta' => json_encode(['data' => GupshupWhatsApp::WELCOME_BODY, 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes'], ['type' => 'QUICK_REPLY', 'text' => 'No']]])])]])]);
        $this->assertSame([], app(WhatsAppCloud::class)->templates());
        DB::table('whatsapp_templates')->where('system_key', 'welcome')->update(['name' => 'exowork_welcome_v1', 'body' => GupshupWhatsApp::WELCOME_BODY, 'review_status' => 'approved', 'meta_status' => 'APPROVED', 'account_id' => $this->appId]);
        $this->assertNull(app(WhatsAppOutbox::class)->system('welcome', '919876543211', ['Asha', 'https://exowork.in/candidate/profile'], ['candidate_id' => $this->candidate->id]));
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_formatted_catalog_syncs_all_versions_and_keeps_profile_review_separate_from_interest(): void
    {
        $catalog = \App\Services\WhatsAppTemplateCatalog::all();
        foreach ($catalog as $spec) {
            $this->assertStringContainsString("\n\n*", $spec['body']);
            $this->assertStringNotContainsString("\r", $spec['body']);
        }
        $this->assertSame('UTILITY', $catalog[0]['category']);
        $this->assertStringNotContainsString('looking for', $catalog[0]['body']);
        $this->admin();
        Http::fake(['api.gupshup.io/wa/app/*/template*' => Http::response(['templates' => array_map(fn ($spec) => $this->remote('APPROVED', ['id' => $spec['key'], 'elementName' => $spec['name'], 'languageCode' => $spec['language'], 'category' => $spec['category'], 'containerMeta' => json_encode(['data' => $spec['body']])]), $catalog)])]);
        DB::table('whatsapp_templates')->where('system_key', 'joining_update')->update(['name' => 'exowork_joining_update', 'body' => 'Old body', 'review_status' => 'approved']);
        $this->postJson('/admin-api/whatsapp/sync')->assertOk();
        $this->assertSame(13, DB::table('whatsapp_templates')->where('meta_status', 'APPROVED')->count());
        $this->assertDatabaseHas('whatsapp_templates', ['system_key' => 'joining_update', 'name' => 'exowork_joining_update_v2', 'review_status' => 'pending']);
        $this->assertSame('no_response', $this->candidate->fresh()->job_interest_status);
    }
}
