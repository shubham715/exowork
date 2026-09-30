<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'whatsapp' => preg_replace('/\D+/', '', (string) $this->whatsapp),
            'alternate_mobile' => preg_replace('/\D+/', '', (string) $this->alternate_mobile) ?: null,
            'aadhaar_number' => preg_replace('/\D+/', '', (string) $this->aadhaar_number) ?: null,
            'email' => $this->filled('email') ? strtolower(trim((string) $this->email)) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'whatsapp' => ['required', 'digits:10', 'unique:candidates,whatsapp'],
            'alternate_mobile' => ['nullable', 'digits:10', 'different:whatsapp'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:candidates,email'],
            'password' => ['required', 'confirmed', 'min:8', 'max:72'],
            'gender' => ['required', Rule::in(['Female', 'Male', 'Other', 'Prefer not to say'])],
            'age' => ['required', 'integer', 'between:15,80'],
            'qualification' => ['required', Rule::in(['8th pass', '10th pass', '12th pass', 'ITI', 'Diploma', 'Graduate', 'Postgraduate'])],
            'state' => ['required', 'string', 'max:80'],
            'district' => ['required', 'string', 'max:80'],
            'current_location' => ['required', 'string', 'max:255'],
            'permanent_location' => ['required', 'string', 'max:255'],
            'preferred_locations' => ['required', 'array', 'min:1', 'max:10'],
            'preferred_locations.*' => ['string', 'max:120'],
            'relocation_preference' => ['required', 'string', 'max:40'],
            'skills' => ['required', 'string', 'max:2000'],
            'experience_type' => ['required', Rule::in(['Fresher', 'Experienced'])],
            'experience_details' => ['nullable', 'required_if:experience_type,Experienced', 'string', 'max:2000'],
            'expected_monthly_salary' => ['required', 'integer', 'between:0,10000000'],
            'availability' => ['required', Rule::in(['Yes', 'No', 'Available after training'])],
            'preferred_industry' => ['required', 'string', 'max:60'],
            'languages' => ['required', 'array', 'min:1', 'max:10'],
            'languages.*' => ['string', 'max:40'],
            'training_status' => ['required', Rule::in(['Ongoing', 'Completed', 'Not enrolled'])],
            'training_partner' => ['nullable', 'required_unless:training_status,Not enrolled', 'string', 'max:255'],
            'training_center' => ['nullable', 'required_unless:training_status,Not enrolled', 'string', 'max:255'],
            'batch_code' => ['nullable', 'required_unless:training_status,Not enrolled', 'string', 'max:80'],
            'batch_end_date' => ['nullable', 'required_unless:training_status,Not enrolled', 'date'],
            'aadhaar_number' => ['nullable', 'digits:12'],
            'location_consent' => ['required', 'boolean'],
            'photo_consent' => ['required', 'boolean'],
            'call_consent' => ['required', 'boolean'],
            'sms_consent' => ['required', 'boolean'],
            'whatsapp_consent' => ['required', 'boolean'],
            'email_consent' => ['required', 'boolean'],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
            'registration_source' => ['required', Rule::in(['public'])],
            'draft_token' => ['nullable', 'string', 'size:64'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
