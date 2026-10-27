<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Hash, Http};
use App\Services\{AuthVerification, CommunicationSettings};
use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
class PhoneAuthTest extends TestCase {
 use RefreshDatabase;
 public function test_access_switches_and_template_settings_save_independently(): void {
  $this->seed(\Database\Seeders\LocalSuperAdminSeeder::class);
  $this->actingAs(User::where('email','exowork@gmail.com')->firstOrFail());
  app(CommunicationSettings::class)->save('authentication',['login_enabled'=>false,'registration_enabled'=>true,'template_name'=>'existing_otp','template_language'=>'en_US','gupshup_template_id'=>'existing-id']);
  $this->putJson('/admin-api/authentication',['template_name'=>'new_otp','template_language'=>'en_US','gupshup_template_id'=>'new-id'])->assertOk()->assertJsonPath('login_enabled',false)->assertJsonPath('registration_enabled',true);
  $this->putJson('/admin-api/authentication',['login_enabled'=>true,'registration_enabled'=>false])->assertOk()->assertJsonPath('template_name','new_otp')->assertJsonPath('gupshup_template_id','new-id');
  $this->putJson('/admin-api/authentication',['template_language'=>''])->assertUnprocessable();
 }
 public function test_defaults_and_registration_cannot_be_bypassed(): void {
  $this->getJson('/auth/settings')->assertOk()->assertExactJson(['login_enabled'=>true,'registration_enabled'=>true]);
  $this->postJson('/auth/register/employer',['phone'=>'9876543211'])->assertUnprocessable()->assertJsonValidationErrors('phone');
  $this->postJson('/auth/register/training-center',['phone'=>'9876543211'])->assertUnprocessable()->assertJsonValidationErrors('phone');
 }
 public function test_proof_matches_phone_and_role_and_expires(): void {
  Cache::put('registration-proof:'.hash('sha256','proof'),['phone'=>'9876543211','role'=>'employer'],now()->addMinute());
  $service=app(AuthVerification::class);
  $r=\Illuminate\Http\Request::create('/','POST',['verification_token'=>'proof']);
  $service->requireRegistration($r,'employer','9876543211');
  try{$service->requireRegistration($r,'center','9876543211');$this->fail('Wrong role accepted');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('phone',$e->errors());}
  $this->travel(2)->minutes();
  $this->expectException(\Illuminate\Validation\ValidationException::class);
  $service->requireRegistration($r,'employer','9876543211');
 }
 public function test_settings_are_protected_and_disabled_registration_uses_legacy_validation(): void {
  $this->putJson('/admin-api/authentication',[])->assertUnauthorized();
  app(CommunicationSettings::class)->save('authentication',['registration_enabled'=>false,'login_enabled'=>false]);
  $this->getJson('/auth/settings')->assertJsonPath('login_enabled',false);
  $this->postJson('/auth/register/training-center',['phone'=>'9876543211'])->assertJsonValidationErrors('center_name');
  $this->postJson('/auth/otp/send',['phone'=>'9876543211','role'=>'center','purpose'=>'login'])->assertForbidden();
 }
 public function test_otp_is_single_use_and_logs_in_only_with_assigned_role(): void {
  $this->seed(PlatformRolesSeeder::class);
  $user=User::factory()->create(['phone'=>'9876543211','status'=>'active']);
  \Illuminate\Support\Facades\DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>\Illuminate\Support\Facades\DB::table('roles')->where('key','training_partner')->value('id')]);
  $this->withSession(['ready'=>true]);
  $id=str_repeat('a',64);
  $request=\Illuminate\Http\Request::create('/auth/otp/verify','POST',['challenge_id'=>$id,'code'=>'123456']);
  $request->setLaravelSession(session()->driver());
  Cache::put('otp:'.$id,['phone'=>'9876543211','role'=>'center','purpose'=>'login','hash'=>Hash::make('123456'),'session'=>hash('sha256',$request->session()->getId())],300);
  $response=app(\App\Http\Controllers\PhoneAuthController::class)->verify($request);
  $this->assertSame('/center/dashboard',$response->getData(true)['destination']);
  $this->assertAuthenticatedAs($user);
  $this->assertNull(Cache::get('otp:'.$id));
  $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
  app(\App\Http\Controllers\PhoneAuthController::class)->verify($request);
 }
 public function test_delivery_verification_and_resend_cooldown(): void {
  app(CommunicationSettings::class)->save('authentication',['template_name'=>'exowork_verify','template_language'=>'en_US']);
  $code=null;
  $this->mock(\App\Services\WhatsAppCloud::class, function($mock) use (&$code) {
   $mock->shouldReceive('send')->once()->withArgs(function($phone,$template,$parameters) use (&$code){$code=$parameters[0];return $phone==='919876543211'&&$template['authentication']===true;})->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200,[],json_encode(['messages'=>[['id'=>'test']]]))));
  });
  $request=\Illuminate\Http\Request::create('/auth/otp/send','POST',['phone'=>'9876543211','role'=>'employer','purpose'=>'registration']);
  $request->setLaravelSession(session()->driver());
  $controller=app(\App\Http\Controllers\PhoneAuthController::class);
  $sent=$controller->send($request)->getData(true);
  $request->replace(['challenge_id'=>$sent['challenge_id'],'code'=>$code]);
  $proof=$controller->verify($request)->getData(true);
  $this->assertSame('9876543211',$proof['phone']);
  $request->merge(['verification_token'=>$proof['verification_token']]);
  app(AuthVerification::class)->requireRegistration($request,'employer','9876543211');
  $request->replace(['phone'=>'9876543211','role'=>'employer','purpose'=>'registration']);
  $limited=$controller->send($request);
  $this->assertSame(429, $limited->getStatusCode());
  $this->assertGreaterThan(0, $limited->getData(true)['retry_after']);
 }

}
