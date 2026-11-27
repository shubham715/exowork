<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('whatsapp:process', function () {
    app(\App\Services\WhatsAppWorkflow::class)->run();
    \Illuminate\Support\Facades\DB::table('whatsapp_messages')->where('status', 'queued')->where('available_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) {
        foreach ($rows as $row) \App\Jobs\SendWhatsAppMessage::dispatch($row->id);
    });
    // A crashed worker may have posted to Meta. Keep uncertain sends out of the resend queue.
    \Illuminate\Support\Facades\DB::table('whatsapp_messages')->where('status', 'submitting')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'unknown', 'error_code' => 'WORKER_INTERRUPTED', 'error_message' => 'Submission interrupted. Check Meta before creating another send.', 'updated_at' => now()]);
})->purpose('Queue WhatsApp workflow notifications and recover the outbox');

\Illuminate\Support\Facades\Schedule::command('whatsapp:process')->everyMinute()->withoutOverlapping();

Artisan::command('candidate-emails:process', function () {
    \Illuminate\Support\Facades\DB::table('email_deliveries')->whereNotNull('rendered_payload')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'unknown', 'error' => 'Submission interrupted. Check SMTP provider before sending again.', 'updated_at' => now()]);
    $ids = \Illuminate\Support\Facades\DB::table('email_deliveries')->whereNotNull('rendered_payload')->where('status', 'pending')->distinct()->pluck('campaign_id');
    foreach ($ids as $id) \App\Jobs\SendCandidateEmailCampaign::dispatch($id);
})->purpose('Recover pending candidate email campaigns without resending uncertain submissions');
\Illuminate\Support\Facades\Schedule::command('candidate-emails:process')->everyMinute()->withoutOverlapping();
