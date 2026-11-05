<?php
namespace Tests\Feature;
use App\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class CandidatePreferencesTest extends TestCase
{
    use RefreshDatabase;
    private function candidate(string $phone = '9876543222'): Candidate
    {
        return Candidate::forceCreate(['profile_status'=>'active','first_name'=>'Asha','last_name'=>'Rao','whatsapp'=>$phone,'password'=>'test-password','gender'=>'Female','age'=>24,'qualification'=>'ITI','state'=>'Maharashtra','district'=>'Pune','current_location'=>'Pune','permanent_location'=>'Pune','preferred_locations'=>['Pune'],'relocation_preference'=>'No','skills'=>'Machine operation','experience_type'=>'Fresher','expected_monthly_salary'=>15000,'availability'=>'Yes','preferred_industry'=>'Manufacturing','languages'=>['Hindi'],'training_status'=>'Not enrolled','whatsapp_consent'=>true,'terms_accepted_at'=>now(),'privacy_accepted_at'=>now()]);
    }


    public function test_preferences_are_authenticated_validated_scoped_and_recorded(): void
    {
        $payload=['kind'=>'preferences','call_consent'=>true,'sms_consent'=>false,'whatsapp_consent'=>false,'email_consent'=>true];
        $this->postJson('/candidate-api/employment/respond',$payload)->assertUnauthorized();
        $candidate=$this->candidate();$other=$this->candidate('9876543223');
        $this->actingAs($candidate,'candidate')->postJson('/candidate-api/employment/respond',array_merge($payload,['call_consent'=>'invalid']))->assertUnprocessable();
        $this->postJson('/candidate-api/employment/respond',$payload)->assertOk();
        $this->assertDatabaseHas('candidates',['id'=>$candidate->id,'call_consent'=>true,'whatsapp_consent'=>false,'email_consent'=>true]);
        $this->assertDatabaseHas('candidates',['id'=>$other->id,'whatsapp_consent'=>true]);
        $this->assertDatabaseHas('candidate_consents',['candidate_id'=>$candidate->id,'purpose'=>'whatsapp','granted'=>false,'source'=>'candidate_preferences']);
        $count=DB::table('candidate_consents')->count();
        $this->postJson('/candidate-api/employment/respond',$payload)->assertOk();
        $this->assertDatabaseCount('candidate_consents',$count);
        $this->getJson('/candidate-api/employment')->assertOk()->assertJsonPath('candidate.whatsapp_consent',false);
        $candidate->update(['profile_status'=>'inactive']);
        $this->postJson('/candidate-api/employment/respond',$payload)->assertForbidden();
    }
    public function test_interest_is_independent_of_channel_preferences(): void
    {
        $candidate=$this->candidate();
        $this->actingAs($candidate,'candidate')->postJson('/candidate-api/employment/respond',['kind'=>'interest','status'=>'not_interested'])->assertOk();
        $this->assertDatabaseHas('candidates',['id'=>$candidate->id,'job_interest_status'=>'not_interested','whatsapp_consent'=>true]);
    }
}
