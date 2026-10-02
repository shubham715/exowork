<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CandidateMasterDataSeeder;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_options_and_state_slug_districts_come_from_database(): void
    {
        $this->seed(CandidateMasterDataSeeder::class);
        $this->getJson('/api/master-data')->assertOk()
            ->assertJsonFragment(['slug' => 'iti', 'name' => 'ITI'])
            ->assertJsonFragment(['slug' => 'rajasthan', 'name' => 'Rajasthan']);
        $this->getJson('/api/master-data/states/rajasthan/districts')->assertOk()
            ->assertJsonFragment(['slug' => 'jaipur', 'name' => 'Jaipur']);
        $this->getJson('/api/master-data/states/missing/districts')->assertNotFound();
    }

    public function test_admin_can_add_and_deactivate_a_district(): void
    {
        $this->seed(PlatformRolesSeeder::class);
        $this->seed(CandidateMasterDataSeeder::class);
        $stateId = DB::table('states')->where('slug', 'rajasthan')->value('id');
        $this->postJson('/admin-api/master-data/district', ['name' => 'Bikaner', 'state_id' => $stateId])->assertUnauthorized();
        $admin = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $admin->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $response = $this->actingAs($admin)->postJson('/admin-api/master-data/district', ['name' => 'Bikaner', 'state_id' => $stateId]);
        $response->assertCreated()->assertJsonPath('item.slug', 'bikaner');
        $id = $response->json('item.id');
        $this->patchJson("/admin-api/master-data/district/{$id}", ['is_active' => false])->assertOk();
        $this->getJson('/api/master-data/states/rajasthan/districts')->assertDontSee('bikaner');
    }
}
