<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Validation\ValidationException;

class CandidateMessageContent
{
    public const FIELDS = ['name' => 'Candidate full name', 'first_name' => 'Candidate first name', 'last_name' => 'Candidate last name', 'email' => 'Email address', 'whatsapp' => 'WhatsApp number', 'candidate_code' => 'Candidate ID', 'qualification' => 'Education / qualification', 'district' => 'Home district', 'state' => 'State', 'training_center' => 'Training center', 'training_course' => 'Training course', 'registered_on' => 'Registration date', 'app_name' => 'Application name'];

    public static function values(Candidate $candidate): array
    {
        return ['name' => trim($candidate->first_name.' '.$candidate->last_name),
            ...$candidate->only(['first_name', 'last_name', 'email', 'whatsapp', 'candidate_code', 'qualification', 'district', 'state', 'training_center', 'training_course']),
            'registered_on' => $candidate->created_at?->timezone('Asia/Kolkata')->format('F j, Y') ?? '', 'app_name' => config('app.name', 'EXOWORK')];
    }

    public static function render(string $content, array $values): string
    {
        $rendered = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($match) use ($values) {
            if (! array_key_exists($match[1], self::FIELDS)) {
                throw ValidationException::withMessages(['content' => 'Unknown placeholder '.$match[0].'. Use the listed placeholders.']);
            }
            $value = trim((string) ($values[$match[1]] ?? ''));
            if ($value === '') {
                throw ValidationException::withMessages(['content' => 'Candidate has no value for '.$match[0].'.']);
            }

            return $value;
        }, $content);
        if (str_contains($rendered, '{{') || str_contains($rendered, '}}')) {
            throw ValidationException::withMessages(['content' => 'Use placeholders such as {{first_name}}.']);
        }

        return $rendered;
    }
}
