<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\JobEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobEngagementController extends Controller
{
    private function owned(Request $request, int $id): object {
        $employer = app(EmployerWorkspaceController::class)->employer($request);
        $job = DB::table('job_posts')->where('employer_id', $employer->id)->whereNull('deleted_at')->find($id);
        abort_unless($job, 404);
        return $job;
    }
    public function stats(Request $request, int $id) {
        $this->owned($request, $id);
        $data = $request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]]);
        $start = now('Asia/Kolkata')->startOfDay()->subDays(($data['days'] ?? 30) - 1);
        $counts = DB::table('job_engagement_events')->where('job_post_id', $id)->where('event_date', '>=', $start->toDateString())
            ->selectRaw("event_date, SUM(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) as impressions, SUM(CASE WHEN type = 'click' THEN 1 ELSE 0 END) as clicks")
            ->groupBy('event_date')->get()->keyBy('event_date');
        $days = [];
        for ($date = $start->copy(); $date->lte(now('Asia/Kolkata')); $date->addDay()) {
            $row = $counts->get($date->toDateString());
            $days[] = ['date' => $date->toDateString(), 'impressions' => (int) ($row->impressions ?? 0), 'clicks' => (int) ($row->clicks ?? 0)];
        }
        return response()->json(['days' => $days]);
    }
    public function candidates(Request $request, int $id, JobEligibility $eligibility) {
        $job = $this->owned($request, $id);
        abort_unless(app(EmployerWorkspaceController::class)->employer($request)->status === 'verified', 403, 'Company verification is required to view eligible candidates.');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'sort' => ['nullable', Rule::in(['newest', 'salary', 'name'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $matches = Candidate::whereNull('deleted_at')->where('profile_status', 'active')
            ->whereNotNull('terms_accepted_at')->whereNotNull('privacy_accepted_at')
            ->whereNotIn('id', DB::table('job_candidate_skips')->where('job_post_id', $id)->select('candidate_id'))
            ->orderByDesc('id')->get()->filter(fn ($candidate) => $eligibility->matches($job, $candidate))->values();
        if (!empty($filters['search'])) {
            $needle = mb_strtolower(trim($filters['search']));
            $matches = $matches->filter(fn ($c) => str_contains(mb_strtolower($c->first_name.' '.$c->last_name.' '.$c->skills), $needle))->values();
        }
        if (($filters['sort'] ?? '') === 'salary') $matches = $matches->sortBy('expected_monthly_salary')->values();
        if (($filters['sort'] ?? '') === 'name') $matches = $matches->sortBy(fn ($c) => mb_strtolower($c->first_name.' '.$c->last_name))->values();
        $page = max(1, (int) $request->input('page', 1));
        return response()->json(['job' => $job, 'total' => $matches->count(), 'page' => $page, 'per_page' => 20,
            'candidates' => $matches->slice(($page - 1) * 20, 20)->map(fn ($c) => $c->only(['id', 'candidate_code', 'first_name', 'last_name', 'qualification', 'district', 'state', 'skills', 'experience_type', 'experience_details', 'expected_monthly_salary', 'availability', 'whatsapp_consent']))->values()]);
    }
    public function skip(Request $request, int $id, int $candidate, JobEligibility $eligibility) {
        $job = $this->owned($request, $id);
        abort_unless(app(EmployerWorkspaceController::class)->employer($request)->status === 'verified', 403);
        $profile = Candidate::whereNull('deleted_at')->where('profile_status', 'active')->find($candidate);
        abort_unless($profile && $profile->terms_accepted_at && $profile->privacy_accepted_at && $eligibility->matches($job, $profile), 404);
        DB::table('job_candidate_skips')->insertOrIgnore(['job_post_id' => $id, 'candidate_id' => $candidate, 'created_at' => now()]);
        return response()->json(['skipped' => true]);
    }
    public function opportunities(Request $request) {
        return response()->json(['jobs' => DB::table('job_posts as j')->join('employers as e', 'e.id', '=', 'j.employer_id')
            ->whereIn('j.status', ['active', 'published'])->where('e.status', 'verified')->whereNull('j.deleted_at')->whereNull('e.deleted_at')
            ->where(fn ($q) => $q->whereNull('j.application_deadline')->orWhere('j.application_deadline', '>=', now('Asia/Kolkata')->toDateString()))
            ->orderByDesc('j.id')->get(['j.id', 'j.title', 'j.description', 'j.district', 'j.state', 'j.salary_min', 'j.salary_max', 'j.job_type', 'j.openings', 'j.education', 'j.skills', 'j.experience'])]);
    }
    public function record(Request $request, int $id) {
        $data = $request->validate(['event_id' => ['required', 'uuid'], 'type' => ['required', Rule::in(['impression', 'click'])]]);
        abort_unless(DB::table('job_posts as j')->join('employers as e', 'e.id', '=', 'j.employer_id')->where('j.id', $id)->whereIn('j.status', ['active', 'published'])->where('e.status', 'verified')->whereNull('j.deleted_at')->whereNull('e.deleted_at')->where(fn ($q) => $q->whereNull('j.application_deadline')->orWhere('j.application_deadline', '>=', now('Asia/Kolkata')->toDateString()))->exists(), 404);
        DB::table('job_engagement_events')->insertOrIgnore(['job_post_id' => $id, 'candidate_id' => $request->user('candidate')->id, 'event_id' => $data['event_id'], 'type' => $data['type'], 'event_date' => now('Asia/Kolkata')->toDateString(), 'created_at' => now()]);
        return response()->json(['recorded' => true]);
    }
}
