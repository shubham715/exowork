<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CenterCandidateController extends Controller
{
    private function center(Request $request): object
    {
        $center = DB::table('organization_memberships')
            ->join('training_centers', 'training_centers.id', '=', 'organization_memberships.training_center_id')
            ->join('training_partners', 'training_partners.id', '=', 'training_centers.training_partner_id')
            ->where('organization_memberships.user_id', $request->user()->id)
            ->where('organization_memberships.status', 'active')
            ->where('training_centers.status', 'active')
            ->where('training_partners.status', 'active')
            ->select('training_centers.id', 'training_centers.name', 'training_centers.code', 'training_centers.training_partner_id', 'training_partners.name as partner_name')
            ->first();
        abort_unless($center, 403, 'No active training center membership.');

        return $center;
    }

    public function context(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('candidates.view'), 403);
        $center = $this->center($request);
        $batches = DB::table('training_batches')->where('training_center_id', $center->id)
            ->orderByDesc('starts_on')->get(['id', 'code', 'job_role', 'ends_on', 'status']);

        return response()->json(['center' => $center, 'batches' => $batches]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('candidates.create'), 403);
        $center = $this->center($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'whatsapp' => ['required', 'digits:10', 'unique:candidates,whatsapp'],
            'alternate_mobile' => ['nullable', 'digits:10'],
            'email' => ['nullable', 'email', 'max:255', 'unique:candidates,email'],
            'gender' => ['required', Rule::in(['Female', 'Male', 'Other', 'Prefer not to say'])],
            'age' => ['required', 'integer', 'between:15,80'],
            'qualification' => ['required', 'string', 'max:60'],
            'state' => ['required', 'string', 'max:80'], 'district' => ['required', 'string', 'max:80'],
            'current_location' => ['required', 'string', 'max:255'], 'permanent_location' => ['required', 'string', 'max:255'],
            'preferred_locations' => ['required', 'array', 'min:1'], 'preferred_locations.*' => ['string', 'max:120'],
            'relocation_preference' => ['required', 'string', 'max:40'],
            'skills' => ['required', 'string', 'max:2000'],
            'experience_type' => ['required', Rule::in(['Fresher', 'Experienced'])],
            'experience_details' => ['nullable', 'string', 'max:2000'],
            'expected_monthly_salary' => ['required', 'integer', 'between:0,10000000'],
            'availability' => ['required', Rule::in(['Yes', 'No', 'Available after training'])],
            'preferred_industry' => ['required', 'string', 'max:60'],
            'languages' => ['required', 'array', 'min:1'], 'languages.*' => ['string', 'max:40'],
            'training_status' => ['required', Rule::in(['Ongoing', 'Completed'])],
            'batch_id' => ['required', Rule::exists('training_batches', 'id')->where('training_center_id', $center->id)],
            'aadhaar_number' => ['nullable', 'digits:12'],
            'call_consent' => ['required', 'boolean'], 'sms_consent' => ['required', 'boolean'],
            'whatsapp_consent' => ['required', 'boolean'], 'email_consent' => ['required', 'boolean'],
            'terms_accepted' => ['accepted'], 'privacy_accepted' => ['accepted'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);
        $batch = DB::table('training_batches')->where('id', $data['batch_id'])->first();
        $paths = [];
        try {
            foreach (['resume', 'certificate'] as $type) {
                if ($request->hasFile($type)) {
                    $paths[$type] = $request->file($type)->store("candidate-documents/{$type}");
                }
            }
            $candidate = DB::transaction(function () use ($request, $data, $center, $batch, $paths) {
                $candidate = Candidate::create([
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'whatsapp' => $data['whatsapp'], 'alternate_mobile' => $data['alternate_mobile'] ?? null,
                    'email' => isset($data['email']) ? strtolower($data['email']) : null,
                    'gender' => $data['gender'], 'age' => $data['age'], 'qualification' => $data['qualification'],
                    'state' => $data['state'], 'district' => $data['district'],
                    'current_location' => $data['current_location'], 'permanent_location' => $data['permanent_location'],
                    'preferred_locations' => $data['preferred_locations'], 'relocation_preference' => $data['relocation_preference'],
                    'skills' => $data['skills'], 'experience_type' => $data['experience_type'],
                    'experience_details' => $data['experience_details'] ?? null,
                    'expected_monthly_salary' => $data['expected_monthly_salary'], 'availability' => $data['availability'],
                    'preferred_industry' => $data['preferred_industry'], 'languages' => $data['languages'],
                    'training_status' => $data['training_status'], 'training_partner' => $center->partner_name,
                    'training_center' => $center->name, 'batch_code' => $batch->code,
                    'batch_end_date' => $batch->ends_on,
                    'training_partner_id' => $center->training_partner_id, 'training_center_id' => $center->id,
                    'training_batch_id' => $batch->id, 'created_by_user_id' => $request->user()->id,
                    'aadhaar_number' => $data['aadhaar_number'] ?? null,
                    'resume_path' => $paths['resume'] ?? null, 'certificate_path' => $paths['certificate'] ?? null,
                    'location_consent' => false, 'photo_consent' => false,
                    'call_consent' => $data['call_consent'], 'sms_consent' => $data['sms_consent'],
                    'whatsapp_consent' => $data['whatsapp_consent'], 'email_consent' => $data['email_consent'],
                    'terms_accepted_at' => now(), 'privacy_accepted_at' => now(),
                    'consent_notice_version' => '1.0', 'registration_source' => 'center',
                    'registration_ip' => $request->ip(),
                ]);
                $candidate->forceFill(['candidate_code' => 'EXO-CAN-'.str_pad((string) $candidate->id, 6, '0', STR_PAD_LEFT)])->save();
                foreach (['call', 'sms', 'whatsapp', 'email', 'terms', 'privacy'] as $purpose) {
                    DB::table('candidate_consents')->insert([
                        'candidate_id' => $candidate->id, 'purpose' => $purpose,
                        'granted' => in_array($purpose, ['terms', 'privacy']) || $data[$purpose.'_consent'],
                        'notice_version' => '1.0', 'source' => 'center',
                        'recorded_by_user_id' => $request->user()->id, 'ip_address' => $request->ip(),
                        'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                foreach ($paths as $type => $path) {
                    $file = $request->file($type);
                    DB::table('candidate_documents')->insert([
                        'candidate_id' => $candidate->id, 'type' => $type, 'disk' => config('filesystems.default'),
                        'path' => $path, 'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                        'uploaded_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                return $candidate;
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::delete($path);
            }
            throw $exception;
        }

        return response()->json(['candidate' => ['id' => $candidate->id, 'candidate_code' => $candidate->candidate_code]], 201);
    }
}
