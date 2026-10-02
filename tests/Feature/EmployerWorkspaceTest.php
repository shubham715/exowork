<?php
namespace Tests\Feature;
use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Hash,Storage};
use Tests\TestCase;
class EmployerWorkspaceTest extends TestCase {
 use RefreshDatabase;
 private function payload(): array {return ['account_name'=>'Asha Rao','name'=>'Acme','legal_name'=>'Acme Industries Ltd','industry'=>'Manufacturing','website'=>'https://example.com','description'=>'Manufacturing components.','gstin'=>'27ABCDE1234F1Z5','state'=>'Maharashtra','district'=>'Pune','address'=>'Factory Road 12','pincode'=>'411001','contact_name'=>'Asha Rao','contact_designation'=>'HR Manager','contact_phone'=>'9876543210 ext 12','contact_department'=>'Human Resources','email'=>'owner@example.com','phone'=>'9876543210','password'=>'SecurePass123','password_confirmation'=>'SecurePass123','terms_accepted'=>true,'privacy_accepted'=>true,'document'=>UploadedFile::fake()->create('gst-proof.pdf',50,'application/pdf')];}
 private function register(): void {$this->seed(PlatformRolesSeeder::class);Storage::fake('local');$this->post('/auth/register/employer',$this->payload(),['Accept'=>'application/json'])->assertCreated();}
 private function job(): array{return ['title'=>'Machine Operator','department'=>'Production','industry'=>'Manufacturing','job_type'=>'Full-Time','workplace_type'=>'On-site','state'=>'Maharashtra','district'=>'Pune','location'=>'Factory Road 12','openings'=>5,'description'=>'Operate machines.','responsibilities'=>'Safety and quality checks.','skills'=>'Machine operation','education'=>'ITI','experience'=>'Fresher','salary_type'=>'Fixed','salary_min'=>15000,'salary_max'=>20000,'benefits'=>'PF, ESIC','screening_questions'=>'When can you start?','application_deadline'=>now()->addDays(30)->toDateString(),'status'=>'draft'];}
 public function test_registration_persists_private_profile_and_login(): void {
  $this->register();$user=User::firstOrFail();$e=DB::table('employers')->first();$this->assertAuthenticatedAs($user);$this->assertTrue(Hash::check('SecurePass123',$user->password));$this->assertSame('pending',$e->status);$this->assertNotNull($e->terms_accepted_at);Storage::disk('local')->assertExists($e->document_path);
  $this->getJson('/employer-api/identity')->assertOk()->assertJsonMissingPath('employer.document_path')->assertJsonPath('employer.legal_name','Acme Industries Ltd');
  $this->get('/employer-api/document')->assertOk();
  $this->postJson('/auth/logout')->assertOk();$this->postJson('/auth/login',['role'=>'employer','identifier'=>'owner@example.com','password'=>'SecurePass123'])->assertOk()->assertJsonPath('destination','/employer/dashboard');
  $this->postJson('/auth/logout');$this->postJson('/auth/login',['role'=>'employer','identifier'=>'9876543210','password'=>'SecurePass123'])->assertOk();
 }
 public function test_registration_requires_consent_and_rejects_honeypot_submissions(): void {
  $this->seed(PlatformRolesSeeder::class);Storage::fake('local');$p=$this->payload();unset($p['document']);$p['terms_accepted']=false;$this->postJson('/auth/register/employer',$p)->assertUnprocessable()->assertJsonValidationErrors(['terms_accepted']);
  $spam=$this->payload();$spam['website_check']='spam';$this->post('/auth/register/employer',$spam,['Accept'=>'application/json'])->assertUnprocessable()->assertJsonValidationErrors('website_check');$this->assertDatabaseCount('employers',0);
 }
 public function test_verification_gates_publishing_and_requires_staff_permission(): void {
  $this->register();$owner=User::firstOrFail();$e=DB::table('employers')->first();$job=$this->job();$this->postJson('/employer-api/jobs',$job)->assertOk()->assertJsonPath('summary.draft_jobs',1);
  $id=DB::table('job_posts')->value('id');$job['status']='active';$this->putJson("/employer-api/jobs/$id",$job)->assertUnprocessable()->assertJsonValidationErrors('status');
  $this->patchJson("/admin-api/employers/{$e->id}/review",['status'=>'verified'])->assertForbidden();
  $admin=User::factory()->create();DB::table('user_roles')->insert(['user_id'=>$admin->id,'role_id'=>DB::table('roles')->where('key','admin')->value('id')]);
  $this->actingAs($admin)->patchJson("/admin-api/employers/{$e->id}/review",['status'=>'rejected'])->assertUnprocessable();
  $this->patchJson("/admin-api/employers/{$e->id}/review",['status'=>'verified','review_remarks'=>'Proof checked'])->assertOk();
  $this->actingAs($owner)->putJson("/employer-api/jobs/$id",$job)->assertOk()->assertJsonPath('summary.active_jobs',1);
  $this->assertDatabaseHas('audit_logs',['action'=>'employer.reviewed','subject_id'=>$e->id]);
 }
 public function test_organization_boundaries_and_no_unreleased_candidates(): void {
  $this->register();$this->postJson('/employer-api/jobs',$this->job())->assertOk();$owner=User::firstOrFail();
  $other=User::factory()->create();DB::table('user_roles')->insert(['user_id'=>$other->id,'role_id'=>DB::table('roles')->where('key','employer')->value('id')]);
  $otherEmployer=DB::table('employers')->insertGetId(['name'=>'Other','code'=>'OTHER','status'=>'pending','created_at'=>now(),'updated_at'=>now()]);DB::table('organization_memberships')->insert(['user_id'=>$other->id,'employer_id'=>$otherEmployer,'membership_role'=>'owner','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $job=DB::table('job_posts')->value('id');$this->actingAs($other)->getJson('/employer-api/workspace')->assertOk()->assertJsonCount(0,'jobs')->assertJsonCount(0,'interviews');
  $this->putJson("/employer-api/jobs/$job",$this->job())->assertNotFound();$this->get('/employer-api/document')->assertNotFound();$this->getJson('/admin-api/employers')->assertForbidden();
  $this->actingAs($owner)->getJson('/employer-api/workspace')->assertJsonCount(1,'jobs');
  $center=User::factory()->create();$this->actingAs($center)->getJson('/employer-api/identity')->assertForbidden();
 }
 public function test_job_metrics_report_recorded_views_and_deadline_days(): void {
  $this->register();$this->postJson('/employer-api/jobs',$this->job())->assertOk();
  $this->getJson('/employer-api/workspace')->assertOk()->assertJsonPath('jobs.0.applications_count',0)->assertJsonPath('jobs.0.views_count',0)->assertJsonPath('jobs.0.clicks_count',0)->assertJsonPath('jobs.0.days_remaining',30);
  DB::table('job_posts')->update(['application_deadline'=>now()->subDays(2)->toDateString()]);
  $this->getJson('/employer-api/workspace')->assertOk()->assertJsonPath('jobs.0.days_remaining',0);
  DB::table('job_posts')->update(['application_deadline'=>null]);
  $this->getJson('/employer-api/workspace')->assertOk()->assertJsonPath('jobs.0.days_remaining',null);
 }
 public function test_profile_change_requires_reverification(): void {
  $this->register();DB::table('employers')->update(['status'=>'verified']);$profile=array_intersect_key($this->payload(),array_flip(['name','legal_name','industry','website','description','gstin','state','district','address','pincode','contact_name','contact_designation','contact_phone','contact_department']));
  $this->postJson('/employer-api/profile',$profile)->assertOk()->assertJsonPath('employer.status','verified');$profile['legal_name']='Acme Updated Ltd';$this->postJson('/employer-api/profile',$profile)->assertOk()->assertJsonPath('employer.status','pending');
 }
 public function test_password_recovery_uses_single_use_tokens_and_generic_email_response(): void {
  $this->register(); $user=User::firstOrFail();
  \Illuminate\Support\Facades\Notification::fake();
  $this->postJson('/auth/employer/password/email',['email'=>$user->email])->assertOk();
  \Illuminate\Support\Facades\Notification::assertSentTo($user,\Illuminate\Auth\Notifications\ResetPassword::class);
  $this->postJson('/auth/employer/password/email',['email'=>'unknown@example.com'])->assertOk()->assertJsonPath('message','If an active employer account uses this email, a password reset link has been sent.');
  $token=\Illuminate\Support\Facades\Password::broker()->createToken($user);
  $payload=['email'=>$user->email,'token'=>$token,'password'=>'NewSecurePass123','password_confirmation'=>'NewSecurePass123'];
  $this->postJson('/auth/employer/password/reset',$payload)->assertOk();
  $this->postJson('/auth/employer/password/reset',$payload)->assertUnprocessable();
  $this->postJson('/auth/logout'); $this->postJson('/auth/login',['role'=>'employer','identifier'=>$user->email,'password'=>'NewSecurePass123'])->assertOk();
 }
 public function test_profile_changes_pause_published_jobs_and_invalid_documents_are_rejected(): void {
  $this->register(); DB::table('employers')->update(['status'=>'verified']); $job=$this->job();$job['status']='active';$this->postJson('/employer-api/jobs',$job)->assertOk();
  $profile=array_intersect_key($this->payload(),array_flip(['name','legal_name','industry','website','description','gstin','state','district','address','pincode','contact_name','contact_designation','contact_phone','contact_department']));$profile['legal_name']='Updated company';
  $this->postJson('/employer-api/profile',$profile)->assertOk()->assertJsonPath('employer.status','pending');$this->assertDatabaseHas('job_posts',['title'=>'Machine Operator','status'=>'draft']);
  $profile['document']=UploadedFile::fake()->create('proof.exe',10,'application/octet-stream');$this->post('/employer-api/profile',$profile,['Accept'=>'application/json'])->assertUnprocessable()->assertJsonValidationErrors('document');
 }

 public function test_registration_can_defer_gstin_document_and_contact_and_complete_them_later(): void {
  $this->seed(PlatformRolesSeeder::class);Storage::fake('local');$data=$this->payload();
  foreach(['gstin','document','contact_name','contact_phone','contact_designation','contact_department'] as $key) unset($data[$key]);
  $this->postJson('/auth/register/employer',$data)->assertCreated();
  $employer=DB::table('employers')->first();$this->assertNull($employer->document_path);$this->assertNull($employer->gstin);$this->assertSame('pending',$employer->status);
  $this->assertSame('Asha Rao',User::first()->name);$this->get('/employer-api/document')->assertNotFound();
  $profile=array_intersect_key($this->payload(),array_flip(['name','legal_name','industry','website','description','gstin','state','district','address','pincode','contact_name','contact_designation','contact_phone','contact_department','document']));
  $this->post('/employer-api/profile',$profile,['Accept'=>'application/json'])->assertOk()->assertJsonPath('employer.gstin','27ABCDE1234F1Z5');Storage::disk('local')->assertExists(DB::table('employers')->value('document_path'));
 }

 public function test_short_signup_creates_a_workspace_before_company_setup(): void {
  $this->seed(PlatformRolesSeeder::class);
  $this->postJson('/auth/register/employer',[
   'name'=>'Acme','legal_name'=>'Acme Ltd','account_name'=>'Asha Rao','email'=>'asha@example.com','password'=>'SecurePass123','password_confirmation'=>'SecurePass123','terms_accepted'=>true,'privacy_accepted'=>true,
  ])->assertCreated();
  $this->assertSame('Asha Rao',User::first()->name);$this->assertNull(User::first()->phone);
  $this->getJson('/employer-api/workspace')->assertOk()->assertJsonPath('employer.status','pending')->assertJsonPath('summary.active_jobs',0)->assertJsonPath('employer.industry',null)->assertJsonPath('employer.contact_name','Asha Rao');
  $this->postJson('/auth/logout');$this->postJson('/auth/login',['role'=>'employer','identifier'=>'asha@example.com','password'=>'SecurePass123'])->assertOk();
 }


 private function eligibleCandidate(): \App\Models\Candidate {
  return \App\Models\Candidate::forceCreate(['candidate_code'=>'EXO-CAN-TEST','first_name'=>'Asha','last_name'=>'Rao','whatsapp'=>'9876543222','gender'=>'Female','age'=>24,'qualification'=>'ITI','state'=>'Maharashtra','district'=>'Pune','current_location'=>'Pune','permanent_location'=>'Pune','preferred_locations'=>['Pune'],'relocation_preference'=>'No','skills'=>'Machine operation','experience_type'=>'Fresher','expected_monthly_salary'=>15000,'availability'=>'Yes','preferred_industry'=>'Manufacturing','languages'=>['Hindi'],'training_status'=>'Not enrolled','whatsapp_consent'=>true,'terms_accepted_at'=>now(),'privacy_accepted_at'=>now()]);
 }
 public function test_daily_engagement_is_idempotent_and_private_to_owner(): void {
  $this->register();$owner=User::firstOrFail();DB::table('employers')->update(['status'=>'verified']);$job=$this->job();$job['status']='active';$this->postJson('/employer-api/jobs',$job)->assertOk();$id=DB::table('job_posts')->value('id');$candidate=$this->eligibleCandidate();
  $this->actingAs($candidate,'candidate')->getJson('/candidate-api/opportunities')->assertOk()->assertJsonPath('jobs.0.id',$id);
  $event=['event_id'=>(string)\Illuminate\Support\Str::uuid(),'type'=>'impression'];
  $this->postJson("/candidate-api/jobs/$id/events",$event)->assertOk();$this->postJson("/candidate-api/jobs/$id/events",$event)->assertOk();
  $this->postJson("/candidate-api/jobs/$id/events",['event_id'=>(string)\Illuminate\Support\Str::uuid(),'type'=>'click'])->assertOk();$this->assertDatabaseCount('job_engagement_events',2);
  $this->actingAs($owner,'web')->getJson("/employer-api/jobs/$id/stats?days=7")->assertOk()->assertJsonCount(7,'days')->assertJsonPath('days.6.impressions',1)->assertJsonPath('days.6.clicks',1);
  $this->getJson('/employer-api/workspace')->assertJsonPath('jobs.0.views_count',1)->assertJsonPath('jobs.0.clicks_count',1);
  $this->getJson("/employer-api/jobs/$id/stats?days=999")->assertUnprocessable();
  $other=User::factory()->create();$this->actingAs($other)->getJson("/employer-api/jobs/$id/stats")->assertForbidden();
 }
 public function test_pausing_removes_opportunity_and_skips_are_specific_to_job(): void {
  $this->register();$owner=User::firstOrFail();DB::table('employers')->update(['status'=>'verified']);$job=$this->job();$job['status']='active';$this->postJson('/employer-api/jobs',$job)->assertOk();$this->postJson('/employer-api/jobs',$job)->assertOk();$ids=DB::table('job_posts')->pluck('id');$candidate=$this->eligibleCandidate();
  $this->getJson("/employer-api/jobs/{$ids[0]}/candidates")->assertOk()->assertJsonCount(1,'candidates')->assertJsonMissingPath('candidates.0.whatsapp')->assertJsonMissingPath('candidates.0.aadhaar_number');
  $this->postJson("/employer-api/jobs/{$ids[0]}/candidates/{$candidate->id}/skip")->assertOk();$this->postJson("/employer-api/jobs/{$ids[0]}/candidates/{$candidate->id}/skip")->assertOk();$this->assertDatabaseCount('job_candidate_skips',1);
  $this->getJson("/employer-api/jobs/{$ids[0]}/candidates")->assertJsonCount(0,'candidates');$this->getJson("/employer-api/jobs/{$ids[1]}/candidates")->assertJsonCount(1,'candidates');
  $candidate->forceFill(['skills'=>'Unrelated'])->save();$this->getJson("/employer-api/jobs/{$ids[1]}/candidates")->assertJsonCount(0,'candidates');
  $job['status']='paused';$this->putJson("/employer-api/jobs/{$ids[0]}",$job)->assertOk();$this->actingAs($candidate,'candidate')->getJson('/candidate-api/opportunities')->assertJsonCount(1,'jobs');
  $this->postJson("/candidate-api/jobs/{$ids[0]}/events",['event_id'=>(string)\Illuminate\Support\Str::uuid(),'type'=>'click'])->assertNotFound();
  $this->actingAs($owner,'web');$job['status']='active';$this->putJson("/employer-api/jobs/{$ids[0]}",$job)->assertOk();
 }
}
