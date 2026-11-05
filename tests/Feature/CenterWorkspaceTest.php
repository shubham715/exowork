<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class CenterWorkspaceTest extends TestCase {
 use RefreshDatabase;
 private function center($code){return DB::table('training_centers')->insertGetId(['code'=>$code,'name'=>$code,'state'=>'Rajasthan','district'=>'Jaipur','status'=>'active']);}
 private function candidate($center,$batch,$phone){return DB::table('candidates')->insertGetId(['first_name'=>'Test','last_name'=>'Learner','whatsapp'=>$phone,'password'=>'unused','gender'=>'Female','age'=>22,'qualification'=>'12th','state'=>'Rajasthan','district'=>'Jaipur','current_location'=>'Jaipur','permanent_location'=>'Jaipur','preferred_locations'=>'[]','relocation_preference'=>'Yes','skills'=>'Training','experience_type'=>'Fresher','expected_monthly_salary'=>10000,'availability'=>'Yes','preferred_industry'=>'Retail','languages'=>'[]','training_status'=>'Ongoing','training_center_id'=>$center,'training_batch_id'=>$batch]);}
 public function test_pipeline_uses_owned_records_distinct_counts_date_boundaries_and_saved_joining_dates(){
  $this->travelTo(\Carbon\Carbon::parse('2026-10-01 06:00:00','UTC'));
  $user=User::factory()->create();$center=$this->center('OWN');$other=$this->center('OTHER');
  DB::table('organization_memberships')->insert(['user_id'=>$user->id,'training_center_id'=>$center,'status'=>'active']);
  $batch=DB::table('training_batches')->insertGetId(['training_center_id'=>$center,'code'=>'B1','job_role'=>'Retail','ends_on'=>'2026-10-30','status'=>'active']);
  foreach(['2026-10-03','2026-10-15','2026-10-31'] as $i=>$end) DB::table('training_batches')->insert(['training_center_id'=>$center,'code'=>'NEXT'.$i,'job_role'=>'Retail','ends_on'=>$end]);
  $foreignBatch=DB::table('training_batches')->insertGetId(['training_center_id'=>$other,'code'=>'F','job_role'=>'Retail','ends_on'=>'2026-10-02']);
  $candidate=$this->candidate($center,$batch,'9876543201');$foreign=$this->candidate($other,$foreignBatch,'9876543202');
  $employer=DB::table('employers')->insertGetId(['code'=>'E','name'=>'Employer','status'=>'active']);
  $job=DB::table('job_posts')->insertGetId(['employer_id'=>$employer,'title'=>'Retail','industry'=>'Retail','status'=>'published']);
  $app=DB::table('applications')->insertGetId(['candidate_id'=>$candidate,'job_post_id'=>$job]);$foreignApp=DB::table('applications')->insertGetId(['candidate_id'=>$foreign,'job_post_id'=>$job]);
  foreach(['2026-10-02 06:00:00','2026-10-03 06:00:00','2026-10-30 20:00:00'] as $when) DB::table('interviews')->insert(['application_id'=>$app,'scheduled_at'=>$when,'status'=>'scheduled']);
  DB::table('interviews')->insert(['application_id'=>$foreignApp,'scheduled_at'=>'2026-10-02 06:00:00']);
  DB::table('interviews')->insert(['application_id'=>$app,'scheduled_at'=>'2026-10-04 06:00:00','status'=>'cancelled']);
  $placement=DB::table('placement_outcomes')->insertGetId(['application_id'=>$app,'status'=>'joining_pending']);
  $foreignPlacement=DB::table('placement_outcomes')->insertGetId(['application_id'=>$foreignApp,'status'=>'joining_pending','expected_joining_on'=>'2026-10-04']);
  $this->actingAs($user)->getJson('/center-api/workspace')->assertOk()->assertJsonPath('pipeline.to','2026-10-30')->assertJsonPath('pipeline.metrics.completing',1)->assertJsonPath('pipeline.metrics.interviews',1)->assertJsonPath('pipeline.metrics.joining',0)->assertJsonPath('pipeline.metrics.undated',1)->assertJsonCount(3,'pipeline.batches')->assertJsonPath('pipeline.batches.0.code','NEXT0')->assertJsonCount(4,'interviews')->assertJsonCount(1,'placements')->assertJsonPath('pipeline.weeks.4.interviews',0);
  $this->patchJson('/center-api/placements/'.$foreignPlacement.'/joining',['expected_joining_on'=>'2026-10-04'])->assertNotFound();
  $this->patchJson('/center-api/placements/'.$placement.'/joining',['expected_joining_on'=>'invalid'])->assertUnprocessable();
  $this->patchJson('/center-api/placements/'.$placement.'/joining',['expected_joining_on'=>'2026-10-30'])->assertOk();
  $this->getJson('/center-api/workspace')->assertJsonPath('pipeline.metrics.joining',1)->assertJsonPath('pipeline.metrics.undated',0)->assertJsonPath('pipeline.weeks.4.joining',1);
  DB::table('placement_outcomes')->where('id',$placement)->update(['joined_on'=>'2026-10-30','status'=>'joined']);
  $this->patchJson('/center-api/placements/'.$placement.'/joining',['expected_joining_on'=>'2026-11-01'])->assertUnprocessable();
  $this->getJson('/center-api/workspace')->assertJsonPath('pipeline.metrics.joining',0)->assertJsonPath('performance.joined',1);
 }
 public function test_guests_and_users_without_membership_cannot_access_workspace(){ $this->getJson('/center-api/workspace')->assertUnauthorized();$this->actingAs(User::factory()->create())->getJson('/center-api/workspace')->assertForbidden(); }
 public function test_batch_stats_are_scoped_and_reflect_actual_student_milestones(): void {
  $this->seed(\Database\Seeders\PlatformRolesSeeder::class);
  $this->travelTo(\Carbon\Carbon::parse('2026-11-05 06:00:00', 'UTC'));
  $user=User::factory()->create();
  DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>DB::table('roles')->where('key','training_partner')->value('id')]);
  $center=$this->center('STATS');$other=$this->center('FOREIGN');
  DB::table('organization_memberships')->insert(['user_id'=>$user->id,'training_center_id'=>$center,'status'=>'active']);
  $batch=DB::table('training_batches')->insertGetId(['training_center_id'=>$center,'code'=>'STATS-1','job_role'=>'Retail','starts_on'=>'2026-10-01','ends_on'=>'2026-11-15','status'=>'active']);
  $foreignBatch=DB::table('training_batches')->insertGetId(['training_center_id'=>$other,'code'=>'PRIVATE','job_role'=>'Retail','status'=>'active']);
  $candidate=$this->candidate($center,$batch,'9876543281');
  $foreign=$this->candidate($other,$batch,'9876543282');
  DB::table('candidates')->where('id',$candidate)->update(['candidate_code'=>'EXO-CAN-TEST','created_at'=>now()]);
  $this->actingAs($user)->getJson('/center-api/batch-stats')->assertOk()->assertJsonCount(1,'batches')->assertJsonPath('batches.0.students_count',1)->assertJsonCount(0,'students');
  $this->getJson('/center-api/batch-stats?batch_id='.$foreignBatch)->assertNotFound();
  $this->getJson('/center-api/batch-stats?batch_id=invalid')->assertUnprocessable();
  $url='/center-api/batch-stats?batch_id='.$batch;
  $this->getJson($url)->assertOk()->assertJsonCount(1,'students')->assertJsonPath('students.0.training_state','finishing_soon')->assertJsonPath('students.0.steps',[true,true,false,false,false,false]);
  $employer=DB::table('employers')->insertGetId(['code'=>'STATS-EMP','name'=>'Employer','status'=>'verified']);
  $job=DB::table('job_posts')->insertGetId(['employer_id'=>$employer,'title'=>'Retail','status'=>'active']);
  $application=DB::table('applications')->insertGetId(['candidate_id'=>$candidate,'job_post_id'=>$job]);
  $placement=DB::table('placement_outcomes')->insertGetId(['application_id'=>$application,'status'=>'joined','joined_on'=>'2026-11-01']);
  DB::table('candidates')->where('id',$candidate)->update(['training_status'=>'Completed']);
  $this->getJson($url)->assertJsonPath('students.0.steps',[true,true,true,true,true,true])->assertJsonPath('summary.working',1)->assertJsonPath('students.0.completed_steps',6);
  DB::table('retention_responses')->insert(['placement_outcome_id'=>$placement,'day'=>3,'status'=>'still_employed']);
  DB::table('retention_responses')->insert(['placement_outcome_id'=>$placement,'day'=>7,'status'=>'left']);
  $this->getJson($url)->assertJsonPath('students.0.steps.5',false)->assertJsonPath('summary.working',0)->assertJsonPath('summary.hired',1);
  DB::table('training_batches')->where('id',$batch)->update(['starts_on'=>'2026-12-01','ends_on'=>'2026-12-31']);
  $this->getJson($url)->assertJsonPath('students.0.training_state','upcoming');
  DB::table('training_batches')->where('id',$batch)->update(['status'=>'cancelled']);
  $this->getJson($url)->assertJsonPath('students.0.training_state','cancelled');
  DB::table('candidates')->where('id',$candidate)->update(['deleted_at'=>now()]);
  $this->getJson($url)->assertJsonCount(0,'students')->assertJsonPath('summary.students',0);
  $this->actingAs(User::factory()->create())->getJson('/center-api/batch-stats')->assertForbidden();
 }
 public function test_admin_batch_stats_select_partner_then_batch_and_exclude_other_partners(): void {
  $this->seed(\Database\Seeders\PlatformRolesSeeder::class);
  $admin=User::factory()->create(['status'=>'active']);
  DB::table('user_roles')->insert(['user_id'=>$admin->id,'role_id'=>DB::table('roles')->where('key','admin')->value('id')]);
  $partner=DB::table('training_partners')->insertGetId(['code'=>'PARTNER-A','name'=>'Partner A']);
  $other=DB::table('training_partners')->insertGetId(['code'=>'PARTNER-B','name'=>'Partner B']);
  $first=$this->center('FIRST');$second=$this->center('SECOND');$foreign=$this->center('FOREIGN-STATS');$independent=$this->center('INDEPENDENT');
  DB::table('training_centers')->whereIn('id',[$first,$second])->update(['training_partner_id'=>$partner]);
  DB::table('training_centers')->where('id',$foreign)->update(['training_partner_id'=>$other]);
  $batches=[];
  foreach ([$first,$second,$foreign,$independent] as $i=>$center) $batches[]=DB::table('training_batches')->insertGetId(['training_center_id'=>$center,'code'=>'BATCH-'.$i,'job_role'=>'Retail','status'=>'active']);
  $candidate=$this->candidate($first,$batches[0],'9876543291');$this->candidate($foreign,$batches[2],'9876543292');
  $url='/admin-api/batch-stats';
  $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonCount(3,'partners')->assertJsonCount(0,'batches')->assertJsonCount(0,'students');
  $this->getJson($url.'?partner=partner:'.$partner)->assertOk()->assertJsonCount(2,'batches')->assertJsonCount(0,'students');
  $response=$this->getJson($url.'?partner=partner:'.$partner.'&batch_id='.$batches[0])->assertOk()->assertJsonCount(1,'students')->assertJsonPath('students.0.id',$candidate)->assertJsonPath('summary.students',1);
  $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
  $this->getJson($url.'?partner=partner:'.$partner.'&batch_id='.$batches[2])->assertNotFound();
  $this->getJson($url.'?batch_id='.$batches[0])->assertUnprocessable();
  $this->getJson($url.'?partner=bad')->assertUnprocessable();
  $this->getJson($url.'?partner=center:'.$independent)->assertOk()->assertJsonCount(1,'batches');
  DB::table('training_centers')->where('id',$second)->update(['deleted_at'=>now()]);
  $this->getJson($url.'?partner=partner:'.$partner)->assertJsonCount(1,'batches');
  DB::table('training_partners')->where('id',$partner)->update(['deleted_at'=>now()]);
  $this->getJson($url.'?partner=partner:'.$partner)->assertNotFound();
  $viewer=User::factory()->create(['status'=>'active']);
  DB::table('user_roles')->insert(['user_id'=>$viewer->id,'role_id'=>DB::table('roles')->where('key','training_partner')->value('id')]);
  $this->actingAs($viewer)->getJson($url)->assertForbidden();
  $this->actingAs($admin);DB::table('user_permissions')->insert(['user_id'=>$admin->id,'permission_id'=>DB::table('permissions')->where('key','candidates.view')->value('id'),'allowed'=>false]);
  $this->getJson($url)->assertForbidden();
 }
}
