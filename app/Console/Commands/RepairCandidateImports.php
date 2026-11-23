<?php

namespace App\Console\Commands;

use App\Http\Controllers\AdminCandidateImportController;
use App\Models\Candidate;
use App\Services\CandidateImportNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RepairCandidateImports extends Command
{
    protected $signature = 'candidates:repair-imports {--apply : Apply corrections after creating an encrypted backup}';

    protected $description = 'Normalize existing admin imports from their preserved original source data';

    public function handle(CandidateImportNormalizer $normalizer): int
    {
        $plans = [];
        $failed = [];
        foreach (Candidate::where('registration_source', 'admin_import')->whereNull('deleted_at')->lazyById(200) as $candidate) {
            $source = $candidate->import_data['values'] ?? [];
            if (! $source) {
                continue;
            }
            $mapping = AdminCandidateImportController::mapping(array_keys($source));
            $data = [];
            $fields = ['qualification', 'date_of_birth', 'father_name', 'mother_name', 'training_course', 'employment_type', 'current_location', 'permanent_location', 'training_status', 'preferred_industry'];
            foreach ($mapping as $header => $field) {
                if (in_array($field, $fields, true)) {
                    $data[$field] = trim($source[$header]);
                }
            }
            // Keep the candidate's current home district/state and deliberate profile edits.
            $data['district'] = $candidate->district;
            $data['state'] = $candidate->state;
            try {
                $data = $normalizer->normalize($data, (bool) ($candidate->import_data['excel_date_1904'] ?? false));
            } catch (ValidationException $e) {
                $failed[] = $candidate->id;

                continue;
            }
            if (! empty($data['training_course']) && $candidate->skills === $data['training_course']) {
                $data['skills'] = '';
            }
            $changes = [];
            $before = [];
            foreach ($data as $field => $value) {
                $old = $candidate->$field;
                if ($old instanceof \DateTimeInterface) {
                    $old = $old->format('Y-m-d');
                }
                if (is_bool($value) ? (bool) $old !== $value : (string) $old !== (string) $value) {
                    $changes[$field] = $value;
                    $before[$field] = $old;
                }
            }
            if ($changes) {
                $plans[] = ['id' => $candidate->id, 'actor' => $candidate->created_by_user_id, 'before' => $before, 'after' => $changes, 'updated_at' => (string) $candidate->updated_at];
            }
        }
        $this->info(count($plans).' imported profiles need corrections; '.count($failed).' profiles have invalid source dates.');
        if ($failed) {
            $this->error('Resolve source dates for candidate IDs: '.implode(', ', $failed).'. No repairs applied.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Dry run only. Use --apply to back up and repair these profiles.');

            return self::SUCCESS;
        }
        if (! $plans) {
            return self::SUCCESS;
        }
        $backup = 'candidate-import-repairs/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(5)).'.encrypted';
        Storage::put($backup, encrypt(json_encode($plans, JSON_THROW_ON_ERROR)));
        DB::transaction(function () use ($plans) {
            foreach ($plans as $plan) {
                $candidate = Candidate::whereKey($plan['id'])->lockForUpdate()->firstOrFail();
                if ((string) $candidate->updated_at !== $plan['updated_at']) {
                    throw new \RuntimeException('A candidate changed during repair. No repairs applied; run again.');
                }
                $candidate->update($plan['after']);
                DB::table('audit_logs')->insert(['actor_user_id' => $plan['actor'], 'action' => 'admin.candidate_import_repaired', 'subject_type' => 'candidate', 'subject_id' => $candidate->id, 'changes' => json_encode(['fields' => array_keys($plan['after'])]), 'created_at' => now()]);
            }
        });
        $this->info(count($plans).' profiles repaired. Encrypted backup: '.$backup);

        return self::SUCCESS;
    }
}
