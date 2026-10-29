<?php
namespace App\Services;

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WhatsAppOtpSender
{
    public function send(string $phone, string $purpose, string $code): int
    {
        $cloud = app(WhatsAppCloud::class);
        $spec = WhatsAppTemplateCatalog::get('auth_otp');
        // Keep OTPs only in the short-lived hashed challenge. Never store them in logs or retry payloads.
        $id = DB::table('whatsapp_messages')->insertGetId([
            'request_id'=>(string)Str::uuid(), 'recipient'=>'91'.$phone,
            'template_name'=>$spec['name'], 'language'=>$spec['language'], 'category'=>'AUTHENTICATION',
            'provider'=>config('whatsapp.provider','meta'), 'provider_account_id'=>$cloud->accountId(),
            'source'=>'otp_'.$purpose, 'status'=>'submitting', 'attempts'=>1, 'payload'=>null,
            'available_at'=>now(), 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $posted = false;
        try {
            $local = DB::table('whatsapp_templates')->where('system_key','auth_otp')->first();
            abort_unless($local && $local->review_status === 'approved' && $local->meta_status === 'APPROVED' && $local->account_id === $cloud->accountId() && $local->name === $spec['name'] && $local->language === $spec['language'], 503, 'WhatsApp verification is unavailable. Ask an administrator to sync and approve the OTP template in Settings > WhatsApp templates.');
            $remote = collect($cloud->manageTemplates())->first(fn ($t) => $t['name'] === $spec['name'] && $t['language'] === $spec['language']);
            $body = $remote ? (collect($remote['components'] ?? [])->firstWhere('type','BODY')['text'] ?? '') : '';
            $valid = $remote && $remote['status'] === 'APPROVED' && $body === $spec['body'] && WhatsAppAuthenticationTemplate::supported($remote);
            if (!$valid) {
                $status = !$remote ? 'NOT_SUBMITTED' : ($remote['status'] !== 'APPROVED' ? $remote['status'] : 'CONTENT_MISMATCH');
                DB::table('whatsapp_templates')->where('id',$local->id)->update(['meta_status'=>$status,'meta_error'=>$remote['reason'] ?? null,'review_status'=>'pending','synced_at'=>now(),'updated_at'=>now()]);
                abort(503, 'WhatsApp verification is unavailable because the OTP template is not approved by the provider. Contact EXOWORK support.');
            }
            $posted = true;
            $response = $cloud->send('91'.$phone, [...$spec, 'id'=>$remote['id'], 'authentication'=>true], [$code], $id);
            $providerId = $response->json(config('whatsapp.provider') === 'gupshup' ? 'messageId' : 'messages.0.id');
            $accepted = $response->successful() && is_string($providerId) && $providerId !== '' && !in_array(strtolower((string)$response->json('status')), ['error','failed','failure']);
            if (!$accepted) {
                $status = $response->serverError() || $response->successful() ? 'unknown' : 'failed';
                DB::table('whatsapp_messages')->where('id',$id)->update(['status'=>$status,'error_code'=>'OTP_PROVIDER_REJECTED','error_message'=>'Provider did not confirm OTP acceptance. Check template approval, account permissions and provider delivery logs.','updated_at'=>now()]);
                abort(502, 'We could not send the code. Please try again later.');
            }
            DB::table('whatsapp_messages')->where('id',$id)->update(['status'=>'accepted','provider_message_id'=>$providerId,'updated_at'=>now()]);
            app(WhatsAppWebhookController::class)->reconcile($providerId);
            return $id;
        } catch (\Throwable $e) {
            DB::table('whatsapp_messages')->where('id',$id)->where('status','submitting')->update([
                'status'=>$posted?'unknown':'failed', 'error_code'=>$posted?'OTP_SUBMISSION_UNKNOWN':'OTP_UNAVAILABLE',
                'error_message'=>$e instanceof HttpException?$e->getMessage():($posted?'No OTP acceptance confirmation. Check provider logs before requesting another code.':'Unable to verify the OTP template. Check provider configuration and approval.'), 'updated_at'=>now(),
            ]);
            if ($e instanceof HttpException) throw $e;
            abort(502, 'WhatsApp verification is temporarily unavailable. Please try again later.');
        }
    }
}
