<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Database\Seeders\LocalSuperAdminSeeder;
use Database\Seeders\CandidateMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_superadmin_seed_can_sign_in(): void
    {
        $this->seed(LocalSuperAdminSeeder::class);
        $this->postJson('/auth/login', [
            'role' => 'admin',
            'identifier' => 'exowork@gmail.com',
            'password' => '123456789',
        ])->assertOk()->assertJsonPath('destination', '/admin/dashboard');
        $this->assertTrue(User::where('email', 'exowork@gmail.com')->firstOrFail()->hasPermission('permissions.manage'));
    }

    public function test_user_login_requires_assigned_role(): void
    {
        $this->seed(PlatformRolesSeeder::class);
        $user = User::factory()->create(['email' => 'owner@example.com', 'password' => 'Secret1234']);
        $this->postJson('/auth/login', ['role' => 'center', 'identifier' => 'owner@example.com', 'password' => 'Secret1234'])->assertUnprocessable();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'training_partner')->value('id')]);
        $this->postJson('/auth/login', ['role' => 'center', 'identifier' => 'owner@example.com', 'password' => 'Secret1234'])
            ->assertOk()->assertJsonPath('destination', '/center/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_candidate_can_login_with_mobile_and_remember_me(): void
    {
        $this->seed(CandidateMasterDataSeeder::class);
        $this->postJson('/api/candidates', $this->candidatePayload())->assertCreated();
        $candidate = Candidate::firstOrFail();
        $this->postJson('/auth/login', ['role' => 'candidate', 'identifier' => '9876543211', 'password' => 'SecurePass123', 'remember' => true])
            ->assertOk()->assertJsonPath('destination', '/candidate/dashboard');
        $this->assertAuthenticatedAs($candidate, 'candidate');
    }

    private function candidatePayload(): array
    {
        return [
            'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211',
            'password' => 'SecurePass123', 'password_confirmation' => 'SecurePass123',
            'gender' => 'Female', 'age' => 24, 'qualification' => 'ITI', 'state' => 'Rajasthan',
            'qualification_option_id' => DB::table('master_options')->where('type', 'qualification')->where('slug', 'iti')->value('id'),
            'state_id' => DB::table('states')->where('slug', 'rajasthan')->value('id'),
            'district_id' => DB::table('districts')->where('slug', 'jaipur')->value('id'),
            'district_is_custom' => false,
            'experience_level_option_id' => DB::table('master_options')->where('type', 'experience_level')->where('slug', 'fresher')->value('id'),
            'industry_option_id' => DB::table('master_options')->where('type', 'industry')->where('slug', 'manufacturing')->value('id'),
            'language_option_ids' => [DB::table('master_options')->where('type', 'language')->where('slug', 'hindi')->value('id')],
            'district' => 'Jaipur', 'current_location' => 'Jaipur', 'permanent_location' => 'Jaipur',
            'preferred_locations' => ['Jaipur'], 'relocation_preference' => 'No', 'skills' => 'Machine operation',
            'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000,
            'availability' => 'Yes', 'preferred_industry' => 'Manufacturing', 'languages' => ['Hindi'],
            'training_status' => 'Not enrolled', 'location_consent' => false, 'photo_consent' => false,
            'call_consent' => true, 'sms_consent' => true, 'whatsapp_consent' => true, 'email_consent' => false,
            'terms_accepted' => true, 'privacy_accepted' => true, 'registration_source' => 'public',
        ];
    }
}
