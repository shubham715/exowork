<?php
namespace Tests\Feature;

use App\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CandidateDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function candidate(string $phone = '9876543222'): Candidate
    {
        return Candidate::forceCreate(['profile_status'=>'active','first_name'=>'Asha','last_name'=>'Rao','whatsapp'=>$phone,'password'=>'test-password','gender'=>'Female','age'=>24,'qualification'=>'ITI','state'=>'Maharashtra','district'=>'Pune','current_location'=>'Pune','permanent_location'=>'Pune','preferred_locations'=>['Pune'],'relocation_preference'=>'No','skills'=>'Machine operation','experience_type'=>'Fresher','expected_monthly_salary'=>15000,'availability'=>'Yes','preferred_industry'=>'Manufacturing','languages'=>['Hindi'],'training_status'=>'Not enrolled','whatsapp_consent'=>true,'terms_accepted_at'=>now(),'privacy_accepted_at'=>now()]);
    }

    private function job(array $overrides = []): int
    {
        $employer = DB::table('employers')->insertGetId(['name'=>'Private Employer','code'=>uniqid('EMP'),'status'=>'verified']);
        return DB::table('job_posts')->insertGetId(array_merge(['employer_id'=>$employer,'title'=>'Operator','status'=>'active','district'=>'Pune','state'=>'Maharashtra','education'=>'ITI','skills'=>'Machine operation','experience'=>'Fresher','salary_max'=>20000,'workplace_type'=>'On-site'], $overrides));
    }

    public function test_dashboard_requires_candidate_authentication(): void
    {
        $this->getJson('/candidate-api/dashboard')->assertUnauthorized();
    }

    public function test_empty_account_has_real_zero_counts_and_profile_checks(): void
    {
        $candidate=$this->candidate();
        $this->actingAs($candidate,'candidate')->getJson('/candidate-api/dashboard')->assertOk()
            ->assertJsonPath('summary.applications',0)->assertJsonPath('summary.upcoming_interviews',0)
            ->assertJsonPath('profile.completion',100)->assertJsonPath('profile.resume_available',false)
            ->assertJsonPath('training_center',null)->assertJsonPath('next_interview',null)->assertJsonCount(0,'jobs');
        $candidate->update(['skills'=>'']);
        $this->getJson('/candidate-api/dashboard')->assertJsonPath('profile.completion',80);
    }

    public function test_dashboard_scopes_records_and_excludes_unavailable_jobs(): void
    {
        $this->travelTo(now()->setDate(2026,10,29)->setTime(10,0));
        $candidate=$this->candidate();$other=$this->candidate('9876543223');
        $applied=$this->job();$eligible=$this->job();$this->job(['status'=>'draft']);$this->job(['application_deadline'=>'2026-10-28']);$this->job(['deleted_at'=>now()]);$this->job(['district'=>'Delhi']);
        $unverified=$this->job();DB::table('employers')->where('id',DB::table('job_posts')->where('id',$unverified)->value('employer_id'))->update(['status'=>'pending']);
        $a=DB::table('applications')->insertGetId(['candidate_id'=>$candidate->id,'job_post_id'=>$applied,'status'=>'interested']);
        $b=DB::table('applications')->insertGetId(['candidate_id'=>$other->id,'job_post_id'=>$eligible,'status'=>'interested']);
        foreach ([$a,$b] as $id) DB::table('interviews')->insert(['application_id'=>$id,'scheduled_at'=>now()->addDay(),'status'=>'scheduled','location'=>'Interview office']);
        DB::table('interviews')->insert(['application_id'=>$a,'scheduled_at'=>now()->subDay(),'status'=>'scheduled']);
        DB::table('interviews')->insert(['application_id'=>$a,'scheduled_at'=>now()->addHour(),'status'=>'cancelled']);
        $p=DB::table('placement_outcomes')->insertGetId(['application_id'=>$a,'status'=>'joined','joined_on'=>'2026-10-20']);
        DB::table('retention_responses')->insert(['placement_outcome_id'=>$p,'day'=>3,'status'=>'still_employed']);
        $response=$this->actingAs($candidate,'candidate')->getJson('/candidate-api/dashboard')->assertOk()
            ->assertJsonPath('summary.applications',1)->assertJsonPath('summary.upcoming_interviews',1)
            ->assertJsonPath('summary.joined',1)->assertJsonPath('summary.check_ins_due',1)
            ->assertJsonCount(1,'recent_applications')->assertJsonCount(1,'jobs')->assertJsonPath('jobs.0.id',$eligible);
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('employer_id',$response->json('jobs.0'));
        $this->assertStringEndsWith('+00:00',$response->json('next_interview.scheduled_at'));
        $candidate->update(['profile_status'=>'inactive']);
        $this->getJson('/candidate-api/dashboard')->assertForbidden();
    }

    public function test_career_milestones_follow_training_joining_and_latest_retention(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-05 10:00:00', 'UTC'));
        $candidate = $this->candidate();
        $this->actingAs($candidate, 'candidate')->getJson('/candidate-api/dashboard')->assertOk()
            ->assertJsonPath('journey.steps', [true, true, false, false, false, false])
            ->assertJsonPath('journey.training_state', 'not_required')->assertJsonPath('journey.total_steps', 5);
        $center = DB::table('training_centers')->insertGetId(['code' => 'JOURNEY', 'name' => 'Training center', 'status' => 'verified', 'state' => 'Maharashtra', 'district' => 'Pune']);
        $batch = DB::table('training_batches')->insertGetId(['training_center_id' => $center, 'code' => 'JOURNEY-01', 'job_role' => 'Operator', 'status' => 'active', 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-15']);
        $candidate->update(['training_center_id' => $center, 'training_batch_id' => $batch, 'training_status' => 'Ongoing']);
        $this->getJson('/candidate-api/dashboard')->assertJsonPath('journey.training_state', 'finishing_soon')->assertJsonPath('journey.total_steps', 6)->assertJsonPath('journey.batch_code', 'JOURNEY-01');
        $application = DB::table('applications')->insertGetId(['candidate_id' => $candidate->id, 'job_post_id' => $this->job()]);
        $placement = DB::table('placement_outcomes')->insertGetId(['application_id' => $application, 'status' => 'selected']);
        $this->getJson('/candidate-api/dashboard')->assertJsonPath('journey.steps.3', true)->assertJsonPath('journey.hired', true)->assertJsonPath('journey.working', false);
        DB::table('placement_outcomes')->where('id', $placement)->update(['status' => 'joined', 'joined_on' => '2026-11-01']);
        $candidate->update(['training_status' => 'Completed']);
        $this->getJson('/candidate-api/dashboard')->assertJsonPath('journey.steps', [true, true, true, true, true, true])->assertJsonPath('journey.completed_steps', 6);
        DB::table('retention_responses')->insert(['placement_outcome_id' => $placement, 'day' => 3, 'status' => 'still_employed']);
        DB::table('retention_responses')->insert(['placement_outcome_id' => $placement, 'day' => 7, 'status' => 'left']);
        $this->getJson('/candidate-api/dashboard')->assertJsonPath('journey.hired', true)->assertJsonPath('journey.working', false)->assertJsonPath('journey.completed_steps', 5);
        $candidate->update(['skills' => '']);
        $this->getJson('/candidate-api/dashboard')->assertJsonPath('journey.steps.1', false)->assertJsonPath('journey.completed_steps', 4);
    }
}

