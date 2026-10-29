<?php
namespace Tests\Feature;

use App\Services\CommunicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Hash, Http};
use Tests\TestCase;

class OtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.provider'=>'gupshup', 'whatsapp.enabled'=>true,
            'whatsapp.gupshup_app_id'=>'bcd032f4-5e7b-4929-9945-9f731b7a3af0',
            'whatsapp.gupshup_app_name'=>'TestApp', 'whatsapp.gupshup_source'=>'919876543210',
            'whatsapp.gupshup_auth_mode'=>'api_key', 'whatsapp.gupshup_api_key'=>'test-key',
            'whatsapp.gupshup_webhook_secret'=>str_repeat('s',40),
        ]);
        app(CommunicationSettings::class)->save('authentication', [
            'template_name'=>'exowork_auth_otp_v1', 'template_language'=>'en_US', 'gupshup_template_id'=>'test-template',
        ]);
        $spec = \App\Services\WhatsAppTemplateCatalog::get('auth_otp');
        DB::table('whatsapp_templates')->where('system_key','auth_otp')->update(['review_status'=>'approved','meta_status'=>'APPROVED','account_id'=>config('whatsapp.gupshup_app_id')]);
        Http::preventStrayRequests();
    }

    private function fakeSend($response): void
    {
        $spec = \App\Services\WhatsAppTemplateCatalog::get('auth_otp');
        Http::fake([
            'api.gupshup.io/wa/app/*/template*'=>Http::response(['templates'=>[[
                'id'=>'test-template', 'elementName'=>$spec['name'], 'languageCode'=>$spec['language'],
                'category'=>'AUTHENTICATION', 'status'=>'APPROVED', 'templateType'=>'TEXT',
                'containerMeta'=>json_encode(['data'=>$spec['body'],'buttons'=>[['type'=>'OTP','otp_type'=>'COPY_CODE','text'=>'Copy code']]]),
            ]]]),
            'api.gupshup.io/wa/api/v1/template/msg'=>$response,
        ]);
    }

    private function send()
    {
        $response = $this->withSession(['ready'=>true])->postJson('/auth/otp/send',[
            'phone'=>'9876543211', 'role'=>'candidate', 'purpose'=>'login',
        ]);
        $this->withCookie(session()->getName(),session()->getId())->withCredentials();
        return $response;
    }

    public function test_body_and_copy_button_receive_the_same_code_and_async_failure_reaches_the_browser(): void
    {
        $code = null;
        $this->fakeSend(function ($request) use (&$code) {
            $template = json_decode($request['template'],true);
            $this->assertCount(2,$template['params']);
            $this->assertSame($template['params'][0],$template['params'][1]);
            $code = $template['params'][0];
            return Http::response(['status'=>'submitted','messageId'=>'otp-gs-id'],202);
        });
        $sent = $this->send()->assertOk()->assertJsonPath('delivery_status','accepted');
        $this->assertStringNotContainsString('Code sent',$sent->json('message'));
        $challenge = $sent->json('challenge_id');
        $this->assertTrue(Hash::check($code,Cache::get('otp:'.$challenge)['hash']));
        $message = DB::table('whatsapp_messages')->first();
        $this->assertNull($message->payload);
        $this->assertSame('otp_login',$message->source);
        $this->assertStringNotContainsString($code,json_encode($message));
        $this->postJson('/auth/otp/status',['challenge_id'=>$challenge])->assertOk()->assertJsonPath('delivery_status','accepted');
        $this->travel(31)->seconds();
        $this->postJson('/auth/otp/status',['challenge_id'=>$challenge])->assertOk()->assertSee('Delivery has not been confirmed');
        $this->postJson('/api/webhooks/gupshup',[
            'app'=>'TestApp', 'version'=>2, 'timestamp'=>now()->getTimestampMs(), 'type'=>'message-event',
            'payload'=>['id'=>'otp-gs-id','type'=>'failed','payload'=>['code'=>132000,'reason'=>'Parameter mismatch']],
        ],['X-Exowork-Webhook-Token'=>str_repeat('s',40)])->assertOk();
        $this->postJson('/auth/otp/status',['challenge_id'=>$challenge])->assertOk()->assertJsonPath('delivery_status','failed')->assertSee('could not deliver');
        $this->assertDatabaseHas('whatsapp_messages',['id'=>$message->id,'status'=>'failed','error_code'=>'132000']);
    }

    public function test_success_without_message_id_does_not_create_a_challenge(): void
    {
        $this->fakeSend(Http::response(['status'=>'success'],202));
        $this->send()->assertStatus(502)->assertJsonMissingPath('challenge_id');
        $this->assertDatabaseHas('whatsapp_messages',['status'=>'unknown','error_code'=>'OTP_PROVIDER_REJECTED']);
    }

    public function test_explicit_provider_error_is_rejected_even_with_http_success_and_a_message_id(): void
    {
        $this->fakeSend(Http::response(['status'=>'error','messageId'=>'not-accepted'],200));
        $this->send()->assertStatus(502)->assertJsonMissingPath('challenge_id');
    }

    public function test_receipt_arriving_before_http_response_is_reconciled_and_status_is_session_bound(): void
    {
        $this->fakeSend(function () {
            DB::table('whatsapp_receipts')->insert(['provider_message_id'=>'early-id','status'=>'delivered','status_timestamp'=>now()->timestamp,'created_at'=>now(),'updated_at'=>now()]);
            return Http::response(['status'=>'success','messageId'=>'early-id'],202);
        });
        $challenge = $this->send()->assertOk()->json('challenge_id');
        $this->postJson('/auth/otp/status',['challenge_id'=>$challenge])->assertOk()->assertJsonPath('delivery_status','delivered')->assertSee('Code delivered');
        $this->withCookie(session()->getName(),str_repeat('z',40));
        $this->postJson('/auth/otp/status',['challenge_id'=>$challenge])->assertUnprocessable();
    }

    public function test_timed_out_submission_is_not_reported_as_sent_and_is_never_automatically_retried(): void
    {
        $this->fakeSend(fn()=>throw new \Illuminate\Http\Client\ConnectionException('Test timeout'));
        $this->send()->assertStatus(502)->assertJsonMissingPath('challenge_id');
        $this->assertDatabaseHas('whatsapp_messages',['status'=>'unknown','error_code'=>'OTP_SUBMISSION_UNKNOWN']);
        $this->assertDatabaseCount('whatsapp_messages',1);
    }

    public function test_expired_challenges_stop_delivery_polling(): void
    {
        $this->fakeSend(Http::response(['status'=>'submitted','messageId'=>'expiring-id'],202));
        $challenge = $this->send()->assertOk()->json('challenge_id');
        $this->travel(6)->minutes();
        $this->postJson('/auth/otp/status',['challenge_id'=>$challenge])->assertUnprocessable()->assertSee('expired');
    }
}
