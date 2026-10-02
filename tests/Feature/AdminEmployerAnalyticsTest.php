<?php
namespace Tests\Feature;
use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class AdminEmployerAnalyticsTest extends TestCase {
 use RefreshDatabase;
 private function staff(): User {$this->seed(PlatformRolesSeeder::class);$user=User::factory()->create();DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>DB::table('roles')->where('key','admin')->value('id')]);return $user;}
 private function employer($code,$created='2026-09-01') {return DB::table('employers')->insertGetId(['code'=>$code,'name'=>$code,'legal_name'=>$code.' Ltd','industry'=>'Retail','state'=>'Rajasthan','status'=>'verified','created_at'=>$created,'updated_at'=>$created]);}
 private function candidate() {return DB::table('candidates')->insertGetId(['first_name'=>'Test','last_name'=>'Learner','whatsapp'=>'9876543201','password'=>'unused','gender'=>'Female','age'=>22,'qualification'=>'12th','state'=>'Rajasthan','district'=>'Jaipur','current_location'=>'Jaipur','permanent_location'=>'Jaipur','preferred_locations'=>'[]','relocation_preference'=>'Yes','skills'=>'Training','experience_type'=>'Fresher','expected_monthly_salary'=>10000,'availability'=>'Yes','preferred_industry'=>'Retail','languages'=>'[]','training_status'=>'Completed']);}
 public function test_month_range_filters_dates_and_distinct_hires_and_excludes_deleted_records(): void {
  $user=$this->staff();$employer=$this->employer('ONE');$this->employer('NEW','2026-10-31 23:59:59');$this->employer('FUTURE','2026-11-01');
  $candidate=$this->candidate();
  foreach (['2026-10-01 00:00:00','2026-10-31 23:59:59','2026-09-01 00:00:00',null] as $i=>$published) {
   $job=DB::table('job_posts')->insertGetId(['employer_id'=>$employer,'title'=>'Job '.$i,'published_at'=>$published,'openings'=>3]);
   if($i<2){$application=DB::table('applications')->insertGetId(['candidate_id'=>$candidate,'job_post_id'=>$job]);DB::table('placement_outcomes')->insert(['application_id'=>$application,'status'=>'joined','joined_on'=>$i===0?'2026-10-01':'2026-10-31']);}
  }
  DB::table('job_posts')->insert(['employer_id'=>$employer,'title'=>'Deleted','published_at'=>'2026-10-02','deleted_at'=>now()]);
  $this->actingAs($user)->getJson('/admin-api/employers/stats?from=2026-10&to=2026-10')->assertOk()->assertJsonPath('summary.employers',2)->assertJsonPath('summary.new_employers',1)->assertJsonPath('summary.jobs',2)->assertJsonPath('summary.hires',1)->assertJsonPath('months.0.jobs',2)->assertJsonPath('months.0.hires',1)->assertJsonCount(1,'months');
  $this->getJson('/admin-api/employers/stats?from=2026-09&to=2026-10')->assertOk()->assertJsonPath('summary.jobs',3)->assertJsonCount(2,'months');
  $this->getJson('/admin-api/employers/stats?from=2026-10&to=2026-10&industry=Manufacturing')->assertOk()->assertJsonPath('summary.employers',0)->assertJsonCount(0,'performance');
  $this->getJson('/admin-api/employers/stats?from=2026-11&to=2026-10')->assertUnprocessable();
  $this->getJson('/admin-api/employers/stats?from=2020-01&to=2026-10')->assertUnprocessable();
  $this->getJson('/admin-api/employers')->assertOk()->assertJsonPath('employers.2.jobs_count',3)->assertJsonPath('employers.2.hires_count',1);
 }
 public function test_edit_permissions_validation_audit_and_reverification(): void {
  $user=$this->staff();$id=$this->employer('EDIT');
  DB::table('job_posts')->insert(['employer_id'=>$id,'title'=>'Published','status'=>'active','published_at'=>now()]);
  $outsider=User::factory()->create();
  $this->actingAs($outsider)->getJson('/admin-api/employers/stats')->assertForbidden();
  $this->patchJson('/admin-api/employers/'.$id,['name'=>'Changed','legal_name'=>'Changed Ltd'])->assertForbidden();
  $view=DB::table('permissions')->where('key','employers.view')->value('id');DB::table('user_permissions')->insert(['user_id'=>$outsider->id,'permission_id'=>$view,'allowed'=>true]);
  $this->getJson('/admin-api/employers/stats?from=2026-09&to=2026-10')->assertOk();
  $this->patchJson('/admin-api/employers/'.$id,['name'=>'Changed','legal_name'=>'Changed Ltd'])->assertForbidden();
  $this->actingAs($user)->patchJson('/admin-api/employers/'.$id,['name'=>'Changed','legal_name'=>'Changed Ltd','gstin'=>'invalid'])->assertUnprocessable();
  $this->patchJson('/admin-api/employers/'.$id,['name'=>'EDIT','legal_name'=>'EDIT Ltd'])->assertOk();$this->assertDatabaseHas('employers',['id'=>$id,'status'=>'verified']);
  $this->patchJson('/admin-api/employers/'.$id,['name'=>'Changed','legal_name'=>'Changed Ltd'])->assertOk();
  $this->assertDatabaseHas('employers',['id'=>$id,'name'=>'Changed','status'=>'pending']);$this->assertDatabaseHas('job_posts',['employer_id'=>$id,'status'=>'draft']);$this->assertDatabaseHas('audit_logs',['action'=>'admin.employer_updated','subject_id'=>$id]);
  DB::table('employers')->where('id',$id)->update(['status'=>'inactive']);
  $this->patchJson('/admin-api/employers/'.$id,['name'=>'Changed again','legal_name'=>'Changed Ltd'])->assertUnprocessable();
 }

 public function test_bulk_actions_are_atomic_permission_checked_and_reactivation_requires_review(): void {
  $admin=$this->staff();$one=$this->employer('BULK1');$two=$this->employer('BULK2');
  foreach([$one,$two] as $id) DB::table('job_posts')->insert(['employer_id'=>$id,'title'=>'Job','status'=>'active','published_at'=>now()]);
  $outsider=User::factory()->create();
  $this->actingAs($outsider)->postJson('/admin-api/employers/actions',['ids'=>[$one],'action'=>'disable'])->assertForbidden();
  $this->actingAs($admin)->postJson('/admin-api/employers/actions',['ids'=>[$one,$two],'action'=>'approve'])->assertOk();
  $this->assertDatabaseHas('employers',['id'=>$one,'status'=>'verified']);
  $this->postJson('/admin-api/employers/actions',['ids'=>[$one,$two],'action'=>'disable'])->assertOk();
  foreach([$one,$two] as $id){$this->assertDatabaseHas('employers',['id'=>$id,'status'=>'inactive']);$this->assertDatabaseHas('job_posts',['employer_id'=>$id,'status'=>'draft']);}
  $this->postJson('/admin-api/employers/actions',['ids'=>[$one,$two],'action'=>'activate'])->assertOk();
  $this->assertDatabaseHas('employers',['id'=>$one,'status'=>'pending']);
  $this->postJson('/admin-api/employers/actions',['ids'=>[$one,$two],'action'=>'reject'])->assertUnprocessable();
  $this->postJson('/admin-api/employers/actions',['ids'=>[$one,$two],'action'=>'reject','remarks'=>'Upload valid proof'])->assertOk();
  foreach([$one,$two] as $id) DB::table('employers')->where('id',$id)->update(['gstin'=>$id===$one?'27ABCDE1234F1Z5':'27ABCDE1235F1Z5','address'=>'Road','pincode'=>'411001','document_path'=>'proof.pdf']);
  $this->postJson('/admin-api/employers/actions',['ids'=>[$one,$two],'action'=>'approve'])->assertOk();
  $this->assertDatabaseHas('employers',['id'=>$one,'status'=>'verified']);
  $this->postJson('/admin-api/employers/actions',['ids'=>[$one,$two],'action'=>'activate'])->assertUnprocessable();
  $this->postJson('/admin-api/employers/actions',['ids'=>[$one,99999],'action'=>'disable'])->assertUnprocessable();
  $this->assertDatabaseHas('employers',['id'=>$one,'status'=>'verified']);
  $this->assertDatabaseHas('audit_logs',['action'=>'admin.employer_disable','subject_id'=>$one]);
 }

 public function test_list_paginates_and_filters_over_a_thousand_employers_and_exports_all_matches(): void {
  $admin=$this->staff();$records=[];
  for($i=0;$i<1005;$i++) $records[]=['name'=>'Company'.str_pad($i,4,'0',STR_PAD_LEFT),'legal_name'=>'Company'.str_pad($i,4,'0',STR_PAD_LEFT),'code'=>'SCALE'.$i,'industry'=>$i%2?'Retail':'Manufacturing','state'=>$i===1004?'Kerala':'Rajasthan','status'=>$i===1004?'inactive':($i%2?'pending':'verified'),'created_at'=>$i<500?'2026-10-02 23:59:59':'2026-09-30 12:00:00'];
  foreach(array_chunk($records,100) as $chunk) DB::table('employers')->insert($chunk);
  $this->actingAs($admin)->getJson('/admin-api/employers?sort=legal_name&direction=asc')->assertOk()->assertJsonCount(25,'employers')->assertJsonPath('pagination.total',1005)->assertJsonPath('pagination.last_page',41)->assertJsonPath('employers.0.legal_name','Company0000')->assertJsonPath('counts.all',1005)->assertJsonFragment(['Kerala']);
  $page=$this->getJson('/admin-api/employers?sort=legal_name&direction=asc&page=2')->assertOk()->assertJsonCount(25,'employers')->assertJsonPath('employers.0.legal_name','Company0025')->assertJsonPath('pagination.from',26)->assertJsonPath('pagination.to',50);
  $this->getJson('/admin-api/employers?from=2026-10-02&to=2026-10-02&per_page=100')->assertOk()->assertJsonCount(100,'employers')->assertJsonPath('pagination.total',500);
  $this->getJson('/admin-api/employers?industries[]=Retail&industries[]=Manufacturing&access[]=active')->assertOk()->assertJsonPath('pagination.total',1004);
  $this->getJson('/admin-api/employers?statuses[]=pending&statuses[]=verified')->assertOk()->assertJsonPath('pagination.total',1004);
  $this->getJson('/admin-api/employers?states[]=Kerala&access[]=inactive')->assertOk()->assertJsonCount(1,'employers')->assertJsonPath('pagination.total',1);
  $id=DB::table('employers')->where('code','SCALE1000')->value('id');
  $this->getJson('/admin-api/employers/'.$id)->assertOk()->assertJsonPath('employer.code','SCALE1000')->assertJsonMissingPath('employer.document_path');
  $this->getJson('/admin-api/employers?per_page=10000')->assertUnprocessable();
  $this->getJson('/admin-api/employers?sort=unsupported')->assertUnprocessable();
  $this->getJson('/admin-api/employers?from=2026-10-03&to=2026-10-02')->assertUnprocessable();
  $response=$this->get('/admin-api/employers/export?from=2026-10-02&to=2026-10-02')->assertOk()->assertDownload('exowork-employers.csv');
  $this->assertCount(501,explode("\n",trim($response->streamedContent())));
  $this->assertStringNotContainsString('Company0500',$response->streamedContent());
  DB::table('employers')->where('code','SCALE0')->update(['name'=>'100% Company','legal_name'=>'100% Company']);
  $this->getJson('/admin-api/employers?search=%25')->assertOk()->assertJsonPath('pagination.total',1);
  $outsider=User::factory()->create();$this->actingAs($outsider)->get('/admin-api/employers/export')->assertForbidden();
 }

 public function test_document_preview_is_authenticated_inline_for_images_and_pdf_and_downloads_other_types(): void {
  $admin=$this->staff();$id=$this->employer('DOCUMENT');
  \Illuminate\Support\Facades\Storage::fake('local');
  $disk=\Illuminate\Support\Facades\Storage::disk('local');
  $disk->put('proof.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5l8AAAAASUVORK5CYII='));
  DB::table('employers')->where('id',$id)->update(['document_path'=>'proof.png','document_name'=>'Company proof.png']);
  $outsider=User::factory()->create();
  $this->actingAs($outsider)->get('/admin-api/employers/'.$id.'/document?preview=1')->assertForbidden();
  $response=$this->actingAs($admin)->get('/admin-api/employers/'.$id.'/document?preview=1')->assertOk()->assertHeader('Content-Type','image/png')->assertHeader('X-Content-Type-Options','nosniff');
  $this->assertStringStartsWith('inline;',$response->headers->get('Content-Disposition'));
  $this->get('/admin-api/employers/'.$id.'/document')->assertOk()->assertDownload('Company proof.png');
  $this->getJson('/admin-api/employers/'.$id)->assertOk()->assertJsonPath('employer.document_type','png')->assertJsonMissingPath('employer.document_path');
  $disk->put('proof.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
  DB::table('employers')->where('id',$id)->update(['document_path'=>'proof.pdf','document_name'=>'proof.pdf']);
  $response=$this->get('/admin-api/employers/'.$id.'/document?preview=1')->assertOk()->assertHeader('Content-Type','application/pdf');
  $this->assertStringStartsWith('inline;',$response->headers->get('Content-Disposition'));
  $disk->put('legacy.html','<html>Untrusted legacy document</html>');
  DB::table('employers')->where('id',$id)->update(['document_path'=>'legacy.html','document_name'=>'legacy.html']);
  $response=$this->get('/admin-api/employers/'.$id.'/document?preview=1')->assertOk()->assertDownload('legacy.html');
  $this->assertStringStartsWith('attachment;',$response->headers->get('Content-Disposition'));
  $disk->delete('legacy.html');$this->get('/admin-api/employers/'.$id.'/document?preview=1')->assertNotFound();
 }

 public function test_admin_can_approve_incomplete_profiles_through_both_review_endpoints(): void {
  $admin=$this->staff();$one=$this->employer('MANUAL1');$two=$this->employer('MANUAL2');
  DB::table('employers')->whereIn('id',[$one,$two])->update(['status'=>'pending','legal_name'=>null,'gstin'=>null,'address'=>null,'pincode'=>null,'document_path'=>null]);
  $outsider=User::factory()->create();
  $this->actingAs($outsider)->patchJson('/admin-api/employers/'.$one.'/review',['status'=>'verified'])->assertForbidden();
  $this->actingAs($admin)->patchJson('/admin-api/employers/'.$one.'/review',['status'=>'verified','review_remarks'=>'Approved by administrator'])->assertOk();
  $this->assertDatabaseHas('employers',['id'=>$one,'status'=>'verified','verified_by_user_id'=>$admin->id,'review_remarks'=>'Approved by administrator']);
  $this->assertDatabaseHas('employers',['id'=>$one,'gstin'=>null,'document_path'=>null]);
  $this->postJson('/admin-api/employers/actions',['ids'=>[$two],'action'=>'approve'])->assertOk();
  $this->assertDatabaseHas('employers',['id'=>$two,'status'=>'verified','verified_by_user_id'=>$admin->id]);
  foreach([$one,$two] as $id){
   $changes=json_decode(DB::table('audit_logs')->where('subject_id',$id)->where('action','admin.employer_approve')->value('changes'),true);
   $this->assertSame('admin_decision',$changes['approval_basis']);$this->assertContains('document_path',$changes['missing_profile_fields']);
   $this->assertDatabaseHas('audit_logs',['action'=>'employer.reviewed','subject_id'=>$id]);
  }
  $this->patchJson('/admin-api/employers/'.$one.'/review',['status'=>'rejected'])->assertUnprocessable();
  $this->postJson('/admin-api/employers/actions',['ids'=>[$two],'action'=>'reject'])->assertUnprocessable();
  $this->patchJson('/admin-api/employers/'.$one.'/review',['status'=>'rejected','review_remarks'=>'Please update the contact'])->assertOk();
  $this->postJson('/admin-api/employers/actions',['ids'=>[$two],'action'=>'reject','remarks'=>'Please update the contact'])->assertOk();
  foreach([$one,$two] as $id)$this->assertDatabaseHas('employers',['id'=>$id,'status'=>'rejected','review_remarks'=>'Please update the contact']);
 }
}

