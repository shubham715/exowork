<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\Candidate;
use App\Services\DemoDataService;
use App\Services\JobEligibility;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class DemoDataManagementTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User {
        return User::find(DB::table('demo_credentials')->where('section', 'admin')->value('entity_id'));
    }
    public function test_network_is_linked_and_jobs_match_and_append_is_unique(): void {
        $this->seed(DemoDataSeeder::class);
        $this->assertDatabaseCount('candidates', 50);
        $this->assertDatabaseCount('employers', 5);
        $this->assertDatabaseCount('training_centers', 5);
        $this->assertDatabaseCount('training_batches', 15);
        $this->assertDatabaseCount('job_posts', 20);
        $this->assertSame(40, DB::table('candidates')->whereNotNull('training_center_id')->count());
        $this->assertSame(10, DB::table('candidates')->whereNull('training_center_id')->count());
        foreach (DB::table('applications')->get() as $application) {
            $this->assertTrue(app(JobEligibility::class)->matches(DB::table('job_posts')->find($application->job_post_id), Candidate::find($application->candidate_id)));
        }
        $this->actingAs($this->admin())->getJson('/admin-api/data-management')->assertOk()->assertJsonPath('counts.candidates.auto', 50)->assertJsonCount(61, 'credentials');
        app(DemoDataService::class)->generate();
        $this->assertDatabaseCount('candidates', 100);
        $this->assertSame(1, DB::table('demo_credentials')->where('section', 'admin')->count());
    }
    public function test_cleanup_preserves_admin_and_removes_dependencies(): void {
        $this->seed(DemoDataSeeder::class);
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/admin-api/data-management/delete', ['section' => 'all', 'scope' => 'all', 'confirmation' => 'DELETE DATA'])->assertOk();
        foreach (['candidates','employers','training_centers','training_batches','job_posts','applications','interviews','training_partners','organization_memberships','candidate_batch_assignments'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('demo_credentials', 1);
    }
    public function test_center_cleanup_detaches_candidates_and_access_is_restricted(): void {
        $this->seed(DemoDataSeeder::class);
        $employer = User::find(DB::table('demo_credentials')->where('section', 'employers')->value('entity_id'));
        $this->actingAs($employer)->getJson('/admin-api/data-management')->assertForbidden();
        $this->actingAs($this->admin())->postJson('/admin-api/data-management/delete', ['section' => 'training_centers', 'scope' => 'dummy', 'confirmation' => 'DELETE DATA'])->assertOk();
        $this->assertDatabaseCount('candidates', 50);
        $this->assertSame(0, DB::table('candidates')->whereNotNull('training_center_id')->count());
        $this->assertDatabaseCount('training_batches', 0);
        $this->assertDatabaseCount('candidate_batch_assignments', 0);
    }
    public function test_replace_and_credentials_download(): void {
        $this->seed(DemoDataSeeder::class);
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/admin-api/data-management/generate', ['mode' => 'replace', 'confirmation' => 'CREATE DEMO DATA'])->assertOk()->assertJsonPath('counts.candidates.total', 50);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->get('/admin-api/data-management/credentials')->assertOk()->assertDownload('demo-credentials.csv');
    }
}
