<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\CandidateMasterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CenterCandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('candidates.view'), 403);
        $center = $this->center($request);
        $batches = DB::table('training_batches')->where('training_center_id', $center->id)->get();
        $candidates = Candidate::where('training_center_id', $center->id)->latest('id')->get()->map(function ($c) use ($batches) {
            $batch = $batches->firstWhere('id', $c->training_batch_id);
            $end = $batch?->ends_on ?? $c->batch_end_date?->format('Y-m-d');
            $days = $end ? now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($end)->startOfDay(), false) : null;
            $readiness = match (true) {
                $c->availability === 'No' => 'Not available',
                $c->training_status === 'Completed' && $c->availability === 'Yes' => 'Job ready',
                $c->training_status === 'Ongoing' && $days !== null && $days >= 0 && $days <= 15 => 'Eligible soon',
                $c->training_status === 'Ongoing' => 'In training',
                default => 'Not available',
            };
            $fields = ['first_name', 'last_name', 'whatsapp', 'district', 'qualification', 'skills', 'preferred_industry', 'availability', 'training_status', 'current_location'];
            $complete = collect($fields)->filter(fn ($field) => filled($c->$field))->count();
            return [
                'id' => $c->candidate_code ?? 'EXO-CAN-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT),
                'firstName' => $c->first_name, 'lastName' => $c->last_name,
                'whatsapp' => $c->whatsapp, 'email' => $c->email, 'district' => $c->district,
                'qualification' => $c->qualification, 'skills' => $c->skills,
                'industry' => $c->preferred_industry, 'salary' => $c->expected_monthly_salary,
                'available' => $c->availability, 'trainingStatus' => $c->training_status,
                'batch' => $batch?->code ?? $c->batch_code, 'batchEnd' => $end,
                'consent' => (bool) ($c->terms_accepted_at && $c->privacy_accepted_at),
                'profile' => (int) round($complete / count($fields) * 100),
                'readiness' => $readiness, 'experienceType' => $c->experience_type,
                'experience' => $c->experience_details, 'relocate' => $c->relocation_preference,
                'consentRecordedAt' => $c->terms_accepted_at?->toIso8601String(),
                'consentVersion' => $c->consent_notice_version,
                'source' => $c->registration_source,
            ];
        });

        return response()->json(['center' => $center, 'candidates' => $candidates, 'batches' => $batches->map(fn ($b) => [
            'code' => $b->code, 'jobRole' => $b->job_role, 'endDate' => $b->ends_on,
        ])]);
    }

    private const CSV_FIELDS = ['candidate_code', 'first_name', 'last_name', 'whatsapp', 'alternate_mobile', 'email', 'gender', 'age', 'qualification', 'state', 'district', 'current_location', 'permanent_location', 'preferred_locations', 'relocation_preference', 'skills', 'experience_type', 'experience_details', 'expected_monthly_salary', 'availability', 'preferred_industry', 'languages', 'training_status', 'batch_code'];

    public function csv(Request $request)
    {
        abort_unless($request->user()->hasPermission('candidates.view'), 403);
        $center = $this->center($request);
        $sample = $request->boolean('sample');
        return response()->streamDownload(function () use ($center, $sample) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::CSV_FIELDS);
            if ($sample) {
                // Templates contain headers only; no fictitious candidate records.

            } else {
                Candidate::where('training_center_id', $center->id)->orderBy('id')->chunk(200, function ($candidates) use ($out) {
                    foreach ($candidates as $candidate) {
                        fputcsv($out, array_map(function ($field) use ($candidate) {
                            $value = $candidate->$field;
                            if ($field === 'candidate_code') $value = $value ?: 'EXO-CAN-'.str_pad((string) $candidate->id, 6, '0', STR_PAD_LEFT);
                            if (is_array($value)) $value = implode(';', $value);
                            $value = (string) ($value ?? '');
                            return preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value;
                        }, self::CSV_FIELDS));
                    }
                });
            }
            fclose($out);
        }, $sample ? 'candidate-template.csv' : 'center-candidates.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvData(array $row, object $center): array
    {
        $row = array_merge(['training_status' => '', 'batch_code' => ''], $row);
        $row['training_status'] = $row['training_status'] ?: 'Not enrolled';
        $row['batch_code'] = $row['batch_code'] ?: null;
        $code = $row['candidate_code'] ?? '';
        $existing = $code ? Candidate::where('candidate_code', $code)->first() : null;
        if ($existing && $existing->training_center_id !== $center->id) throw \Illuminate\Validation\ValidationException::withMessages(['candidate_code' => 'This candidate ID is not owned by your center.']);
        if ($code && !preg_match('/^EXO-CAN-\d{6,}$/', $code)) throw \Illuminate\Validation\ValidationException::withMessages(['candidate_code' => 'Use an EXO-CAN candidate ID or leave it blank for a new candidate.']);
        $data = \Illuminate\Support\Arr::except($row, ['candidate_code']);
        foreach (['preferred_locations', 'languages'] as $field) $data[$field] = array_values(array_filter(array_map('trim', explode(';', $data[$field] ?? ''))));
        $rules = (new \App\Http\Requests\StoreCandidateRequest)->rules();
        foreach (['password', 'registration_source', 'draft_token', 'training_partner', 'training_center', 'batch_end_date', 'aadhaar_number', 'photo', 'resume', 'certificate', 'terms_accepted', 'privacy_accepted', 'location_consent', 'photo_consent', 'call_consent', 'sms_consent', 'whatsapp_consent', 'email_consent'] as $field) unset($rules[$field]);
        foreach (['qualification_option_id' => ['qualification', 'qualification'], 'experience_level_option_id' => ['experience_level', 'experience_type'], 'industry_option_id' => ['industry', 'preferred_industry']] as $field => [$type, $name]) {
            $data[$field] = DB::table('master_options')->where('type', $type)->where('name', $data[$name] ?? '')->where('is_active', true)->value('id');
        }
        $data['state_id'] = DB::table('states')->where('name', $data['state'] ?? '')->where('is_active', true)->value('id');
        $data['district_id'] = DB::table('districts')->where('state_id', $data['state_id'])->where('name', $data['district'] ?? '')->where('is_active', true)->value('id');
        $data['district_is_custom'] = !$data['district_id'];
        $data['language_option_ids'] = [];
        foreach ($data['languages'] as $language) {
            $id = DB::table('master_options')->where('type', 'language')->where('name', $language)->where('is_active', true)->value('id');
            if (!$id) throw \Illuminate\Validation\ValidationException::withMessages(['languages' => "Unknown language: {$language}"]);
            $data['language_option_ids'][] = $id;
        }
        $rules['whatsapp'] = ['required', 'digits:10', Rule::unique('candidates', 'whatsapp')->ignore($existing?->id)];
        $rules['email'] = ['nullable', 'email', 'max:255', Rule::unique('candidates', 'email')->ignore($existing?->id)];
        $rules['training_status'] = ['required', Rule::in(['Ongoing', 'Completed', 'Not enrolled'])];
        $rules['batch_code'] = ['nullable', 'string', 'max:80', Rule::exists('training_batches', 'code')->where('training_center_id', $center->id)];
        $data['email'] = empty($data['email']) ? null : strtolower($data['email']);
        $data = \Illuminate\Support\Facades\Validator::make($data, $rules, ['batch_code.exists' => 'The batch code must match a batch saved by your center. Leave it blank to import without a batch.'])->validate();
        [$data, $languageIds] = CandidateMasterData::resolve($data);
        $batch = $this->ownedBatch($center->id, $data['batch_code'] ?? null, $existing?->id);
        $data = array_merge($data, ['training_center_id' => $center->id, 'training_partner_id' => $center->training_partner_id, 'training_center' => $center->name, 'training_partner' => $center->partner_name, 'training_batch_id' => $batch?->id, 'batch_end_date' => $batch?->ends_on]);
        return [$existing, $data, $languageIds];
    }

    public function previewImport(Request $request): JsonResponse
    {
        $center = $this->center($request);
        abort_unless($request->user()->hasPermission('candidates.create') && $request->user()->hasPermission('candidates.update'), 403);
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($handle);
        if ($headers) $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        abort_unless(is_array($headers) && count($headers) === count(array_unique($headers)) && !array_diff(array_diff(self::CSV_FIELDS, ['batch_code', 'training_status']), $headers) && !array_diff($headers, self::CSV_FIELDS), 422, 'CSV headers must match the template; training_status and batch_code may be omitted.');
        $rows = []; $report = []; $seen = []; $creates = 0; $updates = 0; $invalid = 0;
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null]) continue;
            abort_if(count($rows) >= 1000, 422, 'Upload at most 1,000 candidates at a time.');
            $number = count($rows) + 2;
            $row = count($values) === count($headers) ? array_combine($headers, array_map('trim', $values)) : [];
            $rows[] = $row;
            try {
                if (!$row) throw \Illuminate\Validation\ValidationException::withMessages(['csv' => 'Incorrect number of columns.']);
                foreach (['candidate_code', 'whatsapp', 'email'] as $key) {
                    $value = strtolower($row[$key]);
                    if ($value && isset($seen[$key][$value])) throw \Illuminate\Validation\ValidationException::withMessages([$key => 'Duplicate value in this CSV.']);
                    if ($value) $seen[$key][$value] = true;
                }
                [$existing] = $this->csvData($row, $center);
                $existing ? $updates++ : $creates++;
                $report[] = ['row' => $number, 'name' => $row['first_name'].' '.$row['last_name'], 'action' => $existing ? 'Update' : 'Add', 'errors' => [], 'warnings' => []];
            } catch (\Illuminate\Validation\ValidationException $e) {
                $invalid++;
                $report[] = ['row' => $number, 'name' => $row['first_name'] ?? '', 'action' => 'Invalid', 'errors' => array_merge(...array_values($e->errors())), 'warnings' => []];
            }
        }
        fclose($handle);
        abort_unless(count($rows), 422, 'The CSV contains no candidates.');
        $token = bin2hex(random_bytes(24));
        $request->session()->put('candidate_import', ['token' => $token, 'rows' => $rows, 'center' => $center->id, 'expires' => now()->addMinutes(30)->timestamp, 'invalid' => $invalid]);
        return response()->json(['token' => $token, 'total' => count($rows), 'add' => $creates, 'update' => $updates, 'invalid' => $invalid, 'rows' => $report]);
    }

    public function confirmImport(Request $request): JsonResponse
    {
        $center = $this->center($request);
        abort_unless($request->user()->hasPermission('candidates.create') && $request->user()->hasPermission('candidates.update'), 403);
        $preview = $request->session()->get('candidate_import');
        abort_unless($preview && hash_equals($preview['token'], (string) $request->input('token')) && $preview['center'] === $center->id && $preview['expires'] >= now()->timestamp && !$preview['invalid'], 422, 'Preview a valid CSV again before confirming.');
        DB::transaction(function () use ($preview, $center, $request) {
            foreach ($preview['rows'] as $row) {
                [$candidate, $data, $languages] = $this->csvData($row, $center);
                if ($candidate) $candidate->update($data);
                else {
                    $candidate = Candidate::create($data + ['registration_source' => 'center_csv', 'created_by_user_id' => $request->user()->id]);
                    $candidate->forceFill(['candidate_code' => 'EXO-CAN-'.str_pad((string) $candidate->id, 6, '0', STR_PAD_LEFT)])->save();
                }
                DB::table('candidate_languages')->where('candidate_id', $candidate->id)->delete();
                CandidateMasterData::saveLanguages($candidate->id, $languages);
            }
        });
        $request->session()->forget('candidate_import');
        return response()->json(['message' => 'Candidates imported successfully.']);
    }

    public function edit(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->hasPermission('candidates.update'), 403);
        $center = $this->center($request);
        $candidate = Candidate::where('candidate_code', $code)->where('training_center_id', $center->id)->firstOrFail();
        $row = [];
        foreach (self::CSV_FIELDS as $field) $row[$field] = is_array($candidate->$field) ? implode(';', $candidate->$field) : (string) ($candidate->$field ?? '');
        if ($request->isMethod('get')) return response()->json(['candidate' => $row]);
        $row = array_merge($row, $request->only(self::CSV_FIELDS), ['candidate_code' => $code]);
        [$existing, $data, $languages] = $this->csvData($row, $center);
        DB::transaction(function () use ($existing, $data, $languages, $center) {
            $this->ownedBatch($center->id, $data['batch_code'] ?? null, $existing->id);
            $existing->update($data);
            DB::table('candidate_languages')->where('candidate_id', $existing->id)->delete();
            CandidateMasterData::saveLanguages($existing->id, $languages);
        });
        return response()->json(['message' => 'Candidate updated.']);
    }

    public function invite(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('candidates.create'), 403);
        $center = $this->center($request);
        return response()->json(['url' => url('/register/candidate').'?invite='.\Illuminate\Support\Facades\Crypt::encryptString((string) $center->id)]);
    }

    public function sendInvite(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('candidates.create'), 403);
        $center = $this->center($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        if (in_array(config('mail.default'), ['log', 'array', 'failover'], true)) {
            return response()->json(['message' => 'Email delivery is not configured yet. Please use Copy link to share the invitation.'], 503);
        }
        $url = url('/register/candidate').'?invite='.\Illuminate\Support\Facades\Crypt::encryptString((string) $center->id);
        try {
            \Illuminate\Support\Facades\Mail::to($data['email'])->send(new \App\Mail\CandidateInvitation($center->name, $center->partner_name, $url));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'The invitation could not be sent. Please try again or copy the link.'], 503);
        }
        return response()->json(['message' => 'Invitation sent to '.$data['email'].'.']);
    }

    protected function ownedBatch(int $centerId, ?string $code, ?int $candidateId = null): ?object
    {
        if (!$code) return null;
        $batch = DB::table('training_batches')->where('training_center_id', $centerId)->where('code', $code)->lockForUpdate()->first();
        if (!$batch) throw \Illuminate\Validation\ValidationException::withMessages(['batch_code' => 'The batch is no longer available.']);
        $count = Candidate::where('training_batch_id', $batch->id)->when($candidateId, fn ($q) => $q->where('id', '!=', $candidateId))->count();
        if ($batch->capacity !== null && $count >= $batch->capacity) throw \Illuminate\Validation\ValidationException::withMessages(['batch_code' => 'This batch is full. Leave the batch blank or select another batch.']);
        return $batch;
    }

    protected function center(Request $request): object
    {
        $center = DB::table('organization_memberships')
            ->join('training_centers', 'training_centers.id', '=', 'organization_memberships.training_center_id')
            ->leftJoin('training_partners', 'training_partners.id', '=', 'training_centers.training_partner_id')
            ->where('organization_memberships.user_id', $request->user()->id)
            ->where('organization_memberships.status', 'active')
            ->whereNull('training_centers.deleted_at')
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
            'qualification_option_id' => ['required', 'integer'],
            'state' => ['required', 'string', 'max:80'], 'district' => ['required', 'string', 'max:80'],
            'state_id' => ['required', 'integer'], 'district_id' => [Rule::requiredIf(! $request->boolean('district_is_custom')), 'nullable', 'integer'],
            'district_is_custom' => ['required', 'boolean'],
            'current_location' => ['required', 'string', 'max:255'], 'permanent_location' => ['required', 'string', 'max:255'],
            'preferred_locations' => ['required', 'array', 'min:1'], 'preferred_locations.*' => ['string', 'max:120'],
            'relocation_preference' => ['required', 'string', 'max:40'],
            'skills' => ['required', 'string', 'max:2000'],
            'experience_type' => ['required', 'string', 'max:20'],
            'experience_level_option_id' => ['required', 'integer'],
            'experience_details' => ['nullable', 'string', 'max:2000'],
            'expected_monthly_salary' => ['required', 'integer', 'between:0,10000000'],
            'availability' => ['required', Rule::in(['Yes', 'No', 'Available after training'])],
            'preferred_industry' => ['required', 'string', 'max:60'],
            'industry_option_id' => ['required', 'integer'],
            'languages' => ['required', 'array', 'min:1'], 'languages.*' => ['string', 'max:40'],
            'language_option_ids' => ['required', 'array', 'min:1', 'max:10'], 'language_option_ids.*' => ['integer'],
            'training_status' => ['nullable', Rule::in(['Ongoing', 'Completed', 'Not enrolled'])],
            'batch_id' => ['nullable', Rule::exists('training_batches', 'id')->where('training_center_id', $center->id)],
            'aadhaar_number' => ['nullable', 'digits:12'],
            'call_consent' => ['sometimes', 'boolean'], 'sms_consent' => ['sometimes', 'boolean'],
            'whatsapp_consent' => ['sometimes', 'boolean'], 'email_consent' => ['sometimes', 'boolean'],
            'terms_accepted' => ['sometimes', 'boolean'], 'privacy_accepted' => ['sometimes', 'boolean'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);
        [$data, $languageIds] = CandidateMasterData::resolve($data);
        $batch = isset($data['batch_id']) ? DB::table('training_batches')->where('id', $data['batch_id'])->first() : null;
        $paths = [];
        try {
            foreach (['resume', 'certificate'] as $type) {
                if ($request->hasFile($type)) {
                    $paths[$type] = $request->file($type)->store("candidate-documents/{$type}");
                }
            }
            $candidate = DB::transaction(function () use ($request, $data, $center, $batch, $paths, $languageIds) {
                $batch = $this->ownedBatch($center->id, $batch?->code);
                $candidate = Candidate::create([
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'whatsapp' => $data['whatsapp'], 'alternate_mobile' => $data['alternate_mobile'] ?? null,
                    'email' => isset($data['email']) ? strtolower($data['email']) : null,
                    'gender' => $data['gender'], 'age' => $data['age'], 'qualification' => $data['qualification'],
                    'state' => $data['state'], 'district' => $data['district'],
                    'state_id' => $data['state_id'] ?? null, 'district_id' => $data['district_id'] ?? null,
                    'district_is_custom' => $data['district_is_custom'] ?? false,
                    'qualification_option_id' => $data['qualification_option_id'] ?? null,
                    'experience_level_option_id' => $data['experience_level_option_id'] ?? null,
                    'industry_option_id' => $data['industry_option_id'] ?? null,
                    'current_location' => $data['current_location'], 'permanent_location' => $data['permanent_location'],
                    'preferred_locations' => $data['preferred_locations'], 'relocation_preference' => $data['relocation_preference'],
                    'skills' => $data['skills'], 'experience_type' => $data['experience_type'],
                    'experience_details' => $data['experience_details'] ?? null,
                    'expected_monthly_salary' => $data['expected_monthly_salary'], 'availability' => $data['availability'],
                    'preferred_industry' => $data['preferred_industry'], 'languages' => $data['languages'],
                    'training_status' => $data['training_status'] ?? 'Not enrolled', 'training_partner' => $center->partner_name,
                    'training_center' => $center->name, 'batch_code' => $batch?->code,
                    'batch_end_date' => $batch?->ends_on,
                    'training_partner_id' => $center->training_partner_id, 'training_center_id' => $center->id,
                    'training_batch_id' => $batch?->id, 'created_by_user_id' => $request->user()->id,
                    'aadhaar_number' => $data['aadhaar_number'] ?? null,
                    'resume_path' => $paths['resume'] ?? null, 'certificate_path' => $paths['certificate'] ?? null,
                    'location_consent' => false, 'photo_consent' => false,
                    'call_consent' => $data['call_consent'] ?? false, 'sms_consent' => $data['sms_consent'] ?? false,
                    'whatsapp_consent' => $data['whatsapp_consent'] ?? false, 'email_consent' => $data['email_consent'] ?? false,
                    'terms_accepted_at' => !empty($data['terms_accepted']) ? now() : null, 'privacy_accepted_at' => !empty($data['privacy_accepted']) ? now() : null,
                    'consent_notice_version' => '1.0', 'registration_source' => 'center',
                    'registration_ip' => $request->ip(),
                ]);
                $candidate->forceFill(['candidate_code' => 'EXO-CAN-'.str_pad((string) $candidate->id, 6, '0', STR_PAD_LEFT)])->save();
                CandidateMasterData::saveLanguages($candidate->id, $languageIds);
                foreach (['call', 'sms', 'whatsapp', 'email', 'terms', 'privacy'] as $purpose) {
                    $key = $purpose.(in_array($purpose, ['terms', 'privacy']) ? '_accepted' : '_consent');
                    if (!array_key_exists($key, $data)) continue;
                    DB::table('candidate_consents')->insert([
                        'candidate_id' => $candidate->id, 'purpose' => $purpose,
                        'granted' => (bool) ($data[$purpose.(in_array($purpose, ['terms', 'privacy']) ? '_accepted' : '_consent')] ?? false),
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
