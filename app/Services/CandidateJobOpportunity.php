<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CandidateJobOpportunity
{
    public const FIELDS = ['job_role' => 'Job role', 'job_location' => 'Job location', 'job_salary' => 'Job monthly salary', 'job_url' => 'Opportunities link'];

    public static function value(array $job, string $field): string
    {
        return (string) ($job[match ($field) {
            'job_role' => 'role', 'job_location' => 'location', 'job_salary' => 'salary', 'job_url' => 'url'
        }] ?? '');
    }

    public function query(bool $live = true)
    {
        return DB::table('job_posts as j')->join('employers as e', 'e.id', '=', 'j.employer_id')
            ->whereNull('j.deleted_at')->whereNull('e.deleted_at')->where('e.status', 'verified')
            ->when($live, fn ($q) => $q->whereIn('j.status', ['active', 'published'])->where(fn ($q) => $q->whereNull('j.application_deadline')->orWhere('j.application_deadline', '>=', now('Asia/Kolkata')->toDateString())))
            ->select(['j.id', 'j.employer_id', 'j.title', 'j.district', 'j.state', 'j.salary_min', 'j.salary_max', 'j.status', 'j.application_deadline', 'e.name as employer_name']);
    }

    public function snapshot(object $job): array
    {
        $role = str_ireplace($job->employer_name, '', $job->title);
        $role = preg_replace('/https?:\/\/\S+|[\w.+-]+@[\w.-]+|\+?[\d\s()-]{8,}/', '', $role);
        $role = trim($role, " \t\n\r\0\x0B-|,:;") ?: 'Job';
        $salary = $job->salary_min || $job->salary_max ? 'INR '.number_format($job->salary_min ?: $job->salary_max).($job->salary_max && $job->salary_min && $job->salary_max !== $job->salary_min ? ' - '.number_format($job->salary_max) : '') : 'To be discussed';

        return ['id' => $job->id, 'role' => preg_replace('/[\r\n\t]+/', ' ', $role), 'location' => $job->district ?: $job->state ?: 'See your account', 'salary' => $salary, 'url' => rtrim(config('app.url'), '/').'/candidate/opportunities'];
    }

    public function find(int $id, bool $live = true): ?array
    {
        $job = $this->query($live)->where('j.id', $id)->first();

        return $job ? $this->snapshot($job) : null;
    }
}
