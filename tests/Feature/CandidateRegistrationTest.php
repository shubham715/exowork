<?php

namespace Tests\Feature;

use App\Mail\AdminEventMail;
use App\Models\Candidate;
use App\Models\CandidateDraft;
use Database\Seeders\CandidateMasterDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CandidateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(\App\Services\CommunicationSettings::class)->save('authentication', ['registration_enabled' => false]);
        $this->seed(CandidateMasterDataSeeder::class);
    }

    public function test_candidate_registration_is_validated_and_persisted_securely(): void
    {
        Mail::fake();
        config(['mail.admin_notification_email' => 'admin@example.test', 'app.url' => 'https://platform.example.test']);
        $response = $this->postJson('/api/candidates', $this->validPayload());

        Mail::assertSent(AdminEventMail::class, function (AdminEventMail $mail) {
            $mail->assertHasTo('admin@example.test')
                ->assertSeeInHtml('https://platform.example.test/admin/candidates/EXO-CAN-000001')
                ->assertDontSeeInHtml('123412341234');

            return true;
        });

        $response->assertCreated()
            ->assertJsonPath('candidate.candidate_code', 'EXO-CAN-000001')
            ->assertJsonMissingPath('candidate.password')
            ->assertJsonMissingPath('candidate.aadhaar_number');

        $candidate = Candidate::firstOrFail();
        $this->assertTrue(Hash::check('SecurePass123', $candidate->password));
        $this->assertSame('123412341234', $candidate->aadhaar_number);
        $this->assertNotSame('123412341234', $candidate->getRawOriginal('aadhaar_number'));
        $this->assertNotNull($candidate->terms_accepted_at);
    }

    public function test_new_candidate_is_visible_in_admin_and_notification_read_state_is_private(): void
    {
        $this->seed(\Database\Seeders\PlatformRolesSeeder::class);
        $this->postJson('/api/candidates', $this->validPayload())->assertCreated();
        $admin = \App\Models\User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $admin->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($admin->fresh())->getJson('/admin-api/candidates')->assertOk()->assertJsonPath('data.0.candidate_code', 'EXO-CAN-000001');
        $item = $this->getJson('/admin-api/review-notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('items.0.kind', 'candidates')->assertJsonPath('items.0.url', '/admin/candidates/EXO-CAN-000001')->json('items.0');
        $this->postJson('/admin-api/review-notifications/read', ['items' => [$item]])->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'items');
        DB::table('candidates')->update(['updated_at' => now()->addMinute()]);
        $this->getJson('/admin-api/review-notifications')->assertJsonPath('unread_count', 0);
        $employer = \App\Models\User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $employer->id, 'role_id' => DB::table('roles')->where('key', 'employer')->value('id')]);
        $this->actingAs($employer)->getJson('/admin-api/review-notifications')->assertForbidden();
    }

    public function test_candidate_number_cannot_request_another_registration_otp(): void
    {
        $payload = $this->validPayload();
        $this->postJson('/api/candidates', $payload)->assertCreated();
        app(\App\Services\CommunicationSettings::class)->save('authentication', ['registration_enabled' => true]);
        $this->mock(\App\Services\WhatsAppOtpSender::class)->shouldNotReceive('send');

        foreach (['candidate', 'employer', 'center'] as $role) {
            $this->postJson('/auth/otp/send', ['phone' => $payload['whatsapp'], 'role' => $role, 'purpose' => 'registration'])
                ->assertUnprocessable()->assertJsonPath('code', 'PHONE_ALREADY_REGISTERED')->assertJsonMissingPath('challenge_id');
        }
    }

    public function test_duplicate_mobile_and_missing_consent_are_rejected(): void
    {
        $this->postJson('/api/candidates', $this->validPayload())->assertCreated();

        $payload = $this->validPayload();
        $payload['privacy_accepted'] = false;

        $this->postJson('/api/candidates', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp', 'privacy_accepted']);
    }

    public function test_other_district_is_saved_under_selected_state(): void
    {
        $payload = $this->validPayload();
        $payload['district'] = 'New District';
        $payload['district_id'] = null;
        $payload['district_is_custom'] = true;

        $this->postJson('/api/candidates', $payload)->assertCreated();
        $candidate = Candidate::firstOrFail();
        $this->assertSame('New District', $candidate->district);
        $this->assertNull($candidate->district_id);
        $this->assertTrue((bool) $candidate->district_is_custom);
        $this->assertNotNull($candidate->state_id);
    }

    public function test_district_from_another_state_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['district_id'] = DB::table('districts')->where('slug', 'lucknow')->value('id');

        $this->postJson('/api/candidates', $payload)->assertUnprocessable()->assertJsonValidationErrors('district_id');
    }

    public function test_safe_draft_can_be_saved_restored_and_removed_after_registration(): void
    {
        $draftResponse = $this->postJson('/api/candidate-drafts', [
            'last_step' => 2,
            'registration_source' => 'public',
            'data' => [
                'firstName' => 'Asha',
                'whatsapp' => '9876543211',
                'district' => 'Jaipur',
                'password' => 'must-not-be-saved',
                'aadhaar' => 'must-not-be-saved',
            ],
        ])->assertCreated();

        $token = $draftResponse->json('draft.token');
        $this->assertSame(64, strlen($token));
        $this->assertArrayNotHasKey('password', CandidateDraft::firstOrFail()->payload);
        $this->assertArrayNotHasKey('aadhaar', CandidateDraft::firstOrFail()->payload);

        $this->getJson("/api/candidate-drafts/{$token}")
            ->assertOk()
            ->assertJsonPath('draft.data.firstName', 'Asha')
            ->assertJsonPath('draft.last_step', 2);

        $payload = $this->validPayload();
        $payload['draft_token'] = $token;
        $this->postJson('/api/candidates', $payload)->assertCreated();

        $this->assertDatabaseCount('candidate_drafts', 0);
        $this->getJson("/api/candidate-drafts/{$token}")->assertNotFound();
    }

    public function test_manual_state_and_district_are_saved_as_searchable_names(): void
    {
        $payload = $this->validPayload();
        $payload['state'] = 'New Region';
        $payload['state_id'] = null;
        $payload['district'] = '  New   District  ';
        $payload['district_id'] = null;
        $payload['district_is_custom'] = true;
        $this->postJson('/api/candidates', $payload)->assertCreated();
        $candidate = Candidate::firstOrFail();
        $this->assertSame('New Region', $candidate->state);
        $this->assertSame('New District', $candidate->district);
        $this->assertNull($candidate->state_id);
        $this->assertNull($candidate->district_id);
        $this->assertTrue((bool) $candidate->district_is_custom);
        $this->assertSame(1, Candidate::where('district', 'like', '%District%')->count());
    }

    private function validPayload(): array
    {
        return [
            'first_name' => 'Asha', 'last_name' => 'Sharma',
            'whatsapp' => '9876543211', 'alternate_mobile' => '9876543212',
            'email' => 'asha@example.com', 'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123', 'gender' => 'Female', 'age' => 24,
            'qualification' => 'ITI', 'state' => 'Rajasthan', 'district' => 'Jaipur',
            'qualification_option_id' => DB::table('master_options')->where('type', 'qualification')->where('slug', 'iti')->value('id'),
            'state_id' => DB::table('states')->where('slug', 'rajasthan')->value('id'),
            'district_id' => DB::table('districts')->where('slug', 'jaipur')->value('id'),
            'district_is_custom' => false,
            'experience_level_option_id' => DB::table('master_options')->where('type', 'experience_level')->where('slug', 'fresher')->value('id'),
            'industry_option_id' => DB::table('master_options')->where('type', 'industry')->where('slug', 'manufacturing')->value('id'),
            'language_option_ids' => [DB::table('master_options')->where('type', 'language')->where('slug', 'hindi')->value('id')],
            'current_location' => 'Sanganer', 'permanent_location' => 'Jaipur',
            'preferred_locations' => ['Jaipur, Rajasthan'], 'relocation_preference' => 'No',
            'skills' => 'Machine operation, 5S', 'experience_type' => 'Fresher',
            'expected_monthly_salary' => 15000, 'availability' => 'Yes',
            'preferred_industry' => 'Manufacturing', 'languages' => ['Hindi'],
            'training_status' => 'Not enrolled', 'aadhaar_number' => '1234 1234 1234',
            'location_consent' => false, 'photo_consent' => true, 'call_consent' => true,
            'sms_consent' => true, 'whatsapp_consent' => true, 'email_consent' => true,
            'terms_accepted' => true, 'privacy_accepted' => true, 'registration_source' => 'public',
        ];
    }
}
