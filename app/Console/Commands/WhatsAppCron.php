<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WhatsAppCron extends Command
{
    protected $signature = 'whatsapp:cron';

    protected $description = 'Check WhatsApp stages and process a bounded queue batch for cron hosting';

    public function handle(): int
    {
        if (config('queue.default') !== 'database') {
            $this->error('Set QUEUE_CONNECTION=database before running whatsapp:cron.');
            return self::FAILURE;
        }
        $store = config('cache.default');
        if (in_array(config('cache.stores.'.$store.'.driver'), ['array', 'null', null], true)) {
            $this->error('Use a persistent CACHE_STORE, such as database or file, for the cron overlap lock.');
            return self::FAILURE;
        }
        if ((int) config('queue.connections.database.retry_after') <= 60) {
            $this->error('DB_QUEUE_RETRY_AFTER must exceed the 60-second worker timeout (use 180).');
            return self::FAILURE;
        }
        if (app()->isDownForMaintenance()) {
            $this->info('Maintenance mode: WhatsApp cron skipped.');
            return self::SUCCESS;
        }

        $lock = Cache::lock('whatsapp:cron', 600);
        if (! $lock->get()) {
            $this->info('An earlier WhatsApp cron run is still active; skipped.');
            return self::SUCCESS;
        }

        try {
            $this->info('WhatsApp cron started at '.now()->toIso8601String());
            $workflowResult = self::SUCCESS;
            try {
                $workflowResult = $this->call('whatsapp:process');
            } catch (\Throwable $e) {
                // A stage-check failure must not strand consented manual test messages.
                report($e);
                $this->error('Stage checks failed. Review storage/logs/laravel.log.');
                $workflowResult = self::FAILURE;
            }

            $workerResult = $this->call('queue:work', [
                'connection' => 'database',
                '--queue' => config('queue.connections.database.queue', 'default'),
                '--stop-when-empty' => true,
                '--max-time' => 45,
                '--max-jobs' => 50,
                '--sleep' => 1,
                '--tries' => 1,
                '--timeout' => 60,
                '--memory' => 128,
            ]);

            $this->info('WhatsApp cron finished.');
            return $workflowResult === self::SUCCESS ? $workerResult : $workflowResult;
        } finally {
            $lock->release();
        }
    }
}
