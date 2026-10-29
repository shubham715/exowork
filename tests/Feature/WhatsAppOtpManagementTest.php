<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\{WhatsAppCloud,WhatsAppTemplateCatalog,WhatsAppOtpSender};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Queue};
use Tests\TestCase;

class WhatsAppOtpManagementTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp(): void {
  parent::setUp();
  $this->seed(\Database\Seeders\LocalSuperAdminSeeder::class);
  $this->actingAs(User::where('email','exowork@gmail.com')->firstOrFail());
  config(['whatsapp.enabled'=>true,'whatsapp.provider'=>'gupshup','whatsapp.gupshup_app_id'=>'bcd032f4-5e7b-4929-9945-9f731b7a3af0','whatsapp.gupshup_app_name'=>'ExoworkNotifications','whatsapp.gupshup_source'=>'919785262702','whatsapp.gupshup_api_key'=>'test-key','whatsapp.gupshup_auth_mode'=>'api_key','whatsapp.gupshup_webhook_secret'=>str_repeat('s',40)]);
  Queue::fake(); Http::preventStrayRequests();
 }
 private function remote(string $status='APPROVED',array $meta=[]): void {
  Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
  $t=WhatsAppTemplateCatalog::get('auth_otp');
  Http::fake(['api.gupshup.io/wa/app/*/template*'=>Http::response(['templates'=>[['id'=>'otp-provider-id','elementName'=>$t['name'],'languageCode'=>$t['language'],'category'=>'AUTHENTICATION','status'=>$status,'reason'=>$status==='FAILED'?'This WhatsApp Business account does not have permission to create message template':'NONE','templateType'=>'TEXT','containerMeta'=>json_encode(array_replace(['data'=>$t['body'],'buttons'=>[['type'=>'OTP','otp_type'=>'COPY_CODE']]],$meta))]]]),'api.gupshup.io/wa/api/v1/template/msg'=>Http::response(['status'=>'submitted','messageId'=>'otp-message-id'],202)]);
 }
 private function approve(): int {
  $this->remote();
  $id=DB::table('whatsapp_templates')->where('system_key','auth_otp')->value('id');
  $this->patchJson('/admin-api/whatsapp/templates/'.$id.'/review',['status'=>'approved'])->assertOk();
  return $id;
 }
 public function test_otp_has_same_library_status_and_approval_controls(): void {
  $this->getJson('/admin-api/whatsapp/templates')->assertOk()->assertJsonCount(14,'templates');
  $this->remote('FAILED');
  $this->postJson('/admin-api/whatsapp/sync')->assertOk();
  $id=DB::table('whatsapp_templates')->where('system_key','auth_otp')->value('id');
  $this->assertDatabaseHas('whatsapp_templates',['id'=>$id,'meta_status'=>'FAILED','review_status'=>'pending','meta_error'=>'This WhatsApp Business account does not have permission to create message template']);
  $this->patchJson('/admin-api/whatsapp/templates/'.$id.'/review',['status'=>'approved'])->assertUnprocessable();
 }
 public function test_real_gupshup_payload_contains_code_for_body_and_button_and_no_logged_secret(): void {
  $this->approve();
  $messageId=app(WhatsAppOtpSender::class)->send('9876543211','registration','654321');
  Http::assertSent(function($r){if(!str_ends_with($r->url(),'/template/msg'))return false;$template=json_decode($r['template'],true);return $template['id']==='otp-provider-id'&&$template['params']===['654321','654321'];});
  $record=DB::table('whatsapp_messages')->find($messageId);
  $this->assertSame('accepted',$record->status);
  $this->assertSame('otp_registration',$record->source);
  $this->assertNull($record->payload);
  $this->assertStringNotContainsString('654321',json_encode(\Illuminate\Support\Arr::except((array)$record,['recipient'])));
  $this->getJson('/admin-api/whatsapp/logs')->assertOk()->assertJsonPath('logs.data.0.can_resend',false);
  $this->postJson('/admin-api/whatsapp/resend',['ids'=>[$messageId]])->assertAccepted()->assertJsonPath('results.0.queued',false);
  $this->assertDatabaseCount('whatsapp_messages',1);
 }
 public function test_revoked_provider_approval_blocks_sending_and_logs_failure(): void {
  $this->approve(); $this->remote('FAILED');
  $this->postJson('/auth/otp/send',['phone'=>'9876543211','role'=>'candidate','purpose'=>'login'])->assertStatus(503);
  $this->assertDatabaseHas('whatsapp_messages',['source'=>'otp_login','status'=>'failed','payload'=>null]);
  $this->assertDatabaseHas('whatsapp_templates',['system_key'=>'auth_otp','meta_status'=>'FAILED','review_status'=>'pending']);
  Http::assertNotSent(fn($r)=>str_ends_with($r->url(),'/template/msg'));
 }
 public function test_unsupported_authentication_button_cannot_be_approved(): void {
  $this->remote('APPROVED',['buttons'=>[['type'=>'OTP','otp_type'=>'ONE_TAP']]]);
  $id=DB::table('whatsapp_templates')->where('system_key','auth_otp')->value('id');
  $this->patchJson('/admin-api/whatsapp/templates/'.$id.'/review',['status'=>'approved'])->assertUnprocessable();
  $this->assertDatabaseHas('whatsapp_templates',['id'=>$id,'meta_status'=>'CONTENT_MISMATCH']);
 }
 public function test_common_template_test_uses_authentication_payload_and_worker(): void {
  $id=$this->approve();
  $this->postJson('/admin-api/whatsapp/templates/'.$id.'/test',['request_id'=>(string)\Illuminate\Support\Str::uuid(),'number'=>'9876543211','consent'=>true,'parameters'=>['123456']])->assertAccepted();
  $record=DB::table('whatsapp_messages')->first();
  (new \App\Jobs\SendWhatsAppMessage($record->id))->handle(app(WhatsAppCloud::class));
  $this->assertDatabaseHas('whatsapp_messages',['id'=>$record->id,'status'=>'accepted']);
  Http::assertSent(fn($r)=>str_ends_with($r->url(),'/template/msg')&&json_decode($r['template'],true)['params']===['123456','123456']);
 }
}
