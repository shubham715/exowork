<?php

namespace Tests\Feature;

use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CenterOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function registerCenter(): void
    {
        $this->seed(PlatformRolesSeeder::class);
        $this->postJson('/auth/register/training-center', [
            'organization_name' => 'Test Organization', 'center_name' => 'Test Center',
            'email' => 'center@example.com', 'phone' => '9876543210',
            'password' => 'Secret1234', 'password_confirmation' => 'Secret1234',
            'state' => 'Rajasthan', 'district' => 'Jaipur',
        ])->assertCreated();
    }

    private function profile(): array
    {
        return [
            'step' => 3, 'completed_step' => 2, 'draft' => false,
            'center_type' => 'Individual', 'spoc_name' => 'Asha',
            'spoc_phone' => '9876543210', 'spoc_email' => 'center@example.com',
            'state' => 'Rajasthan', 'district' => 'Jaipur',
            'city_block' => 'Jaipur', 'address' => 'Test address', 'pincode' => '302001',
        ];
    }

    public function test_individual_can_register_without_an_organization(): void
    {
        $this->seed(PlatformRolesSeeder::class);
        $this->postJson('/auth/register/training-center', [
            'center_name' => 'Asha Coaching', 'email' => 'asha@example.com', 'phone' => '9876543210',
            'password' => 'Secret1234', 'password_confirmation' => 'Secret1234',
            'state' => 'Rajasthan', 'district' => 'Jaipur',
        ])->assertCreated();
        $this->assertDatabaseCount('training_partners', 0);
        $this->assertDatabaseHas('training_centers', ['name' => 'Asha Coaching', 'training_partner_id' => null, 'status' => 'active']);
        $this->getJson('/center-api/candidates')->assertOk();
        $this->getJson('/center-api/dashboard')->assertOk();
        $this->getJson('/center-api/identity')->assertOk()->assertJsonPath('center.org_name', 'Asha Coaching');
        $this->getJson('/center-api/candidates/invite')->assertOk();
        $this->getJson('/center-api/onboarding')->assertOk()
            ->assertJsonPath('center.spoc_phone', '9876543210')
            ->assertJsonPath('center.spoc_email', 'asha@example.com');
        $this->putJson('/center-api/onboarding', $this->profile())->assertOk()->assertJsonPath('step', 3);
    }

    public function test_center_completes_without_course_legal_or_transport_fields(): void
    {
        $this->registerCenter();
        $this->putJson('/center-api/onboarding', $this->profile())->assertOk()->assertJsonPath('step', 3);
        $this->assertDatabaseHas('training_centers', ['onboarding_step' => 3, 'center_type' => 'Individual']);
        $this->assertDatabaseCount('training_partners', 0);
        $this->assertDatabaseHas('training_centers', ['state' => 'Rajasthan', 'district' => 'Jaipur', 'training_partner_id' => null]);
        $this->putJson('/center-api/onboarding', [...$this->profile(), 'spoc_name' => 'Updated owner'])
            ->assertOk();
        $this->assertDatabaseHas('training_centers', ['spoc_name' => 'Updated owner']);
    }

    public function test_required_contact_and_address_fields_are_validated(): void
    {
        $this->registerCenter();
        $response = $this->putJson('/center-api/onboarding', [
            'step' => 3, 'completed_step' => 2, 'draft' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['spoc_name', 'spoc_phone', 'address', 'pincode']);
        $this->assertArrayNotHasKey('job_role', $response->json('errors'));
        $this->assertArrayNotHasKey('authorized_person', $response->json('errors'));
        $this->assertDatabaseHas('training_centers', ['onboarding_step' => 0]);
        $this->putJson('/center-api/onboarding', [...$this->profile(), 'spoc_phone' => 'abc', 'pincode' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['spoc_phone', 'pincode']);
    }

    public function test_incomplete_draft_is_saved_without_advancing(): void
    {
        $this->registerCenter();
        $this->putJson('/center-api/onboarding', [
            'step' => 0, 'completed_step' => 0, 'draft' => true, 'spoc_name' => 'Draft owner',
        ])->assertOk()->assertJsonPath('step', 0);
        $this->assertDatabaseHas('training_centers', ['spoc_name' => 'Draft owner', 'onboarding_step' => 0]);
    }

    public function test_existing_partial_setup_can_finish_and_removed_fields_are_ignored(): void
    {
        $this->registerCenter();
        \Illuminate\Support\Facades\DB::table('training_centers')->update(['onboarding_step' => 2]);
        $this->putJson('/center-api/onboarding', [...$this->profile(),
            'project_name' => 'Unrelated course', 'nearby_bus_stand' => 'Unnecessary',
        ])->assertOk()->assertJsonPath('step', 3);
        $this->assertDatabaseHas('training_centers', ['onboarding_step' => 3, 'project_name' => null, 'nearby_bus_stand' => null]);
    }
}
