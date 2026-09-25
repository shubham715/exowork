<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_react_application_routes_return_the_spa_shell(): void
    {
        foreach (['/', '/candidate/dashboard', '/employer/jobs', '/center/batches', '/admin/reports'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('<div id="root"></div>', false);
        }
    }

    public function test_api_routes_are_not_handled_by_the_spa_fallback(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'application' => 'EXOWORK',
            ]);

        $this->getJson('/api/not-a-real-endpoint')->assertNotFound();
    }
}
