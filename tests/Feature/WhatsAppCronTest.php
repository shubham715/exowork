<?php

namespace Tests\Feature;

use App\Services\WhatsAppOutbox;
use App\Services\WhatsAppWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'cache.default' => 'database', 'whatsapp.provider' => 'gupshup', 'whatsapp.gupshup_automation_enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_cron_drains_the_database_queue_and_releases_its_lock(): void
    {
        $this->mock(WhatsAppWorkflow::class)->shouldReceive('run')->once();
        $row = app(WhatsAppOutbox::class)->enqueue('919876543210', ['name' => 'example', 'language' => 'en_US', 'category' => 'UTILITY'], [], ['source' => 'welcome']);
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('whatsapp:cron')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('whatsapp_messages', ['id' => $row->id, 'status' => 'skipped', 'error_code' => 'AUTOMATION_PAUSED', 'attempts' => 1]);
        $lock = Cache::lock('whatsapp:cron', 600);
        $this->assertTrue($lock->get());
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_overlapping_cron_does_not_run_workflow_or_worker(): void
    {
        $this->mock(WhatsAppWorkflow::class)->shouldNotReceive('run');
        $lock = Cache::lock('whatsapp:cron', 600);
        $lock->get();
        try {
            $this->artisan('whatsapp:cron')->expectsOutput('An earlier WhatsApp cron run is still active; skipped.')->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    public function test_cron_rejects_sync_queue_and_nonpersistent_locks(): void
    {
        $this->mock(WhatsAppWorkflow::class)->shouldNotReceive('run');
        config(['queue.default' => 'sync']);
        $this->artisan('whatsapp:cron')->assertFailed();
        config(['queue.default' => 'database', 'cache.default' => 'array']);
        $this->artisan('whatsapp:cron')->assertFailed();
    }
}
