<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateDraft;
use Database\Seeders\CandidateMasterDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CandidateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CandidateMasterDataSeeder::class);
    }

    public function test_candidate_registration_is_validated_and_persisted_securely(): void
    {
        $response = $this->postJson('/api/candidates', $this->validPayload());

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
