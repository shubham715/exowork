<?php

namespace App\Http\Controllers;

use App\Jobs\SendWhatsAppMessage;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppHttpCronController extends Controller
{
    public function __invoke(Request $request, WhatsAppWorkflow $workflow, WhatsAppCloud $cloud)
    {
        abort_unless(config('whatsapp.http_cron_enabled'), 404);
        abort_unless($request->query('run') === '1', 400, 'Add run=1 to start a cron run.');
        $store = config('cache.default');
        abort_if(in_array(config('cache.stores.'.$store.'.driver'), ['array', 'null', null], true), 503, 'A persistent cache is required.');
        $lock = Cache::lock('whatsapp:cron', 600);
        if (! $lock->get()) return response()->json(['status' => 'busy', 'processed' => 0])->header('Cache-Control', 'no-store');
        try {
            $rateKey = 'whatsapp:http-cron';
            if (RateLimiter::tooManyAttempts($rateKey, 1)) {
                return response()->json(['status' => 'rate_limited', 'processed' => 0, 'retry_after' => RateLimiter::availableIn($rateKey)], 429)
                    ->header('Retry-After', RateLimiter::availableIn($rateKey))->header('Cache-Control', 'no-store');
            }
            RateLimiter::hit($rateKey, 60);
            DB::table('whatsapp_messages')->where('status', 'submitting')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'unknown', 'error_code' => 'WORKER_INTERRUPTED', 'error_message' => 'Submission interrupted. Check the provider before creating another send.', 'updated_at' => now()]);
            // A short workflow slice and one message keep shared-host HTTP requests bounded.
            // Existing send jobs retain all consent checks, atomic claims and uncertain-send handling.
            $workflow->run(microtime(true) + 8);
            $id = DB::table('whatsapp_messages')->where('status', 'queued')->where('available_at', '<=', now())->orderBy('available_at')->orderBy('id')->value('id');
            if ($id) (new SendWhatsAppMessage($id))->handle($cloud);
            return response()->json(['status' => 'ok', 'processed' => $id ? 1 : 0])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Cron failed. Check the server application log.'], 500)->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }
}
