<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use Database\Seeders\PlatformRolesSeeder;
use Database\Seeders\CandidateMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CenterCandidateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_center_candidate_is_saved_with_owned_batch_and_consent(): void
    {
        $this->seed(PlatformRolesSeeder::class);
        $this->seed(CandidateMasterDataSeeder::class);
        $user = User::factory()->create(['email' => 'partner@example.com']);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'training_partner')->value('id')]);
        $partnerId = DB::table('training_partners')->insertGetId(['code' => 'TP-1', 'name' => 'Partner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $centerId = DB::table('training_centers')->insertGetId(['training_partner_id' => null, 'code' => 'CTR-1', 'name' => 'Center', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_memberships')->insert(['user_id' => $user->id, 'training_center_id' => $centerId, 'membership_role' => 'owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $batchId = DB::table('training_batches')->insertGetId(['training_center_id' => $centerId, 'code' => 'B-1', 'job_role' => 'Operator', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->postJson('/center-api/candidates', $this->payload($batchId))
            ->assertCreated()->assertJsonPath('candidate.candidate_code', 'EXO-CAN-000001');
        $candidate = Candidate::firstOrFail();
        $this->assertSame($centerId, $candidate->training_center_id);
        $this->assertSame($batchId, $candidate->training_batch_id);
        $this->assertNull($candidate->password);
        $this->assertDatabaseCount('candidate_consents', 6);
        $this->assertNotNull($candidate->state_id);
        $this->assertNotNull($candidate->district_id);
        $this->assertNotNull($candidate->qualification_option_id);
        $this->assertDatabaseCount('candidate_languages', 1);

        $this->getJson('/center-api/candidates')->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.id', $candidate->candidate_code)
            ->assertJsonPath('candidates.0.batch', 'B-1')
            ->assertJsonPath('candidates.0.consent', true)
            ->assertJsonPath('candidates.0.readiness', 'In training')
            ->assertJsonMissingPath('candidates.0.aadhaar_number');

        $export = $this->get('/center-api/candidates/csv')->assertOk()->streamedContent();
        $preview = $this->postJson('/center-api/candidates/import/preview', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('data.csv', str_replace('Asha', 'Anita', $export))])
            ->assertOk()->assertJsonPath('update', 1)->assertJsonPath('invalid', 0);
        $this->assertSame('Asha', $candidate->fresh()->first_name);
        $this->postJson('/center-api/candidates/import/confirm', ['token' => $preview->json('token')])->assertOk();
        $this->assertSame('Anita', $candidate->fresh()->first_name);
        $this->postJson('/center-api/candidates/import/confirm', ['token' => $preview->json('token')])->assertUnprocessable();
        $this->patchJson('/center-api/candidates/'.$candidate->candidate_code.'/edit', ['first_name' => 'Asha'])->assertOk();
        $invalid = $this->postJson('/center-api/candidates/import/preview', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('bad.csv', str_replace('9876543211', 'invalid', $export))])->assertOk()->assertJsonPath('invalid', 1);
        $this->postJson('/center-api/candidates/import/confirm', ['token' => $invalid->json('token')])->assertUnprocessable();
        $newPreview = $this->postJson('/center-api/candidates/import/preview', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('new.csv', str_replace([$candidate->candidate_code, '9876543211'], ['', '9876543299'], $export))])->assertOk()->assertJsonPath('add', 1)->assertJsonPath('invalid', 0);
        $this->postJson('/center-api/candidates/import/confirm', ['token' => $newPreview->json('token')])->assertOk();
        $newCandidate = Candidate::where('whatsapp', '9876543299')->firstOrFail();
        $this->assertNull($newCandidate->terms_accepted_at);
        $this->assertSame($centerId, $newCandidate->training_center_id);
        $newCandidate->delete();
        $invite = $this->getJson('/center-api/candidates/invite')->assertOk()->json('url');
        parse_str(parse_url($invite, PHP_URL_QUERY), $inviteQuery);
        $sample = $this->get('/center-api/candidates/csv?sample=1')->assertOk()->assertDownload('candidate-template.csv')->streamedContent();
        $this->assertStringContainsString('candidate_code,first_name,last_name', $sample);
        $this->assertStringNotContainsString('B-1', $sample);
        \Illuminate\Support\Facades\Mail::fake();
        config(['mail.default' => 'log']);
        $this->postJson('/center-api/candidates/invite', ['email' => 'learner@example.com'])->assertStatus(503);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        config(['mail.default' => 'smtp']);
        $this->postJson('/center-api/candidates/invite', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/center-api/candidates/invite', ['email' => 'learner@example.com'])->assertOk();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CandidateInvitation::class, function ($mail) {
            return $mail->hasTo('learner@example.com') && $mail->centerName === 'Center' && str_contains($mail->registrationUrl, '/register/candidate?invite=');
        });
        $email = new \App\Mail\CandidateInvitation('Center', 'Partner', $invite);
        $this->assertStringContainsString('Register your profile', $email->render());

        $this->getJson('/api/candidate-invite?token='.urlencode($inviteQuery['invite']))->assertOk()->assertJsonPath('center.id', $centerId);
        $this->getJson('/api/candidate-invite?token=invalid')->assertUnprocessable();
        $publicPayload = $this->payload($batchId) + ['password' => 'password123', 'password_confirmation' => 'password123', 'registration_source' => 'public', 'location_consent' => false, 'photo_consent' => false];
        $publicPayload = array_merge($publicPayload, ['invite_token' => $inviteQuery['invite'], 'whatsapp' => '9876543288', 'training_status' => 'Completed', 'training_partner' => 'Wrong partner', 'training_center' => 'Wrong center', 'batch_code' => 'B-1', 'batch_end_date' => '2000-01-01']);
        $this->postJson('/api/candidates', $publicPayload)->assertCreated();
        $invited = Candidate::where('whatsapp', '9876543288')->firstOrFail();
        $this->assertSame($centerId, $invited->training_center_id);
        $this->assertSame('Center', $invited->training_center);
        $this->assertSame('Ongoing', $invited->training_status);
        $this->assertSame('2026-12-31', $invited->batch_end_date->format('Y-m-d'));
        $invited->delete();


        $otherCenter = DB::table('training_centers')->insertGetId(['training_partner_id' => $partnerId, 'code' => 'CTR-2', 'name' => 'Other', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherBatch = DB::table('training_batches')->insertGetId(['training_center_id' => $otherCenter, 'code' => 'B-2', 'job_role' => 'Operator', 'created_at' => now(), 'updated_at' => now()]);
        $otherCandidate = $candidate->replicate(['candidate_code']);
        $otherCandidate->forceFill(['candidate_code' => 'EXO-CAN-999999', 'whatsapp' => '9876543212', 'training_center_id' => $otherCenter, 'training_batch_id' => $otherBatch])->save();
        $this->getJson('/center-api/candidates')->assertOk()->assertJsonCount(1, 'candidates')->assertJsonCount(1, 'batches');
        $this->patchJson('/center-api/candidates/EXO-CAN-999999/edit', ['first_name' => 'Changed'])->assertNotFound();
        $foreignCsv = str_replace([$candidate->candidate_code, '9876543211'], ['EXO-CAN-999999', '9876543212'], $export);
        $this->postJson('/center-api/candidates/import/preview', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('foreign.csv', $foreignCsv)])->assertOk()->assertJsonPath('invalid', 1);

        $candidate->update(['availability' => 'No']);
        $this->getJson('/center-api/candidates')->assertJsonPath('candidates.0.readiness', 'Not available');
        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission_id' => DB::table('permissions')->where('key', 'candidates.view')->value('id'), 'allowed' => false]);
        $this->getJson('/center-api/candidates')->assertForbidden();
        $this->postJson('/center-api/candidates', $this->payload($otherBatch))->assertUnprocessable()->assertJsonValidationErrors('batch_id');

        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission_id' => DB::table('permissions')->where('key', 'candidates.create')->value('id'), 'allowed' => false]);
        $this->postJson('/center-api/candidates', $this->payload($batchId))->assertForbidden();
    }

    public function test_database_batches_optional_import_and_capacity(): void
    {
        $this->seed(PlatformRolesSeeder::class);
        $this->seed(CandidateMasterDataSeeder::class);
        $user = User::factory()->create(['email' => 'partner@example.com']);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'training_partner')->value('id')]);
        $partnerId = DB::table('training_partners')->insertGetId(['code' => 'TP-1', 'name' => 'Partner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $centerId = DB::table('training_centers')->insertGetId(['training_partner_id' => null, 'code' => 'CTR-1', 'name' => 'Center', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_memberships')->insert(['user_id' => $user->id, 'training_center_id' => $centerId, 'membership_role' => 'owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        $batch = ['code' => ' new-1 ', 'sector' => 'Manufacturing', 'jobRole' => 'Operator', 'trainer' => 'Trainer', 'trainerPhone' => '9876543210', 'startDate' => '2026-10-01', 'endDate' => '2026-12-31', 'capacity' => 1];
        $this->postJson('/center-api/batches', $batch)->assertCreated();
        $this->postJson('/center-api/batches', $batch)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/center-api/batches')->assertOk()->assertJsonPath('batches.0.code', 'NEW-1');
        $batchId = DB::table('training_batches')->where('code', 'NEW-1')->value('id');
        $payload = $this->payload($batchId);
        unset($payload['batch_id'], $payload['terms_accepted'], $payload['privacy_accepted'], $payload['call_consent'], $payload['sms_consent'], $payload['whatsapp_consent'], $payload['email_consent']);
        $this->postJson('/center-api/candidates', $payload)->assertCreated();
        $candidate = Candidate::firstOrFail();
        $this->assertNull($candidate->training_batch_id);
        $this->assertNull($candidate->terms_accepted_at);
        $this->assertNull($candidate->privacy_accepted_at);
        $this->assertFalse((bool) $candidate->whatsapp_consent);
        $this->assertDatabaseCount('candidate_consents', 0);
        $export = $this->get('/center-api/candidates/csv')->streamedContent();
        $csv = str_replace([$candidate->candidate_code, '9876543211'], ['', '9876543299'], $export);
        $preview = $this->postJson('/center-api/candidates/import/preview', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('unassigned.csv', $csv)])->assertOk()->assertJsonPath('invalid', 0)->assertJsonPath('rows.0.warnings', []);
        $this->postJson('/center-api/candidates/import/confirm', ['token' => $preview->json('token')])->assertOk();
        $this->assertSame(2, Candidate::whereNull('training_batch_id')->count());
        $lines = preg_split('/\r?\n/', trim($csv));
        $withoutBatch = implode("\n", array_map(function ($line) {
            $fields = str_getcsv($line);
            array_pop($fields); array_pop($fields);
            $out = fopen('php://temp', 'w+'); fputcsv($out, $fields); rewind($out);
            return trim(stream_get_contents($out));
        }, $lines));
        $withoutBatch = str_replace('9876543299', '9876543277', $withoutBatch);
        $this->postJson('/center-api/candidates/import/preview', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('no-batch-columns.csv', $withoutBatch)])->assertOk()->assertJsonPath('invalid', 0);
        $bad = str_replace('9876543299', '9876543278', $csv);
        $bad = preg_replace('/,Ongoing,$/m', ',Ongoing,UNKNOWN', $bad);
        $this->postJson('/center-api/candidates/import/preview', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('unknown.csv', $bad)])->assertOk()->assertJsonPath('invalid', 1);

        $codes = Candidate::pluck('candidate_code')->all();
        $this->postJson("/center-api/batches/{$batchId}/assign", ['candidates' => $codes])->assertUnprocessable();
        $this->postJson("/center-api/batches/{$batchId}/assign", ['candidates' => [$codes[0]]])->assertOk();
        $this->assertDatabaseCount('candidate_batch_assignments', 1);
        $this->postJson('/center-api/candidates', array_merge($this->payload($batchId), ['whatsapp' => '9876543280']))->assertUnprocessable();
        $this->putJson("/center-api/batches/{$batchId}", array_merge($batch, ['code' => 'RENAMED']))->assertOk();
        $this->assertSame('RENAMED', $candidate->fresh()->batch_code);
        $this->postJson('/center-api/batches', array_merge($batch, ['code' => 'SECOND']))->assertCreated();
        $second = DB::table('training_batches')->where('code', 'SECOND')->value('id');
        $this->postJson("/center-api/batches/{$second}/assign", ['candidates' => [$codes[0]]])->assertOk();
        $this->assertDatabaseCount('candidate_batch_assignments', 2);
        $otherCenter = DB::table('training_centers')->insertGetId(['code' => 'OTHER', 'name' => 'Other', 'state' => 'Rajasthan', 'district' => 'Jaipur', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('training_batches')->insertGetId(['training_center_id' => $otherCenter, 'code' => 'FOREIGN', 'job_role' => 'Operator', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson("/center-api/batches/{$foreign}", $batch)->assertNotFound();
        $this->postJson("/center-api/batches/{$foreign}/assign", ['candidates' => [$codes[0]]])->assertNotFound();
        $this->postJson('/center-api/candidates', array_merge($payload, ['whatsapp' => '9876543281', 'batch_id' => $foreign]))->assertUnprocessable();
        $this->assertSame(1, substr_count(trim($this->get('/center-api/candidates/csv?sample=1')->streamedContent()), "\n") + 1);
    }

    public function test_guest_cannot_create_center_candidate(): void
    {
        $this->postJson('/center-api/candidates', [])->assertUnauthorized();
        $this->getJson('/center-api/candidates')->assertUnauthorized();
    }

    private function payload(int $batchId): array
    {
        return [
            'first_name' => 'Asha', 'last_name' => 'Sharma', 'whatsapp' => '9876543211',
            'gender' => 'Female', 'age' => 24, 'qualification' => 'ITI',
            'state' => 'Rajasthan', 'district' => 'Jaipur', 'current_location' => 'Jaipur',
            'state_id' => DB::table('states')->where('slug', 'rajasthan')->value('id'),
            'district_id' => DB::table('districts')->where('slug', 'jaipur')->value('id'),
            'district_is_custom' => false,
            'qualification_option_id' => DB::table('master_options')->where('type', 'qualification')->where('slug', 'iti')->value('id'),
            'experience_level_option_id' => DB::table('master_options')->where('type', 'experience_level')->where('slug', 'fresher')->value('id'),
            'industry_option_id' => DB::table('master_options')->where('type', 'industry')->where('slug', 'manufacturing')->value('id'),
            'language_option_ids' => [DB::table('master_options')->where('type', 'language')->where('slug', 'hindi')->value('id')],
            'permanent_location' => 'Jaipur', 'preferred_locations' => ['Jaipur'],
            'relocation_preference' => 'No', 'skills' => 'Machine operation',
            'experience_type' => 'Fresher', 'expected_monthly_salary' => 15000,
            'availability' => 'Yes', 'preferred_industry' => 'Manufacturing',
            'languages' => ['Hindi'], 'training_status' => 'Ongoing', 'batch_id' => $batchId,
            'call_consent' => true, 'sms_consent' => true, 'whatsapp_consent' => true,
            'email_consent' => false, 'terms_accepted' => true, 'privacy_accepted' => true,
        ];
    }
}
