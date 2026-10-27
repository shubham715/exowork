<?php
namespace App\Http\Controllers;

use App\Services\{AuthVerification, CommunicationSettings, WhatsAppCloud};
use App\Models\{Candidate, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Hash, RateLimiter};
use Illuminate\Support\Str;

class PhoneAuthController extends Controller
{
    public function settings() { return response()->json(array_intersect_key(app(AuthVerification::class)->settings(), array_flip(['login_enabled', 'registration_enabled'])))->header('Cache-Control', 'no-store'); }
    public function admin(Request $request) {
        abort_unless($request->user()?->hasPermission('masters.manage'), 403);
        if ($request->isMethod('put')) {
            $data = $request->validate(['login_enabled'=>'required|boolean','registration_enabled'=>'required|boolean','template_name'=>'nullable|regex:/^[a-z0-9_]+$/|max:100','template_language'=>'required|string|max:20','gupshup_template_id'=>'nullable|string|max:100']);
            app(CommunicationSettings::class)->save('authentication', $data);
        }
        return response()->json(app(AuthVerification::class)->settings())->header('Cache-Control', 'no-store');
    }
    public function send(Request $request) {
        $data = $request->validate(['phone'=>['required','regex:/^[6-9][0-9]{9}$/'],'role'=>'required|in:candidate,center,employer','purpose'=>'required|in:login,registration']);
        $settings = app(AuthVerification::class)->settings();
        abort_unless($settings[$data['purpose']==='login'?'login_enabled':'registration_enabled'], 403, 'WhatsApp verification is disabled.');
        $key = 'phone-otp:'.hash('sha256', $data['phone']);
        abort_if(RateLimiter::tooManyAttempts($key, 1), 429, 'Please wait 60 seconds before requesting another code.');
        RateLimiter::hit($key, 60);
        $code = (string)random_int(100000, 999999);
        app(CommunicationSettings::class)->apply();
        abort_if(empty($settings['template_name']) || (config('whatsapp.provider')==='gupshup' && empty($settings['gupshup_template_id'])), 503, 'WhatsApp verification is being set up. Please contact EXOWORK support.');
        $response = app(WhatsAppCloud::class)->send('91'.$data['phone'], ['name'=>$settings['template_name'],'language'=>$settings['template_language'],'id'=>$settings['gupshup_template_id'],'authentication'=>true], [$code]);
        abort_unless($response->successful() && !in_array(strtolower((string)$response->json('status')), ['error','failed','failure']), 502, 'We could not send the code. Please try again later.');
        $id = Str::random(64);
        Cache::put('otp:'.$id, [...$data, 'hash'=>Hash::make($code), 'session'=>hash('sha256',$request->session()->getId())], now()->addMinutes(5));
        return response()->json(['challenge_id'=>$id,'expires_in'=>300,'retry_after'=>60]);
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
