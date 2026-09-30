<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCandidateDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'size:64'],
            'last_step' => ['required', 'integer', 'between:0,5'],
            'registration_source' => ['required', Rule::in(['public'])],
            'data' => ['required', 'array'],
            'data.*' => ['nullable'],
        ];
    }
}
