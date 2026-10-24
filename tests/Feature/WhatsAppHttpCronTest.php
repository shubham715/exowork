<?php

namespace Tests\Feature;

use App\Services\WhatsAppOutbox;
use App\Services\WhatsAppWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppHttpCronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.http_cron_enabled' => true, 'cache.default' => 'database', 'queue.default' => 'database', 'whatsapp.provider' => 'gupshup', 'whatsapp.gupshup_automation_enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_http_cron_requires_run_parameter_and_enabled_setting(): void
    {
        $this->mock(WhatsAppWorkflow::class)->shouldNotReceive('run');
        $this->getJson('/api/cron/whatsapp')->assertStatus(400);
        $this->getJson('/api/cron/whatsapp?run=0')->assertStatus(400);
        config(['whatsapp.http_cron_enabled' => false]);
        $this->getJson('/api/cron/whatsapp?run=1')->assertNotFound();
    }

    public function test_http_cron_processes_one_outbox_record_without_creating_queue_jobs(): void
    {
        $this->mock(WhatsAppWorkflow::class)->shouldReceive('run')->once()->with(\Mockery::type('float'));
        for ($i = 0; $i < 2; $i++) app(WhatsAppOutbox::class)->enqueue('91987654321'.$i, ['name' => 'example', 'language' => 'en_US', 'category' => 'UTILITY'], [], ['source' => 'welcome']);
        $this->assertDatabaseCount('jobs', 0);
        $this->getJson('/api/cron/whatsapp?run=1')->assertOk()->assertJson(['status' => 'ok', 'processed' => 1]);
        $this->postJson('/api/cron/whatsapp?run=1')->assertStatus(429)->assertJsonPath('status', 'rate_limited');
        $this->assertDatabaseHas('whatsapp_messages', ['recipient' => '919876543210', 'status' => 'skipped']);
        $this->assertDatabaseHas('whatsapp_messages', ['recipient' => '919876543211', 'status' => 'queued']);
        $lock = Cache::lock('whatsapp:cron', 600);
        $this->assertTrue($lock->get());
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_http_cron_shares_overlap_lock_with_cli(): void
    {
        $this->mock(WhatsAppWorkflow::class)->shouldNotReceive('run');
        $lock = Cache::lock('whatsapp:cron', 600);
        $lock->get();
        try {
            $this->getJson('/api/cron/whatsapp?run=1')->assertOk()->assertJsonPath('status', 'busy');
        } finally {
            $lock->release();
        }
    }

    public function test_http_cron_rejects_nonpersistent_cache(): void
    {
        config(['cache.default' => 'array']);
        $this->mock(WhatsAppWorkflow::class)->shouldNotReceive('run');
        $this->postJson('/api/cron/whatsapp?run=1')->assertStatus(503);
    }
}
