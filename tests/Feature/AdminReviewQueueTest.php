<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(PlatformRolesSeeder::class); }

    private function admin(string $role = 'admin'): User {
        $user = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);
        return $user;
    }
    private function center(array $extra = []): int {
        return DB::table('training_centers')->insertGetId([...[
            'code' => 'CTR-'.uniqid(), 'name' => 'Review Center', 'status' => 'pending',
            'center_type' => 'Individual', 'spoc_name' => 'Contact', 'spoc_phone' => '9876543210', 'spoc_email' => 'contact@example.com',
            'state' => 'Rajasthan', 'district' => 'Jaipur', 'city_block' => 'Jaipur', 'address' => 'Center address', 'pincode' => '302001',
            'created_at' => now(), 'updated_at' => now(),
        ], ...$extra]);
    }
    private function employer(array $extra = []): int {
        return DB::table('employers')->insertGetId([...[
            'code' => 'EMP-'.uniqid(), 'name' => 'Review Employer', 'legal_name' => 'Review Employer Ltd', 'status' => 'pending',
            'gstin' => '27ABCDE1234F1Z5', 'address' => 'Company address', 'pincode' => '411001', 'document_path' => 'private/proof.pdf',
            'created_at' => now(), 'updated_at' => now(),
        ], ...$extra]);
    }
    public function test_counts_include_legacy_unverified_centers_and_exclude_closed_and_deleted_profiles(): void {
        $this->center(); $this->center(['status' => 'active']); $this->center(['status' => 'verified', 'verified_at' => now()]);
        $this->center(['deleted_at' => now()]); $this->employer(); $this->employer(['status' => 'rejected']);
        $json = $this->actingAs($this->admin())->getJson('/admin-api/review-notifications')->assertOk()
            ->assertJsonPath('counts.centers', 2)->assertJsonPath('counts.employers', 1)->assertJsonPath('unread_count', 3)
            ->assertJsonCount(3, 'items')->json();
        foreach ($json['items'] as $item) {
            $this->assertArrayNotHasKey('document_path', $item);
            $this->assertArrayNotHasKey('spoc_phone', $item);
            $this->assertStringContainsString('status=pending&review=', $item['url']);
        }
        $this->getJson('/admin-api/training-centers')->assertJsonPath('summary.pending', 2);
    }
    public function test_unread_state_is_per_admin_and_new_profile_versions_reappear(): void {
        $id = $this->employer(); $first = $this->admin(); $second = $this->admin();
        $item = $this->actingAs($first)->getJson('/admin-api/review-notifications')->json('items.0');
        $this->postJson('/admin-api/review-notifications/read', ['items' => [$item]])->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('counts.employers', 1);
        $this->actingAs($second)->getJson('/admin-api/review-notifications')->assertJsonPath('unread_count', 1);
        DB::table('employers')->where('id', $id)->update(['updated_at' => now()->addMinute()]);
        $this->actingAs($first)->postJson('/admin-api/review-notifications/read', ['items' => [$item]])->assertOk()->assertJsonPath('unread_count', 1);
    }
    public function test_permissions_scope_notifications_and_prevent_review_and_read_of_other_domains(): void {
        $this->center(); $this->employer(); $employer = $this->admin('employer');
        $this->actingAs($employer)->getJson('/admin-api/review-notifications')->assertForbidden();
        $viewer = User::factory()->create();
        DB::table('user_permissions')->insert(['user_id' => $viewer->id, 'permission_id' => DB::table('permissions')->where('key', 'partners.view')->value('id'), 'allowed' => true]);
        $this->actingAs($viewer)->getJson('/admin-api/review-notifications')->assertOk()->assertJsonPath('counts.employers', 0)->assertJsonCount(1, 'items')->assertJsonPath('permissions.manage_centers', false);
        $this->patchJson('/admin-api/training-centers/1/review', ['status' => 'verified'])->assertForbidden();
        $this->postJson('/admin-api/review-notifications/read', ['items' => [['kind' => 'employers', 'id' => 1, 'version' => now()->toDateTimeString()]]])->assertForbidden();
    }
    public function test_center_review_requires_complete_profile_and_rejection_remarks_and_is_audited(): void {
        $id = $this->center(['spoc_name' => null]); $this->actingAs($this->admin());
        $this->patchJson("/admin-api/training-centers/$id/review", ['status' => 'verified'])->assertUnprocessable();
        $this->patchJson("/admin-api/training-centers/$id/review", ['status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('review_remarks');
        DB::table('training_centers')->where('id', $id)->update(['spoc_name' => 'Contact']);
        $this->patchJson("/admin-api/training-centers/$id/review", ['status' => 'verified', 'review_remarks' => 'Contact and address checked'])->assertOk()->assertJsonPath('center.status', 'verified');
        $this->getJson('/admin-api/review-notifications')->assertJsonPath('counts.centers', 0)->assertJsonPath('unread_count', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'training_center.verified', 'subject_id' => $id]);
    }
    public function test_new_center_is_pending_and_approved_profile_edits_require_fresh_review(): void {
        $this->postJson('/auth/register/training-center', ['center_name' => 'New Center', 'email' => 'new@example.com', 'phone' => '9876543210', 'password' => 'Secret1234', 'password_confirmation' => 'Secret1234', 'state' => 'Rajasthan', 'district' => 'Jaipur'])->assertCreated();
        $id = DB::table('training_centers')->value('id');
        $this->assertDatabaseHas('training_centers', ['id' => $id, 'status' => 'pending']);
        DB::table('training_centers')->where('id', $id)->update(['status' => 'verified', 'verified_at' => now()]);
        $this->putJson('/center-api/onboarding', ['step' => 0, 'completed_step' => 0, 'draft' => true, 'spoc_name' => 'Changed contact'])->assertOk();
        $this->assertDatabaseHas('training_centers', ['id' => $id, 'status' => 'pending', 'verified_at' => null]);
    }
}
