<?php
namespace App\Http\Controllers;

use App\Services\{AuthVerification, CommunicationSettings, WhatsAppCloud};
use App\Models\{Candidate, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Hash, RateLimiter};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

class PhoneAuthController extends Controller
{
    public function settings() { return response()->json(array_intersect_key(app(AuthVerification::class)->settings(), array_flip(['login_enabled', 'registration_enabled'])))->header('Cache-Control', 'no-store'); }
    public function admin(Request $request) {
        abort_unless($request->user()?->hasPermission('masters.manage'), 403);
        if ($request->isMethod('put')) {
            $data = $request->validate(['login_enabled'=>'sometimes|boolean','registration_enabled'=>'sometimes|boolean','template_name'=>'sometimes|nullable|regex:/^[a-z0-9_]+$/|max:100','template_language'=>'sometimes|required|string|max:20','gupshup_template_id'=>'sometimes|nullable|string|max:100']);
            app(CommunicationSettings::class)->save('authentication', array_replace(app(AuthVerification::class)->settings(), $data));
        }
        return response()->json(app(AuthVerification::class)->settings())->header('Cache-Control', 'no-store');
    }
    public function send(Request $request) {
        $data = $request->validate(['phone'=>['required','regex:/^[6-9][0-9]{9}$/'],'role'=>'required|in:candidate,center,employer','purpose'=>'required|in:login,registration']);
        $settings = app(AuthVerification::class)->settings();
        abort_unless($settings[$data['purpose']==='login'?'login_enabled':'registration_enabled'], 403, 'WhatsApp verification is disabled.');
        $key = 'phone-otp:'.hash('sha256', $data['phone']);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $retry = RateLimiter::availableIn($key);
            return response()->json(['message'=>'Please wait before requesting another code.', 'retry_after'=>$retry], 429)->header('Retry-After', $retry);
        }
        RateLimiter::hit($key, 60);
        if ($data['purpose'] === 'login' && ($error = app(\App\Services\PhoneLoginAccount::class)->error($data['phone'], $data['role']))) {
            return response()->json($error, 422);
        }
        $code = (string)random_int(100000, 999999);
        app(CommunicationSettings::class)->apply();
        $messageId = app(\App\Services\WhatsAppOtpSender::class)->send($data['phone'], $data['purpose'], $code);
        $id = Str::random(64);
        Cache::put('otp:'.$id, [...$data, 'hash'=>Hash::make($code), 'session'=>hash('sha256',$request->session()->getId()), 'message_id'=>$messageId, 'issued_at'=>now()->timestamp], now()->addMinutes(5));
        return response()->json(['message'=>'Verification request accepted. Waiting for WhatsApp delivery.','delivery_status'=>'accepted','challenge_id'=>$id,'expires_in'=>300,'retry_after'=>60]);
    }
    public function status(Request $request) {
        $data = $request->validate(['challenge_id'=>'required|string|size:64']);
        $challenge = Cache::get('otp:'.$data['challenge_id']);
        abort_unless($challenge && hash_equals($challenge['session'], hash('sha256',$request->session()->getId())),422,'This code has expired. Request a new code.');
        $message = isset($challenge['message_id']) ? DB::table('whatsapp_messages')->find($challenge['message_id']) : null;
        $status = $message?->status ?? 'unknown';
        $text = match ($status) {
            'delivered','read' => 'Code delivered to WhatsApp. Enter it below to continue.',
            'failed' => 'WhatsApp could not deliver your code. Check your number and request a new code. If this continues, contact EXOWORK support.',
            default => now()->timestamp - ($challenge['issued_at'] ?? 0) >= 30
                ? 'Delivery has not been confirmed yet. If no code arrived, you can resend it when the timer ends or change your number.'
                : 'Verification request accepted. Waiting for WhatsApp delivery.',
        };
        return response()->json(['delivery_status'=>$status,'message'=>$text])->header('Cache-Control','no-store, private');
    }
    public function verify(Request $request) {
        $data = $request->validate(['challenge_id'=>'required|string|size:64','code'=>'required|digits:6']);
        $key = 'otp:'.$data['challenge_id'];
        $lock = Cache::lock($key.':lock', 10);
        abort_unless($lock->get(), 429, 'Verification in progress. Please try again.');
        try {
            $challenge = Cache::get($key);
            abort_unless($challenge && hash_equals($challenge['session'], hash('sha256',$request->session()->getId())), 422, 'This code has expired. Request a new code.');
            abort_unless(app(AuthVerification::class)->settings()[$challenge['purpose']==='login'?'login_enabled':'registration_enabled'],403);
            if (RateLimiter::tooManyAttempts($key,5)) { Cache::forget($key); abort(429,'Too many attempts. Request a new code.'); }
            RateLimiter::hit($key,300);
            abort_unless(Hash::check($data['code'],$challenge['hash']),422,'Incorrect verification code.');
            Cache::forget($key);
            if ($challenge['purpose']==='registration') {
                $token = Str::random(64);
                Cache::put('registration-proof:'.hash('sha256',$token), ['phone'=>$challenge['phone'],'role'=>$challenge['role']], now()->addHours(2));
                return response()->json(['verification_token'=>$token,'phone'=>$challenge['phone']]);
            }
            $request->merge(['role'=>$challenge['role'],'identifier'=>$challenge['phone'],'remember'=>false]);
            return app(AuthSessionController::class)->store($request, true);
        } finally { $lock->release(); }
    }
}
