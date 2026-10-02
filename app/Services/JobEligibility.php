<?php

namespace App\Services;

use App\Models\Candidate;

class JobEligibility
{
    // Free-text requirements are matched conservatively; expose the rule to employers.
    public function matches(object $job, Candidate $candidate): bool
    {
        $normal = fn ($value) => mb_strtolower(trim((string) $value));
        $education = $normal($job->education);
        $qualification = $normal($candidate->qualification);
        if ($education && $education !== 'any' && (!$qualification || !str_contains($education, $qualification))) return false;
        $experience = $normal($job->experience);
        if ($experience && !str_contains($experience, $normal($candidate->experience_type))) return false;
        $skills = array_filter(array_map($normal, preg_split('/[,;\n]+/', (string) $job->skills)));
        foreach ($skills as $skill) {
            if (!str_contains($normal($candidate->skills), $skill)) return false;
        }
        if ($job->workplace_type !== 'Remote' && $normal($job->district) !== $normal($candidate->district)
            && !str_starts_with($normal($candidate->relocation_preference), 'yes')
            && !in_array($normal($job->district), array_map($normal, $candidate->preferred_locations ?? []))) return false;
        return !$job->salary_max || $candidate->expected_monthly_salary <= $job->salary_max;
    }
}
