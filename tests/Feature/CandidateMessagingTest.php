<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use App\Services\CampaignEmailSender;
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
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [
            ['name' => 'candidate_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Changed {{1}}, visit {{2}}.']]],
        ]])]);
        (new \App\Jobs\SendWhatsAppMessage(DB::table('whatsapp_messages')->value('id')))->handle(app(\App\Services\WhatsAppCloud::class));
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
}
