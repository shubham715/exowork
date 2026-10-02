<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\CandidateMasterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CandidateProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Candidate $candidate */
        $candidate = $request->user('candidate');

        return response()->json(['candidate' => $this->profileData($candidate)]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Candidate $candidate */
        $candidate = $request->user('candidate');
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'alternate_mobile' => ['nullable', 'digits:10', 'different:whatsapp', Rule::unique('candidates', 'alternate_mobile')->ignore($candidate->id)],
            'gender' => ['required', Rule::in(['Female', 'Male', 'Other', 'Prefer not to say'])],
            'age' => ['required', 'integer', 'between:15,80'],
            'qualification' => ['required', 'string', 'max:60'],
            'qualification_option_id' => ['required', 'integer'],
            'state' => ['required', 'string', 'max:80'],
            'state_id' => ['required', 'integer'],
            'district' => ['required', 'string', 'max:80'],
            'district_id' => [Rule::requiredIf(! $request->boolean('district_is_custom')), 'nullable', 'integer'],
            'district_is_custom' => ['required', 'boolean'],
            'current_location' => ['required', 'string', 'max:255'],
            'permanent_location' => ['required', 'string', 'max:255'],
            'preferred_locations' => ['required', 'array', 'min:1', 'max:10'],
            'preferred_locations.*' => ['string', 'max:120'],
            'relocation_preference' => ['required', 'string', 'max:40'],
            'skills' => ['required', 'string', 'max:2000'],
            'experience_type' => ['required', 'string', 'max:20'],
            'experience_level_option_id' => ['required', 'integer'],
            'experience_details' => ['nullable', 'required_if:experience_type,Experienced', 'string', 'max:2000'],
            'expected_monthly_salary' => ['required', 'integer', 'between:0,10000000'],
            'availability' => ['required', Rule::in(['Yes', 'No', 'Available after training'])],
            'preferred_industry' => ['required', 'string', 'max:60'],
            'industry_option_id' => ['required', 'integer'],
            'languages' => ['required', 'array', 'min:1', 'max:10'],
            'languages.*' => ['string', 'max:40'],
            'language_option_ids' => ['required', 'array', 'min:1', 'max:10'],
            'language_option_ids.*' => ['integer'],
            'location_consent' => ['required', 'boolean'],
            'photo_consent' => ['required', 'boolean'],
            'call_consent' => ['required', 'boolean'],
            'sms_consent' => ['required', 'boolean'],
            'whatsapp_consent' => ['required', 'boolean'],
            'email_consent' => ['required', 'boolean'],
        ]);

        [$resolved, $languageIds] = CandidateMasterData::resolve($validated);
        $changes = [];

        DB::transaction(function () use ($request, $candidate, $resolved, $languageIds, &$changes) {
            foreach (['location', 'photo', 'call', 'sms', 'whatsapp', 'email'] as $purpose) {
                $field = $purpose.'_consent';
                if ((bool) $candidate->{$field} !== (bool) $resolved[$field]) {
                    $changes[] = [
                        'candidate_id' => $candidate->id,
                        'purpose' => $purpose,
                        'granted' => $resolved[$field],
                        'notice_version' => '1.0',
                        'source' => 'candidate_profile',
                        'ip_address' => $request->ip(),
                        'recorded_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $candidate->fill(Arr::except($resolved, ['language_option_ids']))->save();
            DB::table('candidate_languages')->where('candidate_id', $candidate->id)->delete();
            CandidateMasterData::saveLanguages($candidate->id, $languageIds);

            if ($changes) {
                DB::table('candidate_consents')->insert($changes);
            }
        });

        return response()->json([
            'message' => 'Profile updated successfully.',
            'candidate' => $this->profileData($candidate->fresh()),
        ]);
    }

    private function profileData(Candidate $candidate): array
    {
        return [
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'full_name' => trim($candidate->first_name.' '.$candidate->last_name),
            'candidate_code' => $candidate->candidate_code,
            'whatsapp' => $candidate->whatsapp,
            'email' => $candidate->email,
            'alternate_mobile' => $candidate->alternate_mobile,
            'gender' => $candidate->gender,
            'age' => $candidate->age,
            'qualification' => $candidate->qualification,
            'qualification_option_id' => $candidate->qualification_option_id,
            'state' => $candidate->state,
            'state_id' => $candidate->state_id,
            'district' => $candidate->district,
            'district_id' => $candidate->district_id,
            'district_is_custom' => (bool) $candidate->district_is_custom,
            'current_location' => $candidate->current_location,
            'permanent_location' => $candidate->permanent_location,
            'preferred_locations' => $candidate->preferred_locations ?? [],
            'relocation_preference' => $candidate->relocation_preference,
            'skills' => $candidate->skills,
            'experience_type' => $candidate->experience_type,
            'experience_level_option_id' => $candidate->experience_level_option_id,
            'experience_details' => $candidate->experience_details,
            'expected_monthly_salary' => $candidate->expected_monthly_salary,
            'availability' => $candidate->availability,
            'preferred_industry' => $candidate->preferred_industry,
            'industry_option_id' => $candidate->industry_option_id,
            'languages' => $candidate->languages ?? [],
            'training_status' => $candidate->training_status,
            'training_partner' => $candidate->training_partner,
            'training_center' => $candidate->training_center,
            'batch_code' => $candidate->batch_code,
            'batch_end_date' => $candidate->batch_end_date?->toDateString(),
            'location_consent' => (bool) $candidate->location_consent,
            'photo_consent' => (bool) $candidate->photo_consent,
            'call_consent' => (bool) $candidate->call_consent,
            'sms_consent' => (bool) $candidate->sms_consent,
            'whatsapp_consent' => (bool) $candidate->whatsapp_consent,
            'email_consent' => (bool) $candidate->email_consent,
            'photo_added' => filled($candidate->photo_path),
            'aadhaar_added' => filled($candidate->getRawOriginal('aadhaar_number')),
            'profile_status' => $candidate->profile_status,
        ];
    }
}
