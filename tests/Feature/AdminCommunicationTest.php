<?php

namespace Tests\Feature;

use App\Mail\CandidateInvitation;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CommunicationSettings;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AdminCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformRolesSeeder::class);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);

        return $user;
    }

    private function smtp(): array
    {
        return ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'sender', 'password' => 'super-secret', 'from_address' => 'sender@example.com', 'from_name' => 'EXOWORK'];
    }

    private function whatsapp(): array
    {
        return ['enabled' => true, 'api_version' => 'v26.0', 'phone_number_id' => '123', 'business_account_id' => '456', 'access_token' => 'meta-secret', 'app_secret' => 'app-secret', 'verify_token' => 'verify-secret', 'default_country_code' => '91'];
    }

    public function test_only_authorized_admins_can_read_settings_and_change_templates(): void
    {
        $this->actingAs($this->user('employer'));
        $this->getJson('/admin-api/communication')->assertForbidden();
        $this->putJson('/admin-api/communication/settings/smtp', $this->smtp())->assertForbidden();
        $this->postJson('/admin-api/communication/email-templates', ['name' => 'No'])->assertForbidden();
        $this->getJson('/admin-api/communication/recipients?type=admin')->assertForbidden();
        $this->getJson('/admin-api/communication/emails')->assertForbidden();
    }

    public function test_credentials_are_encrypted_redacted_and_blank_updates_preserve_secrets(): void
    {
        $this->putJson('/admin-api/communication/settings/smtp', $this->smtp())->assertOk();
        $this->putJson('/admin-api/communication/settings/whatsapp', $this->whatsapp())->assertOk();
        $this->assertStringNotContainsString('super-secret', DB::table('communication_settings')->where('channel', 'smtp')->value('payload'));
        $this->getJson('/admin-api/communication')->assertOk()->assertJsonPath('smtp.password_configured', true)->assertJsonPath('whatsapp.access_token_configured', true)->assertDontSee('super-secret')->assertDontSee('meta-secret')->assertDontSee('verify-secret');
        $this->putJson('/admin-api/communication/settings/smtp', [...$this->smtp(), 'password' => ''])->assertOk();
        $this->assertSame('super-secret', app(CommunicationSettings::class)->get('smtp')['password']);
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->putJson('/admin-api/communication/settings/whatsapp', [...$this->whatsapp(), 'verify_token' => ''])->assertOk();
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-secret&hub.challenge=hello')->assertOk()->assertSee('hello');
    }

    public function test_email_templates_can_be_created_edited_disabled_and_deleted(): void
    {
        $data = ['name' => 'Welcome', 'subject' => 'Hello {{name}}', 'body' => 'Welcome to {{app_name}}, {{email}}', 'is_active' => true];
        $id = $this->postJson('/admin-api/communication/email-templates', $data)->assertOk()->json('id');
        $this->putJson('/admin-api/communication/email-templates/'.$id, [...$data, 'is_active' => false])->assertOk();
        $this->getJson('/admin-api/communication')->assertJsonFragment(['name' => 'Welcome', 'is_active' => 0]);
        $this->postJson('/admin-api/communication/email-templates', [...$data, 'body' => '{{secret}}'])->assertUnprocessable();
        $this->deleteJson('/admin-api/communication/email-templates/'.$id)->assertOk();
        $this->assertDatabaseMissing('email_templates', ['id' => $id]);
    }

    private function campaign(array $recipients): array
    {
        return ['request_key' => (string) Str::uuid(), 'subject' => 'Hello {{name}}', 'body' => 'Contact: {{email}}', 'recipients' => $recipients];
    }

    public function test_bulk_email_is_personalized_private_and_idempotent(): void
    {
        app(CommunicationSettings::class)->save('smtp', $this->smtp());
        $employer = $this->user('employer');
        $other = $this->user('employer');
        $payload = $this->campaign([['type' => 'employer', 'id' => $employer->id], ['type' => 'employer', 'id' => $other->id], ['type' => 'employer', 'id' => $employer->id]]);
        $id = $this->postJson('/admin-api/communication/emails', $payload)->assertCreated()->json('id');
        $this->postJson('/admin-api/communication/emails', $payload)->assertJsonPath('id', $id);
        Mail::partialMock()->shouldReceive('raw')->twice()->andReturnUsing(function ($text, $callback) {
            $message = new Message(new Email);
            $callback($message);
            $this->assertCount(1, $message->getSymfonyMessage()->getTo());
            $email = $message->getSymfonyMessage()->getTo()[0]->getAddress();
            $name = DB::table('users')->where('email', $email)->value('name');
            $this->assertSame('Contact: '.$email, $text);
            $this->assertSame('Hello '.$name, $message->getSymfonyMessage()->getSubject());
        });
        $this->postJson("/admin-api/communication/emails/$id/process")->assertOk()->assertJsonPath('remaining', 1);
        $this->postJson("/admin-api/communication/emails/$id/process")->assertOk()->assertJsonPath('remaining', 0);
        $this->postJson("/admin-api/communication/emails/$id/process")->assertOk()->assertJsonPath('remaining', 0);
        $this->assertSame(2, DB::table('email_deliveries')->where('status', 'accepted')->count());
    }

    public function test_consent_is_checked_at_selection_and_again_at_send_time(): void
    {
        app(CommunicationSettings::class)->save('smtp', $this->smtp());
        $candidate = Candidate::forceCreate([
            'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211', 'email' => 'asha@example.com', 'gender' => 'Female', 'age' => 24,
            'qualification' => 'ITI', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'current_location' => 'Jaipur', 'permanent_location' => 'Jaipur', 'preferred_locations' => ['Jaipur'], 'relocation_preference' => 'No', 'skills' => 'Machines', 'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000, 'availability' => 'Yes', 'preferred_industry' => 'Manufacturing', 'languages' => ['Hindi'], 'training_status' => 'Not enrolled', 'email_consent' => false,
        ]);
        $this->getJson('/admin-api/communication/recipients?type=candidate')->assertJsonPath('total', 0);
        $payload = $this->campaign([['type' => 'candidate', 'id' => $candidate->id]]);
        $this->postJson('/admin-api/communication/emails', $payload)->assertUnprocessable();
        $candidate->update(['email_consent' => true]);
        $id = $this->postJson('/admin-api/communication/emails', $payload)->assertCreated()->json('id');
        $candidate->update(['email_consent' => false]);
        Mail::partialMock()->shouldNotReceive('raw');
        $this->postJson("/admin-api/communication/emails/$id/process")->assertOk()->assertJsonPath('deliveries.0.status', 'skipped');
    }

    public function test_unknown_smtp_results_are_not_automatically_retried(): void
    {
        app(CommunicationSettings::class)->save('smtp', $this->smtp());
        $payload = $this->campaign([['type' => 'admin', 'id' => $this->admin->id]]);
        $id = $this->postJson('/admin-api/communication/emails', $payload)->assertCreated()->json('id');
        Mail::partialMock()->shouldReceive('raw')->once()->andThrow(new \RuntimeException('secret password must not leak'));
        $this->postJson("/admin-api/communication/emails/$id/process")->assertOk()->assertJsonPath('deliveries.0.status', 'unknown')->assertDontSee('secret password');
        $this->postJson("/admin-api/communication/emails/$id/process")->assertOk()->assertJsonPath('remaining', 0);
    }

    public function test_meta_template_submission_list_and_delete_use_saved_account(): void
    {
        app(CommunicationSettings::class)->save('whatsapp', $this->whatsapp());
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [], 'id' => 'template-123', 'status' => 'PENDING', 'success' => true])]);
        $this->getJson('/admin-api/communication/whatsapp-templates')->assertOk();
        $data = ['name' => 'interview_reminder', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => 'Hello {{1}}, interview on {{2}}.', 'examples' => ['Asha', 'Tuesday']];
        $this->postJson('/admin-api/communication/whatsapp-templates', $data)->assertCreated();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer meta-secret') && $r['components'][0]['example']['body_text'] === [['Asha', 'Tuesday']]);
        $this->postJson('/admin-api/communication/whatsapp-templates', [...$data, 'body' => 'Hello {{3}}'])->assertUnprocessable();
        $this->deleteJson('/admin-api/communication/whatsapp-templates', ['name' => 'interview_reminder'])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r['name'] === 'interview_reminder');
    }

    public function test_email_sending_requires_enabled_smtp(): void
    {
        $this->postJson('/admin-api/communication/emails', $this->campaign([['type' => 'admin', 'id' => $this->admin->id]]))->assertUnprocessable();
        $this->postJson('/admin-api/communication/smtp/test', ['email' => 'test@example.com'])->assertUnprocessable();
    }

    public function test_system_emails_use_admin_templates_and_require_working_link_placeholders(): void
    {
        $invitation = DB::table('email_templates')->where('system_key', 'candidate_invitation')->first();
        $data = ['name' => $invitation->name, 'subject' => 'Join {{center_name}}', 'body' => 'Custom invitation: {{registration_url}}', 'is_active' => true];
        $this->putJson('/admin-api/communication/email-templates/'.$invitation->id, [...$data, 'body' => 'Missing link'])->assertUnprocessable();
        $this->putJson('/admin-api/communication/email-templates/'.$invitation->id, $data)->assertOk();
        $mail = new CandidateInvitation('Training Center', null, 'https://example.com/register?invite=test');
        $this->assertSame('Join Training Center', $mail->envelope()->subject);
        $this->assertStringContainsString('Custom invitation: https://example.com/register?invite=test', $mail->render());
        $reset = DB::table('email_templates')->where('system_key', 'password_reset')->first();
        $this->putJson('/admin-api/communication/email-templates/'.$reset->id, ['name' => $reset->name, 'subject' => 'Custom reset', 'body' => 'Reset link: {{reset_url}} for {{name}}', 'is_active' => true])->assertOk();
        $notification = new ResetPassword('sample-token');
        $message = $notification->toMail($this->admin);
        $this->assertSame('Custom reset', $message->subject);
        $this->assertStringContainsString('/reset-password/sample-token', $message->viewData['messageBody']);
        $this->putJson('/admin-api/communication/email-templates/'.$reset->id, ['name' => $reset->name, 'subject' => 'Custom reset', 'body' => 'Reset link: {{reset_url}}', 'is_active' => false])->assertOk();
        $this->assertSame('Reset Password Notification', $notification->toMail($this->admin)->subject);
    }

    public function test_meta_template_edits_cannot_target_another_business_account(): void
    {
        app(CommunicationSettings::class)->save('whatsapp', $this->whatsapp());
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v26.0/456/message_templates*' => Http::response(['data' => [['id' => '789', 'name' => 'update', 'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'Hello']]]]]),
            'graph.facebook.com/v26.0/789' => Http::response(['success' => true]),
        ]);
        $data = ['id' => '999', 'name' => 'update', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => 'Updated text', 'examples' => []];
        $this->postJson('/admin-api/communication/whatsapp-templates', $data)->assertUnprocessable();
        $this->postJson('/admin-api/communication/whatsapp-templates', [...$data, 'id' => '789'])->assertCreated();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/789') && $r['components'][0]['text'] === 'Updated text' && ! isset($r['name']));
    }
}
