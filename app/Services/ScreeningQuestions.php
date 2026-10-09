<?php
namespace App\Services;

class ScreeningQuestions
{
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array_map(fn ($text) => ['question' => trim($text), 'required' => false, 'type' => 'text'], preg_split('/\r?\n/', $value));
        }
        return array_values(array_map(fn ($q) => ['question' => $q['question'], 'required' => (bool) ($q['required'] ?? false), 'type' => $q['type'] ?? 'text'], array_filter($value ?? [], fn ($q) => is_array($q) && trim($q['question'] ?? '') !== '')));
    }
}
