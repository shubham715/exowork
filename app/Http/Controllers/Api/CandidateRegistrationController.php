<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCandidateRequest;
use App\Models\Candidate;
use App\Models\CandidateDraft;
use App\Services\CandidateMasterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CandidateRegistrationController extends Controller
{
    public function store(StoreCandidateRequest $request): JsonResponse
    {
        [$validated, $languageIds] = CandidateMasterData::resolve($request->validated());
        $inviteFields = [];
        if ($request->filled('invite_token')) {
            $center = \App\Services\CenterInvite::resolve($request->invite_token);
            $batch = DB::table('training_batches')->where('training_center_id', $center->id)->where('code', $validated['batch_code'])->first();
            $inviteFields = ['training_center_id' => $center->id, 'training_partner_id' => $center->training_partner_id, 'training_batch_id' => $batch->id, 'training_status' => 'Ongoing', 'training_center' => $center->name, 'training_partner' => $center->partner_name, 'batch_end_date' => $batch->ends_on];
        }
        $storedFiles = [];

        try {
            $candidate = DB::transaction(function () use ($request, $validated, $languageIds, $inviteFields, &$storedFiles) {
                foreach (['photo', 'resume', 'certificate'] as $file) {
                    if ($request->hasFile($file)) {
                        $storedFiles[$file] = $request->file($file)->store("candidate-documents/{$file}");
                    }
                }

                $candidate = Candidate::create([
                    ...Arr::except($validated, ['password_confirmation', 'terms_accepted', 'privacy_accepted', 'photo', 'resume', 'certificate', 'draft_token', 'invite_token']),
                    ...$inviteFields,
                    'registration_source' => 'public',
                    'photo_path' => $storedFiles['photo'] ?? null,
                    'resume_path' => $storedFiles['resume'] ?? null,
                    'certificate_path' => $storedFiles['certificate'] ?? null,
                    'terms_accepted_at' => now(),
                    'privacy_accepted_at' => now(),
                    'consent_notice_version' => '1.0',
                    'registration_ip' => $request->ip(),
                ]);

                $candidate->forceFill([
                    'candidate_code' => 'EXO-CAN-'.str_pad((string) $candidate->id, 6, '0', STR_PAD_LEFT),
                ])->save();
                CandidateMasterData::saveLanguages($candidate->id, $languageIds);

                foreach (['location', 'photo', 'call', 'sms', 'whatsapp', 'email'] as $purpose) {
                    DB::table('candidate_consents')->insert([
                        'candidate_id' => $candidate->id,
                        'purpose' => $purpose,
                        'granted' => $validated[$purpose.'_consent'],
                        'notice_version' => '1.0',
                        'source' => 'public',
                        'ip_address' => $request->ip(),
                        'recorded_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                foreach (['terms', 'privacy'] as $purpose) {
                    DB::table('candidate_consents')->insert([
                        'candidate_id' => $candidate->id,
                        'purpose' => $purpose,
                        'granted' => true,
                        'notice_version' => '1.0',
                        'source' => 'public',
                        'ip_address' => $request->ip(),
                        'recorded_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                foreach ($storedFiles as $type => $path) {
                    $file = $request->file($type);
                    DB::table('candidate_documents')->insert([
                        'candidate_id' => $candidate->id,
                        'type' => $type,
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $candidate;
            });
        } catch (\Throwable $exception) {
            foreach ($storedFiles as $path) {
                Storage::delete($path);
            }

            throw $exception;
        }

        if (! empty($validated['draft_token'])) {
            CandidateDraft::query()
                ->where('token_hash', hash('sha256', $validated['draft_token']))
                ->delete();
        }

        return response()->json([
            'message' => 'Candidate profile created successfully.',
            'candidate' => [
                'id' => $candidate->id,
                'candidate_code' => $candidate->candidate_code,
                'first_name' => $candidate->first_name,
            ],
        ], 201);
    }
}
