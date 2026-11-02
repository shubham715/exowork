<?php

namespace Tests\Feature;

use Database\Seeders\CandidateMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_state_has_districts_and_seed_is_additive_and_idempotent(): void
    {
        $this->assertDatabaseCount('states', 36);
        $this->assertGreaterThanOrEqual(784, DB::table('districts')->count());
        foreach (DB::table('states')->get() as $state) {
            $this->getJson("/api/master-data/states/{$state->slug}/districts")
                ->assertOk()->assertJsonPath('state.id', $state->id);
            $this->assertGreaterThan(0, DB::table('districts')->where('state_id', $state->id)->count());
        }
        $district = DB::table('districts')->where('slug', 'jaipur')->first();
        DB::table('districts')->where('id', $district->id)->update(['is_active' => false, 'name' => 'Admin renamed Jaipur']);
        $count = DB::table('districts')->count();
        $this->seed(CandidateMasterDataSeeder::class);
        $this->assertDatabaseCount('districts', $count);
        $this->assertDatabaseHas('districts', ['id' => $district->id, 'name' => 'Admin renamed Jaipur', 'is_active' => false]);
        $state = DB::table('states')->where('id', $district->state_id)->first();
        $response = $this->getJson("/api/master-data/states/{$state->slug}/districts")->assertOk();
        $this->assertNotContains($district->id, array_column($response->json('districts'), 'id'));
    }
}
