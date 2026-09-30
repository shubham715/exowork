<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CenterCandidateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_center_candidate_is_saved_with_owned_batch_and_consent(): void
    {
        $this->seed(PlatformRolesSeeder::class);
        $user = User::factory()->create(['email' => 'partner@example.com']);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'training_partner')->value('id')]);
        $partnerId = DB::table('training_partners')->insertGetId(['code' => 'TP-1', 'name' => 'Partner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $centerId = DB::table('training_centers')->insertGetId(['training_partner_id' => $partnerId, 'code' => 'CTR-1', 'name' => 'Center', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_memberships')->insert(['user_id' => $user->id, 'training_center_id' => $centerId, 'membership_role' => 'owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $batchId = DB::table('training_batches')->insertGetId(['training_center_id' => $centerId, 'code' => 'B-1', 'job_role' => 'Operator', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->postJson('/center-api/candidates', $this->payload($batchId))
            ->assertCreated()->assertJsonPath('candidate.candidate_code', 'EXO-CAN-000001');
        $candidate = Candidate::firstOrFail();
        $this->assertSame($centerId, $candidate->training_center_id);
        $this->assertSame($batchId, $candidate->training_batch_id);
        $this->assertNull($candidate->password);
        $this->assertDatabaseCount('candidate_consents', 6);

        $otherCenter = DB::table('training_centers')->insertGetId(['training_partner_id' => $partnerId, 'code' => 'CTR-2', 'name' => 'Other', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherBatch = DB::table('training_batches')->insertGetId(['training_center_id' => $otherCenter, 'code' => 'B-2', 'job_role' => 'Operator', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/center-api/candidates', $this->payload($otherBatch))->assertUnprocessable()->assertJsonValidationErrors('batch_id');

        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission_id' => DB::table('permissions')->where('key', 'candidates.create')->value('id'), 'allowed' => false]);
        $this->postJson('/center-api/candidates', $this->payload($batchId))->assertForbidden();
    }

    public function test_guest_cannot_create_center_candidate(): void
    {
        $this->postJson('/center-api/candidates', [])->assertUnauthorized();
    }

    private function payload(int $batchId): array
    {
        return [
            'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211',
            'gender' => 'Female', 'age' => 24, 'qualification' => 'ITI',
            'state' => 'Rajasthan', 'district' => 'Jaipur', 'current_location' => 'Jaipur',
            'permanent_location' => 'Jaipur', 'preferred_locations' => ['Jaipur'],
            'relocation_preference' => 'No', 'skills' => 'Machine operation',
            'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000,
            'availability' => 'Yes', 'preferred_industry' => 'Manufacturing',
            'languages' => ['Hindi'], 'training_status' => 'Ongoing', 'batch_id' => $batchId,
            'call_consent' => true, 'sms_consent' => true, 'whatsapp_consent' => true,
            'email_consent' => false, 'terms_accepted' => true, 'privacy_accepted' => true,
        ];
    }
}
