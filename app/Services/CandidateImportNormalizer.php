<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CandidateImportNormalizer
{
    private ?array $options = null;

    private ?array $districts = null;

    private ?array $states = null;

    public static function qualification(string $value): string
    {
        $key = CandidateSpreadsheet::key($value);
        $school = preg_replace('/(?:class|standard|std|grade|passed|pass|completed|complete)/', '', $key);
        foreach (['8th pass' => ['8', '8th', 'viii', 'eighth'], '10th pass' => ['10', '10th', 'x', 'xth', 'tenth'], '12th pass' => ['12', '12th', 'xii', 'xiith', 'twelfth']] as $name => $aliases) {
            if (in_array($school, $aliases, true)) {
                return $name;
            }
        }

        return match ($key) {
            '8', '8th', '8thpass', 'class8', 'class8th' => '8th pass',
            '10', '10th', '10thpass', 'class10', 'class10th', 'secondary', 'secondaryschool', 'ssc', 'sslc', 'matric', 'matriculation' => '10th pass',
            '12', '12th', '12thpass', 'class12', 'class12th', 'seniorsecondary', 'highersecondary', 'highersecondaryschool', 'hsc', 'intermediate', '10plus2', '102' => '12th pass',
            'iti' => 'ITI', 'diploma' => 'Diploma', 'graduate', 'graduation', 'ba', 'bsc', 'bcom', 'btech', 'be', 'bca', 'bba', 'bachelorofarts', 'bachelorofscience', 'bachelorofcommerce' => 'Graduate', 'postgraduate', 'postgraduation', 'ma', 'msc', 'mcom', 'mtech', 'me', 'mba', 'mca', 'masterofarts', 'masterofscience', 'masterofcommerce' => 'Postgraduate',
            default => trim($value),
        };
    }

    public static function date(string $value, bool $date1904 = false): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{5}(?:\.0+)?$/', $value)) {
            $date = ($date1904 ? CarbonImmutable::create(1904, 1, 1) : CarbonImmutable::create(1899, 12, 30))->addDays((int) $value)->format('Y-m-d');
        } else {
            $date = null;
            if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
                if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                }
            } elseif (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
                if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                }
            } else {
                foreach (['j M Y', 'j F Y', 'j-M-Y', 'j-F-Y'] as $format) {
                    $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value);
                    $errors = \DateTimeImmutable::getLastErrors();
                    if ($parsed && (! $errors || (! $errors['warning_count'] && ! $errors['error_count']))) {
                        $date = $parsed->format('Y-m-d');
                        break;
                    }
                }
            }
        }
        if (! $date || $date < '1900-01-01' || $date > now()->toDateString()) {
            throw ValidationException::withMessages(['date_of_birth' => 'DOB must be a valid past date. Use day-month-year (08-11-2002), ISO year-month-day, or an Excel date.']);
        }

        return $date;
    }

    public static function address(string $value): array
    {
        $value = str_replace(["\xc2\xa0", "\r", "\n"], ' ', $value);
        $pattern = '/(Village\s*\/\s*City|City\s*\/\s*Village|Village|City|District|Distt?\.?|Block|Tehsil|Tehshil|Taluka|Muncipality|Municipality|Municipal\s*Council|Pin\s*code|Pincode)\s*[-:–]\s*/iu';
        preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE);
        $parts = [];
        foreach ($matches[0] as $i => [$marker, $offset]) {
            $start = $offset + strlen($marker);
            $end = $matches[0][$i + 1][1] ?? strlen($value);
            $text = trim(substr($value, $start, $end - $start), " \t,;|");
            $key = CandidateSpreadsheet::key($matches[1][$i][0]);
            $field = match ($key) {
                'villagecity', 'cityvillage', 'village', 'city' => 'city_village',
                'dist', 'distt' => 'district', 'tehshil', 'tehsil', 'taluka' => 'tehsil', 'muncipality', 'municipality', 'municipalcouncil' => 'municipality',
                'pincode' => 'pincode', default => $key,
            };
            if ($text !== '') {
                $parts[$field] = $text;
            }
        }
        if (empty($parts['pincode']) && preg_match('/(?<![0-9])([1-9][0-9]{5})(?![0-9])/', $value, $pin)) {
            $parts['pincode'] = $pin[1];
        }

        return $parts;
    }

    public function normalize(array $data, bool $date1904 = false): array
    {
        $this->options ??= DB::table('master_options')->where('is_active', true)->get()->all();
        $this->states ??= DB::table('states')->where('is_active', true)->get()->all();
        $this->districts ??= DB::table('districts')->where('is_active', true)->get()->all();
        if (isset($data['gender'])) {
            $data['gender'] = match (CandidateSpreadsheet::key($data['gender'])) {
                'm', 'male' => 'Male', 'f', 'female' => 'Female', 'other', 'others' => 'Other', default => trim($data['gender']),
            };
        }
        if (isset($data['experience_type'])) {
            $data['experience_type'] = match (CandidateSpreadsheet::key($data['experience_type'])) {
                'fresh', 'fresher', '0', '0years', 'noexperience' => 'Fresher', 'experienced' => 'Experienced', default => trim($data['experience_type']),
            };
        }
        if (isset($data['qualification'])) {
            $data['qualification'] = self::qualification($data['qualification']);
        }
        foreach (['qualification' => ['qualification', 'qualification_option_id'], 'preferred_industry' => ['industry', 'industry_option_id'], 'experience_type' => ['experience_level', 'experience_level_option_id']] as $field => [$type, $id]) {
            if (! isset($data[$field])) {
                continue;
            }
            $match = collect($this->options)->first(fn ($o) => $o->type === $type && CandidateSpreadsheet::key($o->name) === CandidateSpreadsheet::key($data[$field]));
            $data[$id] = $match?->id;
            if ($match) {
                $data[$field] = $match->name;
            }
        }
        if (array_key_exists('date_of_birth', $data)) {
            $data['date_of_birth'] = self::date((string) $data['date_of_birth'], $date1904);
            if ($data['date_of_birth']) {
                $data['age'] = (int) CarbonImmutable::parse($data['date_of_birth'])->diffInYears(CarbonImmutable::today());
            }
        }
        foreach (['permanent' => 'permanent_location', 'communication' => 'current_location'] as $prefix => $field) {
            if (! isset($data[$field])) {
                continue;
            }
            foreach (self::address($data[$field]) as $part => $value) {
                if (empty($data[$prefix.'_'.$part])) {
                    $data[$prefix.'_'.$part] = $value;
                }
            }
        }
        if (empty($data['district'])) {
            $data['district'] = $data['permanent_district'] ?? $data['communication_district'] ?? '';
        }
        $state = collect($this->states)->first(fn ($s) => CandidateSpreadsheet::key($s->name) === CandidateSpreadsheet::key($data['state'] ?? ''));
        $districtKey = fn ($name) => preg_replace('/^(shri|sri)/', '', CandidateSpreadsheet::key($name));
        $districts = collect($this->districts)->filter(fn ($d) => $districtKey($d->name) === $districtKey($data['district']) && ($state ? $d->state_id == $state->id : empty($data['state'])));
        if ($districts->count() === 1) {
            $district = $districts->first();
            $state = collect($this->states)->firstWhere('id', $district->state_id);
            $data['district'] = $district->name;
            $data['district_id'] = $district->id;
            $data['district_is_custom'] = false;
        }
        if ($state) {
            $data['state'] = $state->name;
            $data['state_id'] = $state->id;
        }
        foreach (['permanent_district', 'communication_district'] as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $matches = collect($this->districts)->filter(fn ($d) => $districtKey($d->name) === $districtKey($data[$field]) && (! $state || $d->state_id == $state->id));
            if ($matches->count() === 1) {
                $data[$field] = $matches->first()->name;
            }
        }
        if (isset($data['training_status'])) {
            $data['training_status'] = match (CandidateSpreadsheet::key($data['training_status'])) {
                'trained', 'completed', 'complete' => 'Completed', 'ongoing', 'intraining' => 'Ongoing', 'dropout', 'droppedout' => 'Dropout', 'notenrolled' => 'Not enrolled', default => trim($data['training_status']),
            };
        }

        return $data;
    }
}
