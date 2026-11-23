<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminCandidateImportController;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CandidateImportNormalizer;
use Database\Seeders\CandidateMasterDataSeeder;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CandidateImportNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualification_variants_normalize_without_guessing_unknown_qualifications(): void
    {
        foreach (['10', '10th', '10th pass', '10 class', 'Class 10', '10th Class Passed', 'Class X', 'Tenth', 'SSC', 'Matriculation'] as $input) {
            $this->assertSame('10th pass', CandidateImportNormalizer::qualification($input), $input);
        }
        foreach (['12', '12th', '12TH PASS', '12 class', 'Class XII', 'Twelfth', 'Higher Secondary'] as $input) {
            $this->assertSame('12th pass', CandidateImportNormalizer::qualification($input), $input);
        }
        $this->assertSame('Graduate', CandidateImportNormalizer::qualification('B.Sc.'));
        $this->assertSame('Postgraduate', CandidateImportNormalizer::qualification('M.Com'));
        $this->assertSame('Custom qualification', CandidateImportNormalizer::qualification('Custom qualification'));
    }

    public function test_dates_and_concatenated_address_components_are_parsed_correctly(): void
    {
        foreach (['08-11-2002', '8/11/2002', '2002-11-08', '2002/11/8', '8 Nov 2002'] as $input) {
            $this->assertSame('2002-11-08', CandidateImportNormalizer::date($input));
        }
        $serial = (int) (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable('2002-11-08'))->days;
        $this->assertSame('2002-11-08', CandidateImportNormalizer::date((string) $serial));
        $this->assertSame('2002-11-08', CandidateImportNormalizer::date((string) ($serial - 1462), true));
        $address = 'Village/City -PEMASAR District-Bikaner Block-BIKANER Tehsil-Bikaner Muncipality-BIKANERPincode-334022';
        $this->assertSame(['city_village' => 'PEMASAR', 'district' => 'Bikaner', 'block' => 'BIKANER', 'tehsil' => 'Bikaner', 'municipality' => 'BIKANER', 'pincode' => '334022'], CandidateImportNormalizer::address($address));
        $this->assertSame(['city_village' => 'Some Village', 'district' => 'Bikaner', 'pincode' => '334022'], CandidateImportNormalizer::address('Village: Some Village, Dist.: Bikaner, Pin code: 334022'));
        foreach (['31-02-2002', '2002', '08-11-02', 'not a date'] as $input) {
            try {
                CandidateImportNormalizer::date($input);
                $this->fail('Invalid DOB accepted: '.$input);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('date_of_birth', $e->errors());
            }
        }
    }

    public function test_import_stores_normalized_fields_master_ids_and_repairs_existing_rows_without_duplicates(): void
    {
        Storage::fake();
        $this->seed([PlatformRolesSeeder::class, CandidateMasterDataSeeder::class]);
        $user = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'admin')->value('id')]);
        $this->actingAs($user->refresh());
        $csv = "Candidate Name,Phone Number,Education,Birth Date,Father's Name,Courses,Permanent Address,Present Address,Training Status\nAsha Sharma,9876543211,10 class,08-11-2002,Parent,Retail course,Village/City-PEMASAR District-Bikaner Block-BIKANER Tehsil-Bikaner Muncipality-BIKANERPincode-334022,City-Jaipur District-Jaipur Pincode-302001,Trained\n";
        $upload = $this->postJson('/admin-api/candidates/import/upload', ['file' => UploadedFile::fake()->createWithContent('other-format.csv', $csv)])->assertOk()->json();
        $payload = ['token' => $upload['token'], 'mapping' => $upload['mapping']];
        $this->postJson('/admin-api/candidates/import/preview', $payload)->assertOk()->assertJsonPath('invalid', 0)->assertJsonPath('rows.0.normalized.qualification', '10th pass');
        $this->postJson('/admin-api/candidates/import/confirm', $payload)->assertOk();
        $candidate = Candidate::firstOrFail();
        $this->assertSame('2002-11-08', $candidate->date_of_birth->format('Y-m-d'));
        $this->assertSame('10th pass', $candidate->qualification);
        $this->assertNotNull($candidate->qualification_option_id);
        $this->assertSame('Rajasthan', $candidate->state);
        $this->assertNotNull($candidate->district_id);
        $this->assertSame('PEMASAR', $candidate->permanent_city_village);
        $this->assertSame('334022', $candidate->permanent_pincode);
        $this->assertSame('Jaipur', $candidate->communication_city_village);
        $this->assertSame('Retail course', $candidate->training_course);
        $this->assertSame('', $candidate->skills);
        $this->assertSame('Completed', $candidate->training_status);
        $this->assertNull($candidate->terms_accepted_at);
        $candidate->update(['date_of_birth' => null, 'qualification' => '10 class', 'qualification_option_id' => null, 'skills' => 'Retail course', 'training_course' => null, 'permanent_city_village' => null]);
        $this->artisan('candidates:repair-imports')->assertExitCode(0);
        $this->assertNull($candidate->fresh()->date_of_birth);
        $this->artisan('candidates:repair-imports', ['--apply' => true])->assertExitCode(0);
        $this->assertDatabaseCount('candidates', 1);
        $this->assertSame($candidate->candidate_code, $candidate->fresh()->candidate_code);
        $this->assertSame('2002-11-08', $candidate->fresh()->date_of_birth->format('Y-m-d'));
        $this->assertSame('10th pass', $candidate->fresh()->qualification);
        $this->assertSame('PEMASAR', $candidate->fresh()->permanent_city_village);
        $this->assertCount(1, Storage::files('candidate-import-repairs'));
        $this->artisan('candidates:repair-imports')->expectsOutputToContain('0 imported profiles need corrections')->assertExitCode(0);
    }

    public function test_header_aliases_do_not_confuse_course_with_skills(): void
    {
        $mapping = AdminCandidateImportController::mapping(['Highest Education', 'D.O.B.', 'Courses', 'Technical Skills', 'Mobile Number', 'Communication Address']);
        $this->assertSame(['Highest Education' => 'qualification', 'D.O.B.' => 'date_of_birth', 'Courses' => 'training_course', 'Technical Skills' => 'skills', 'Mobile Number' => 'whatsapp', 'Communication Address' => 'current_location'], $mapping);
    }
}
