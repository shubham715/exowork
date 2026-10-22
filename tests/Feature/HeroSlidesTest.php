<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HeroSlidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_slides_are_seeded_and_writes_require_permission(): void
    {
        $this->getJson('/public-api/hero-slides')->assertOk()->assertJsonCount(3, 'slides');
        $this->postJson('/admin-api/hero-slides', [])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/admin-api/hero-slides', [])->assertForbidden();
    }

    public function test_admin_can_create_format_upload_edit_and_delete_more_than_three_slides(): void
    {
        Storage::fake('local');
        $this->seed(PlatformRolesSeeder::class);
        $admin = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $admin->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($admin);
        $data = ['title' => 'Fourth slide', 'subtitle' => '<strong onclick="alert(1)">Bold</strong> <em>Italic</em><img src=x onerror=alert(1)>', 'sort_order' => 0];
        $id = $this->postJson('/admin-api/hero-slides', $data)->assertCreated()->json('id');
        $this->assertDatabaseHas('hero_slides', ['id' => $id, 'subtitle' => '<strong>Bold</strong> <em>Italic</em>']);
        $this->getJson('/public-api/hero-slides')->assertJsonCount(4, 'slides');
        $this->post('/admin-api/hero-slides/'.$id, ['title' => 'Updated slide', 'subtitle' => '<i>Updated</i>', 'sort_order' => 10, 'image' => UploadedFile::fake()->image('hero.png')])->assertOk();
        $path = DB::table('hero_slides')->where('id', $id)->value('image_path');
        Storage::disk('local')->assertExists($path);
        $this->get('/public-api/hero-slides/'.$id.'/image')->assertOk();
        $this->deleteJson('/admin-api/hero-slides/'.$id)->assertOk();
        $this->assertDatabaseMissing('hero_slides', ['id' => $id]);
        Storage::disk('local')->assertMissing($path);
    }
}
