<?php
namespace Tests\Feature;
use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminJobTest extends TestCase
{
 use RefreshDatabase;
 private function staff(string $role='admin'): User {
  $this->seed(PlatformRolesSeeder::class);$user=User::factory()->create();
  DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>DB::table('roles')->where('key',$role)->value('id')]);return $user;
 }
 private function job(array $overrides=[]): int {
  $e=DB::table('employers')->insertGetId(['code'=>'TEST','name'=>'Employer','legal_name'=>'Employer Ltd','status'=>'verified']);
  return DB::table('job_posts')->insertGetId(array_merge(['employer_id'=>$e,'title'=>'Test job','status'=>'paused','description'=>'Description','industry'=>'Retail','state'=>'Rajasthan','district'=>'Jaipur','location'=>'Jaipur','department'=>'Sales','education'=>'12th','experience'=>'Fresher','responsibilities'=>'Sales','skills'=>'Sales','job_type'=>'Full-Time','workplace_type'=>'On-site','salary_type'=>'Fixed','openings'=>2,'application_deadline'=>now()->addMonth()->toDateString()],$overrides));
 }
 public function test_status_changes_persist_and_are_audited_and_conflicts_are_rejected(): void {
  $user=$this->staff();$id=$this->job();
  $this->actingAs($user)->patchJson("/admin-api/jobs/$id/status",['status'=>'active','expected_status'=>'paused'])->assertOk();
  $this->assertDatabaseHas('job_posts',['id'=>$id,'status'=>'active']);$this->assertNotNull(DB::table('job_posts')->find($id)->published_at);
  $this->patchJson("/admin-api/jobs/$id/status",['status'=>'paused','expected_status'=>'paused'])->assertConflict();
  $this->patchJson("/admin-api/jobs/$id/status",['status'=>'paused','expected_status'=>'active'])->assertOk();
  $this->assertDatabaseHas('audit_logs',['action'=>'admin.job_status_changed','subject_id'=>$id,'actor_user_id'=>$user->id]);
 }
 public function test_invalid_or_unverified_jobs_cannot_be_activated(): void {
  $user=$this->staff();$id=$this->job(['description'=>null]);$this->actingAs($user);
  $this->patchJson("/admin-api/jobs/$id/status",['status'=>'active','expected_status'=>'paused'])->assertUnprocessable();
  DB::table('job_posts')->where('id',$id)->update(['description'=>'Ready']);DB::table('employers')->update(['status'=>'pending']);
  $this->patchJson("/admin-api/jobs/$id/status",['status'=>'active','expected_status'=>'paused'])->assertUnprocessable();
  DB::table('employers')->update(['status'=>'verified']);DB::table('job_posts')->where('id',$id)->update(['application_deadline'=>now()->subDay()->toDateString()]);
  $this->patchJson("/admin-api/jobs/$id/status",['status'=>'active','expected_status'=>'paused'])->assertUnprocessable();
  $this->assertDatabaseHas('job_posts',['id'=>$id,'status'=>'paused']);
 }
 public function test_delete_requires_confirmation_and_preserves_record_but_excludes_it_from_list(): void {
  $user=$this->staff();$id=$this->job();$this->actingAs($user);
  $this->deleteJson("/admin-api/jobs/$id")->assertUnprocessable();
  $this->deleteJson("/admin-api/jobs/$id",['confirmation'=>'DELETE'])->assertOk();
  $this->assertSoftDeleted('job_posts',['id'=>$id]);
  $this->getJson('/admin-api/jobs')->assertOk()->assertJsonCount(0,'jobs');
  $this->patchJson("/admin-api/jobs/$id/status",['status'=>'active','expected_status'=>'closed'])->assertNotFound();
  $this->assertDatabaseHas('audit_logs',['action'=>'admin.job_deleted','subject_id'=>$id]);
 }
 public function test_employers_cannot_manage_global_jobs_and_view_only_staff_cannot_mutate(): void {
  $employer=$this->staff('employer');$id=$this->job();$this->actingAs($employer);
  $this->getJson('/admin-api/jobs')->assertForbidden();
  $this->patchJson("/admin-api/jobs/$id/status",['status'=>'active','expected_status'=>'paused'])->assertForbidden();
  $this->deleteJson("/admin-api/jobs/$id",['confirmation'=>'DELETE'])->assertForbidden();
  $agent=$this->staff('agent');$this->actingAs($agent)->getJson('/admin-api/jobs')->assertOk()->assertJsonPath('can_manage',false);
  $this->deleteJson("/admin-api/jobs/$id",['confirmation'=>'DELETE'])->assertForbidden();
 }
}
