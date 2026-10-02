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
}
