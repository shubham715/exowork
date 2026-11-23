<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminCandidateImportController;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CandidateSpreadsheet;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCandidateImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Storage::fake();
        $this->seed(PlatformRolesSeeder::class);
        $user = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($user->refresh());

        return $user;
    }

    private function upload(string $csv): array
    {
        return $this->postJson('/admin-api/candidates/import/upload', ['file' => UploadedFile::fake()->createWithContent('candidates.csv', $csv)])->assertOk()->json();
    }

    public function test_preview_confirm_ownership_encrypted_extras_and_full_export(): void
    {
        $user = $this->admin();
        $upload = $this->upload("Aspirant Name,Mobile No.,Father Name,Students KB Code,Students KB Code,Age\nAsha Sharma,9876543211,Parent,KB1,KB2,24\n");
        $payload = ['token' => $upload['token'], 'mapping' => $upload['mapping']];
        $this->assertDatabaseCount('candidates', 0);
        $this->postJson('/admin-api/candidates/import/confirm', $payload)->assertUnprocessable();
        $this->postJson('/admin-api/candidates/import/preview', $payload)->assertOk()->assertJsonPath('invalid', 0);
        $this->postJson('/admin-api/candidates/import/confirm', $payload)->assertOk();
        $c = Candidate::firstOrFail();
        $this->assertSame($user->id, $c->created_by_user_id);
        $this->assertSame('KB2', $c->import_data['values']['Students KB Code [2]']);
        $this->assertStringNotContainsString('Parent', DB::table('candidates')->value('import_data'));
        $this->assertFalse($c->whatsapp_consent);
        $this->assertNull($c->terms_accepted_at);
        $this->assertNull($c->expected_monthly_salary);
        $this->getJson('/admin-api/candidates')->assertOk()->assertJsonMissingPath('data.0.import_data')->assertJsonPath('data.0.created_by_user_id', $user->id);
        $this->getJson('/admin-api/candidates/'.$c->candidate_code)->assertOk()->assertJsonPath('candidate.source_fields.Father Name', 'Parent');
        $csv = $this->get('/admin-api/candidates/export?full=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('created_by_user_id', $csv);
        $this->assertStringContainsString('KB2', $csv);
        $this->assertStringContainsString('Parent', $csv);
        $this->postJson('/admin-api/candidates/import/confirm', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('candidates', 1);
        $upload = $this->upload("first_name,whatsapp\nAnother,9876543211\n");
        $this->postJson('/admin-api/candidates/import/preview', ['token' => $upload['token'], 'mapping' => $upload['mapping']])->assertOk()->assertJsonPath('invalid', 1);
    }

    public function test_invalid_rows_duplicate_mapping_and_permissions_block_save(): void
    {
        $this->admin();
        $upload = $this->upload("first_name,whatsapp,Other\nAsha,9876543211,=SUM(A1)\nBad,invalid,x\nSame,9876543211,x\n");
        $payload = ['token' => $upload['token'], 'mapping' => $upload['mapping']];
        $this->postJson('/admin-api/candidates/import/preview', $payload)->assertOk()->assertJsonPath('invalid', 2);
        $this->postJson('/admin-api/candidates/import/confirm', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('candidates', 0);
        $payload['mapping']['Other'] = 'first_name';
        $this->postJson('/admin-api/candidates/import/preview', $payload)->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->postJson('/admin-api/candidates/import/upload')->assertForbidden();
        $this->get('/admin-api/candidates/sample')->assertForbidden();
    }

    public function test_skip_invalid_requires_explicit_choice_and_imports_only_valid_rows(): void
    {
        $user = $this->admin();
        $upload = $this->upload("first_name,whatsapp,Reference\nAsha,9876543211,keep\nBad,invalid,skip\nDuplicate,9876543211,skip\nAnita,9876543212,keep too\n");
        $payload = ['token' => $upload['token'], 'mapping' => $upload['mapping']];
        $this->postJson('/admin-api/candidates/import/preview', $payload)->assertOk()->assertJsonPath('invalid', 2)->assertJsonPath('add', 2);
        $this->postJson('/admin-api/candidates/import/confirm', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('candidates', 0);
        $this->postJson('/admin-api/candidates/import/confirm', $payload + ['skip_invalid' => true])->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('skipped', 2);
        $this->assertSame(['Asha', 'Anita'], Candidate::orderBy('id')->pluck('first_name')->all());
        $this->assertSame([2, 5], Candidate::orderBy('id')->get()->map(fn ($candidate) => $candidate->import_data['row'])->all());
        $this->assertSame(2, Candidate::where('created_by_user_id', $user->id)->count());
        $this->assertDatabaseCount('audit_logs', 2);
        $this->getJson('/admin-api/review-notifications')->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'items');
        Candidate::where('first_name', 'Asha')->update(['registration_source' => 'public']);
        $this->getJson('/admin-api/review-notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('items.0.kind', 'candidates');
        Candidate::where('first_name', 'Asha')->update(['registration_source' => 'center_csv']);
        $this->getJson('/admin-api/review-notifications')->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'items');

        $this->postJson('/admin-api/candidates/import/confirm', $payload + ['skip_invalid' => true])->assertUnprocessable();
    }

    public function test_skip_invalid_cannot_confirm_an_empty_import(): void
    {
        $this->admin();
        $upload = $this->upload("first_name,whatsapp\nBad,invalid\n");
        $payload = ['token' => $upload['token'], 'mapping' => $upload['mapping']];
        $this->postJson('/admin-api/candidates/import/preview', $payload)->assertOk()->assertJsonPath('add', 0);
        $this->postJson('/admin-api/candidates/import/confirm', $payload + ['skip_invalid' => true])->assertUnprocessable();
        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_skip_invalid_requires_a_new_preview_when_valid_rows_change(): void
    {
        $this->admin();
        $upload = $this->upload("first_name,whatsapp\nAsha,9876543211\nAnita,9876543212\nBad,invalid\n");
        $payload = ['token' => $upload['token'], 'mapping' => $upload['mapping']];
        $this->postJson('/admin-api/candidates/import/preview', $payload)->assertOk();
        // Simulate another registration after the preview without importing the file.
        Candidate::create([
            'first_name' => 'Existing', 'last_name' => '', 'whatsapp' => '9876543211', 'gender' => '', 'age' => null,
            'qualification' => '', 'state' => '', 'district' => '', 'current_location' => '', 'permanent_location' => '',
            'preferred_locations' => [], 'relocation_preference' => '', 'skills' => '', 'experience_type' => '',
            'expected_monthly_salary' => null, 'availability' => 'No', 'preferred_industry' => '', 'languages' => [], 'training_status' => '',
        ]);
        $this->postJson('/admin-api/candidates/import/confirm', $payload + ['skip_invalid' => true])->assertUnprocessable();
        $this->assertDatabaseCount('candidates', 1);
    }

    public function test_supplied_workbook_selects_visible_aspirants_and_preserves_duplicate_headers(): void
    {
        $path = base_path('../documents/ICA DDUGKY Aspirants Detail 7th Phase (2).xlsx');
        if (! file_exists($path)) {
            $this->markTestSkipped('Local source workbook not present.');
        }
        $table = (new CandidateSpreadsheet)->read($path, 'xlsx');
        $this->assertSame('Aspirants List ICA', $table['sheet']);
        $this->assertContains('Students KB Code [2]', $table['headers']);
        $this->assertSame(3, $table['records'][0]['row']);
        $this->assertGreaterThan(400, count($table['records']));
        $this->assertSame('full_name', AdminCandidateImportController::mapping($table['headers'])['Aspirant Name']);
        $this->admin();
        $upload = $this->postJson('/admin-api/candidates/import/upload', ['file' => new UploadedFile($path, basename($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)])->assertOk()->json();
        $result = $this->postJson('/admin-api/candidates/import/preview', ['token' => $upload['token'], 'mapping' => $upload['mapping']])->assertOk();
        $this->assertSame(count($table['records']), $result->json('total'));
        $this->assertDatabaseCount('candidates', 0);
    }
}
