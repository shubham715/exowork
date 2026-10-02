<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CandidateMasterData
{
    public static function resolve(array $data): array
    {
        $fields = [
            'qualification_option_id' => ['qualification', 'qualification'],
            'experience_level_option_id' => ['experience_level', 'experience_type'],
            'industry_option_id' => ['industry', 'preferred_industry'],
        ];
        foreach ($fields as $idField => [$type, $nameField]) {
            if (! empty($data[$idField])) {
                $row = DB::table('master_options')->where('id', $data[$idField])->where('type', $type)->where('is_active', true)->first();
                if (! $row) throw ValidationException::withMessages([$idField => 'Select an active option.']);
                $data[$nameField] = $row->name;
            }
        }
        if (! empty($data['state_id'])) {
            $state = DB::table('states')->where('id', $data['state_id'])->where('is_active', true)->first();
            if (! $state) throw ValidationException::withMessages(['state_id' => 'Select an active state.']);
            $data['state'] = $state->name;
        }
        if (! empty($data['district_id'])) {
            $district = DB::table('districts')->where('id', $data['district_id'])->where('is_active', true)->first();
            if (! $district || $district->state_id !== (int) ($data['state_id'] ?? 0)) {
                throw ValidationException::withMessages(['district_id' => 'Select a district in the chosen state.']);
            }
            $data['district'] = $district->name;
            $data['district_is_custom'] = false;
        } elseif (! empty($data['state_id'])) {
            if (empty($data['district_is_custom'])) {
                throw ValidationException::withMessages(['district_id' => 'Select a district or choose Other.']);
            }
            $data['district_is_custom'] = true;
        }
        $languageIds = array_values(array_unique(array_map('intval', $data['language_option_ids'] ?? [])));
        if ($languageIds) {
            $rows = DB::table('master_options')->where('type', 'language')->where('is_active', true)
                ->whereIn('id', $languageIds)->get(['id', 'name']);
            if ($rows->count() !== count($languageIds)) {
                throw ValidationException::withMessages(['language_option_ids' => 'Select active languages.']);
            }
            $data['languages'] = $rows->pluck('name')->all();
        }
        unset($data['language_option_ids']);

        return [$data, $languageIds];
    }

    public static function saveLanguages(int $candidateId, array $languageIds): void
    {
        foreach ($languageIds as $id) {
            DB::table('candidate_languages')->insert([
                'candidate_id' => $candidateId,
                'language_option_id' => $id,
            ]);
        }
    }
}
