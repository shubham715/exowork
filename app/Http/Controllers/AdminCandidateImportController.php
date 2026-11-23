<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\CandidateImportNormalizer;
use App\Services\CandidateSpreadsheet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCandidateImportController extends Controller
{
    public const FIELDS = ['first_name', 'last_name', 'full_name', 'whatsapp', 'alternate_mobile', 'email', 'gender', 'age', 'qualification', 'state', 'district', 'current_location', 'permanent_location', 'preferred_locations', 'relocation_preference', 'skills', 'experience_type', 'experience_details', 'expected_monthly_salary', 'availability', 'preferred_industry', 'languages', 'training_status', 'training_partner', 'training_center', 'batch_code', 'batch_end_date', 'aadhaar_number', 'date_of_birth', 'father_name', 'mother_name', 'training_course', 'employment_type', 'permanent_city_village', 'permanent_district', 'permanent_block', 'permanent_tehsil', 'permanent_municipality', 'permanent_pincode', 'communication_city_village', 'communication_district', 'communication_block', 'communication_tehsil', 'communication_municipality', 'communication_pincode'];

    private function authorize(Request $request): void
    {
        app(WhatsAppController::class)->admin($request);
        abort_unless($request->user()->hasPermission('candidates.create'), 403);
    }

    public static function mapping(array $headers): array
    {
        $aliases = ['aspirantname' => 'full_name', 'studentname' => 'full_name', 'mobileno' => 'whatsapp', 'emailid' => 'email', 'hometown' => 'district', 'homedistrict' => 'district', 'sdcname' => 'training_center', 'sectors' => 'preferred_industry', 'courses' => 'training_course', 'permanentaddress' => 'permanent_location', 'communicationaddress' => 'current_location', 'aadharno' => 'aadhaar_number', 'dob' => 'date_of_birth', 'dateofbirth' => 'date_of_birth', 'fathername' => 'father_name', 'mothername' => 'mother_name', 'employmenttype' => 'employment_type', 'cityvillage' => 'permanent_city_village', 'villagecity' => 'permanent_city_village', 'block' => 'permanent_block', 'tehsil' => 'permanent_tehsil', 'municipality' => 'permanent_municipality', 'muncipality' => 'permanent_municipality', 'pincode' => 'permanent_pincode'];
        foreach ([
            'full_name' => ['candidate name', 'candidate full name', 'student full name', 'name', 'name of candidate', 'name of student'],
            'first_name' => ['given name', 'forename'], 'last_name' => ['surname', 'family name'],
            'whatsapp' => ['mobile', 'mobile number', 'mobile no', 'phone', 'phone number', 'contact number', 'contact no', 'whatsapp number', 'whatsapp no'],
            'alternate_mobile' => ['alternate phone', 'alternate number', 'secondary mobile'],
            'email' => ['email address', 'e-mail id', 'e-mail'],
            'date_of_birth' => ['birth date', 'birthdate', 'birth date dd mm yyyy', 'dob dd mm yyyy', 'date of birth dd mm yyyy'],
            'qualification' => ['education', 'educational qualification', 'highest qualification', 'highest education', 'education level', 'qualification level'],
            'father_name' => ["father's name", 'fathers name'], 'mother_name' => ["mother's name", 'mothers name'],
            'permanent_location' => ['home address', 'residential address', 'permanent full address'],
            'current_location' => ['current address', 'present address', 'mailing address', 'correspondence address', 'communication full address'],
            'permanent_city_village' => ['city', 'village', 'town', 'permanent city', 'permanent village'],
            'communication_city_village' => ['current city', 'present city', 'current village', 'communication city'],
            'district' => ['home dist', 'home distt', 'dist', 'distt'], 'permanent_pincode' => ['pin code', 'postal code', 'zip code', 'permanent pin code'],
            'communication_pincode' => ['current pin code', 'communication pin code'],
            'training_course' => ['course', 'course name', 'training course name'], 'preferred_industry' => ['sector', 'industry', 'industry preference'],
            'skills' => ['skill', 'skill set', 'technical skills'], 'experience_type' => ['experience level', 'experience category'],
        ] as $field => $names) {
            foreach ($names as $name) {
                $aliases[CandidateSpreadsheet::key($name)] = $field;
            }
        }
        foreach (self::FIELDS as $field) {
            $aliases[CandidateSpreadsheet::key($field)] = $field;
        }
        $mapping = [];
        $used = [];
        foreach ($headers as $header) {
            $field = $aliases[CandidateSpreadsheet::key($header)] ?? '';
            $mapping[$header] = isset($used[$field]) ? '' : $field;
            if ($field) {
                $used[$field] = true;
            }
        }

        return $mapping;
    }

    public function upload(Request $request, CandidateSpreadsheet $reader)
    {
        $this->authorize($request);
        $request->validate(['file' => ['required', 'file', 'max:10240'], 'sheet' => ['nullable', 'string', 'max:255']]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $table = $reader->read($file->getRealPath(), $extension, $request->input('sheet'));
        foreach (Storage::files('candidate-imports') as $stagedFile) {
            if (Storage::lastModified($stagedFile) < now()->subMinutes(30)->timestamp) {
                Storage::delete($stagedFile);
            }
        }
        $token = bin2hex(random_bytes(24));
        $path = 'candidate-imports/'.$token.'.json';
        Storage::put($path, encrypt(json_encode($table, JSON_THROW_ON_ERROR)));
        $old = $request->session()->get('admin_candidate_import');
        if ($old) {
            Storage::delete($old['path']);
        }
        $request->session()->put('admin_candidate_import', ['token' => $token, 'path' => $path, 'user_id' => $request->user()->id, 'source' => mb_substr($file->getClientOriginalName().' / '.$table['sheet'], 0, 255), 'expires' => now()->addMinutes(30)->timestamp]);

        return response()->json(['token' => $token, 'headers' => $table['headers'], 'sheets' => $table['sheets'], 'sheet' => $table['sheet'], 'total' => count($table['records']), 'mapping' => self::mapping($table['headers']), 'fields' => self::FIELDS]);
    }

    private function staged(Request $request): array
    {
        $this->authorize($request);
        $stage = $request->session()->get('admin_candidate_import');
        abort_unless($stage && $stage['user_id'] === $request->user()->id && $stage['expires'] >= now()->timestamp && hash_equals($stage['token'], (string) $request->input('token')) && Storage::exists($stage['path']), 422, 'Upload the file again; this import has expired or was already saved.');
        $table = json_decode(decrypt(Storage::get($stage['path'])), true, 512, JSON_THROW_ON_ERROR);
        $request->validate(['mapping' => ['required', 'array'], 'mapping.*' => ['nullable', Rule::in(self::FIELDS)]]);
        $mapping = $request->input('mapping');
        abort_if(array_diff(array_keys($mapping), $table['headers']), 422, 'Unknown source column.');
        $targets = array_values(array_filter($mapping));
        abort_unless(count($targets) === count(array_unique($targets)), 422, 'Map each system field only once.');
        abort_unless(in_array('first_name', $targets) || in_array('full_name', $targets), 422, 'Map a candidate name.');
        abort_unless(in_array('whatsapp', $targets), 422, 'Map the mobile number column.');

        return [$stage, $table, $mapping];
    }

    private function prepare(array $table, array $mapping): array
    {
        $rows = [];
        $report = [];
        $seen = [];
        $normalizer = new CandidateImportNormalizer;
        foreach ($table['records'] as $record) {
            $data = [];
            foreach ($mapping as $header => $field) {
                if ($field) {
                    $data[$field] = trim($record['values'][$header] ?? '');
                }
            }
            $normalizationErrors = [];
            try {
                $data = $normalizer->normalize($data, (bool) ($record['excel_date_1904'] ?? false));
            } catch (ValidationException $e) {
                $normalizationErrors = array_merge(...array_values($e->errors()));
            }
            if (! empty($data['full_name'])) {
                $parts = preg_split('/\s+/', $data['full_name'], 2);
                $data['first_name'] = $data['first_name'] ?? $parts[0];
                $data['last_name'] = $data['last_name'] ?? ($parts[1] ?? '');
            }
            unset($data['full_name']);
            $phone = preg_replace('/[\s()\-]/', '', $data['whatsapp'] ?? '');
            if (preg_match('/^(?:\+91|91)(\d{10})$/', $phone, $m)) {
                $phone = $m[1];
            }
            $data['whatsapp'] = $phone;
            $data['email'] = empty($data['email']) ? null : strtolower($data['email']);
            foreach (['preferred_locations', 'languages'] as $field) {
                $data[$field] = array_values(array_filter(array_map('trim', explode(';', $data[$field] ?? ''))));
            }
            foreach (['gender', 'training_status', 'availability'] as $field) {
                $options = match ($field) {
                    'gender' => ['Male', 'Female', 'Other'], 'training_status' => ['Ongoing', 'Completed', 'Not enrolled'], default => ['Yes', 'No', 'Available after training']
                };
                foreach ($options as $option) {
                    if (strcasecmp($data[$field] ?? '', $option) === 0) {
                        $data[$field] = $option;
                    }
                }
            }
            // Missing historical information stays unrecorded; consent and login credentials are never imported.
            $data += ['last_name' => '', 'gender' => '', 'qualification' => '', 'state' => '', 'district' => '', 'current_location' => '', 'permanent_location' => '', 'relocation_preference' => '', 'skills' => '', 'experience_type' => '', 'preferred_industry' => '', 'training_status' => '', 'availability' => 'No'];
            foreach (['age', 'expected_monthly_salary', 'batch_end_date'] as $field) {
                if (($data[$field] ?? '') === '') {
                    $data[$field] = null;
                }
            }
            $rules = ['first_name' => 'required|string|max:80', 'last_name' => 'present|string|max:80', 'whatsapp' => ['required', 'regex:/^[6-9][0-9]{9}$/', Rule::unique('candidates', 'whatsapp')], 'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('candidates', 'email')], 'age' => 'nullable|integer|between:0,120', 'expected_monthly_salary' => 'nullable|integer|between:0,10000000', 'batch_end_date' => 'nullable|date_format:Y-m-d'];
            foreach (['gender' => 24, 'qualification' => 60, 'state' => 80, 'district' => 80, 'current_location' => 20000, 'permanent_location' => 20000, 'relocation_preference' => 40, 'experience_type' => 20, 'availability' => 30, 'preferred_industry' => 60, 'training_status' => 20, 'training_center' => 255, 'training_partner' => 255, 'batch_code' => 255, 'alternate_mobile' => 15, 'aadhaar_number' => 30, 'skills' => 20000, 'experience_details' => 20000] as $field => $max) {
                $rules[$field] = 'nullable|string|max:'.$max;
            }
            $rules['date_of_birth'] = 'nullable|date_format:Y-m-d|before_or_equal:today';
            foreach (array_diff(self::FIELDS, array_keys($rules), ['full_name', 'preferred_locations', 'languages']) as $field) {
                $rules[$field] = 'nullable|string|max:255';
            }
            foreach (['permanent_pincode', 'communication_pincode'] as $field) {
                $rules[$field] = ['nullable', 'regex:/^[1-9][0-9]{5}$/'];
            }
            $validator = Validator::make($data, $rules);
            $errors = array_merge($normalizationErrors, $validator->errors()->all());
            foreach (['whatsapp', 'email'] as $field) {
                $value = $data[$field];
                if ($value && isset($seen[$field][$value])) {
                    $errors[] = "Duplicate $field in this file (first seen on row {$seen[$field][$value]}).";
                }
                if ($value) {
                    $seen[$field][$value] = $record['row'];
                }
            }
            $warnings = [];
            if (! empty($data['qualification']) && empty($data['qualification_option_id'])) {
                $warnings[] = 'Education is not in the master list; original wording is retained.';
            }
            foreach (['permanent' => 'permanent_location', 'communication' => 'current_location'] as $prefix => $field) {
                if (! empty($data[$field]) && empty($data[$prefix.'_city_village'])) {
                    $warnings[] = ucfirst($prefix).' address is unstructured; full address is kept. Review city/village separately.';
                }
            }
            $report[] = ['row' => $record['row'], 'name' => trim($data['first_name'].' '.$data['last_name']), 'errors' => $errors, 'warnings' => $warnings, 'normalized' => array_intersect_key($data, array_flip(['qualification', 'date_of_birth', 'district', 'state', 'training_course']))];
            $rows[] = ['data' => $data, 'source' => $record];
        }

        return [$rows, $report];
    }

    public function preview(Request $request)
    {
        [$stage, $table, $mapping] = $this->staged($request);
        [, $report] = $this->prepare($table, $mapping);
        $invalid = count(array_filter($report, fn ($row) => count($row['errors'])));
        $request->session()->put('admin_candidate_import.mapping_hash', hash('sha256', json_encode($mapping)));
        $request->session()->put('admin_candidate_import.valid_rows', array_values(array_column(array_filter($report, fn ($row) => ! count($row['errors'])), 'row')));

        return response()->json(['rows' => $report, 'total' => count($report), 'invalid' => $invalid, 'add' => count($report) - $invalid]);
    }

    public function confirm(Request $request)
    {
        [$stage, $table, $mapping] = $this->staged($request);
        $request->validate(['skip_invalid' => ['sometimes', 'boolean']]);
        abort_unless(hash_equals($stage['mapping_hash'] ?? '', hash('sha256', json_encode($mapping))), 422, 'Preview these mappings before saving.');
        $imported = 0;
        $skipped = 0;
        try {
            DB::transaction(function () use ($table, $mapping, $stage, $request, &$imported, &$skipped) {
                // Serialize confirmations for this uploader, then revalidate duplicates inside the transaction.
                DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
                [$rows, $report] = $this->prepare($table, $mapping);
                $validRows = array_values(array_column(array_filter($report, fn ($row) => ! count($row['errors'])), 'row'));
                abort_unless($validRows === ($stage['valid_rows'] ?? []), 422, 'Candidate validation has changed. Preview again before importing.');
                $skipped = count($report) - count($validRows);
                abort_if($skipped && ! $request->boolean('skip_invalid'), 422, 'No candidates saved. Choose to skip invalid rows or correct them and preview again.');
                abort_unless(count($validRows), 422, 'No valid candidates to import. Correct your file and preview again.');
                foreach ($rows as $index => $row) {
                    if (count($report[$index]['errors'])) {
                        continue;
                    }
                    $candidate = Candidate::create($row['data'] + ['created_by_user_id' => $request->user()->id, 'registration_source' => 'admin_import', 'import_source' => $stage['source'], 'import_data' => $row['source']]);
                    $candidate->forceFill(['candidate_code' => 'EXO-CAN-'.str_pad((string) $candidate->id, 6, '0', STR_PAD_LEFT)])->save();
                    DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'admin.candidate_imported', 'subject_type' => 'candidate', 'subject_id' => $candidate->id, 'changes' => json_encode(['source' => $stage['source'], 'row' => $row['source']['row']]), 'ip_address' => $request->ip(), 'created_at' => now()]);
                    $imported++;
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            CandidateSpreadsheet::fail('No candidates saved. A phone number or email was just registered. Preview again to check duplicates.');
        }
        Storage::delete($stage['path']);
        $request->session()->forget('admin_candidate_import');

        return response()->json(['message' => $imported.' candidates imported successfully.'.($skipped ? ' '.$skipped.' invalid rows skipped.' : ''), 'imported' => $imported, 'skipped' => $skipped]);
    }

    public function sample(Request $request)
    {
        $this->authorize($request);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(array_diff(self::FIELDS, ['full_name', 'aadhaar_number'])));
            fclose($out);
        }, 'candidate-import-sample.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
