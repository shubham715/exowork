<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppOtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, RateLimiter};
use Tests\TestCase;

class PhoneLoginAccountTest extends TestCase
{
 use RefreshDatabase;
 public function test_unknown_number_gets_role_specific_registration_link_without_sending_otp(): void {
  Http::preventStrayRequests();
  $this->mock(WhatsAppOtpSender::class)->shouldNotReceive('send');
  foreach(['candidate'=>'candidate','center'=>'training-center','employer'=>'employer'] as $role=>$path) {
   RateLimiter::clear('phone-otp:'.hash('sha256','9876543211'));
   $this->postJson('/auth/otp/send',['phone'=>'9876543211','role'=>$role,'purpose'=>'login'])
    ->assertUnprocessable()->assertJsonPath('code','ACCOUNT_NOT_FOUND')->assertJsonPath('registration_url','/register/'.$path)->assertJsonMissingPath('challenge_id');
  }
  $this->assertDatabaseCount('whatsapp_messages',0);
 }
 public function test_wrong_role_names_existing_account_types_without_sending_otp(): void {
  $this->seed(\Database\Seeders\PlatformRolesSeeder::class);
  $user=User::factory()->create(['phone'=>'9876543211','status'=>'active']);
  foreach(['training_partner','employer'] as $key) DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>DB::table('roles')->where('key',$key)->value('id')]);
  $this->mock(WhatsAppOtpSender::class)->shouldNotReceive('send');
  $this->postJson('/auth/otp/send',['phone'=>'9876543211','role'=>'candidate','purpose'=>'login'])
   ->assertUnprocessable()->assertJsonPath('code','ACCOUNT_ROLE_MISMATCH')->assertSee('Employer and Training Center')->assertJsonMissingPath('registration_url');
 }
 public function test_matching_role_can_request_otp_and_registration_does_not_require_existing_account(): void {
  $this->seed(\Database\Seeders\PlatformRolesSeeder::class);
  $user=User::factory()->create(['phone'=>'9876543211','status'=>'active']);
  DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>DB::table('roles')->where('key','training_partner')->value('id')]);
  $this->mock(WhatsAppOtpSender::class)->shouldReceive('send')->twice()->andReturn(1);
  $this->postJson('/auth/otp/send',['phone'=>'9876543211','role'=>'center','purpose'=>'login'])->assertOk()->assertJsonStructure(['challenge_id']);
  $this->postJson('/auth/otp/send',['phone'=>'9876543212','role'=>'employer','purpose'=>'registration'])->assertOk()->assertJsonStructure(['challenge_id']);
 }
}
