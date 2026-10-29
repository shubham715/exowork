<?php

namespace App\Http\Controllers;

use App\Services\JobEligibility;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CandidateDashboardController extends Controller
{
    public function show(Request $request, JobEligibility $eligibility)
    {
        $candidate = $request->user('candidate');
        abort_unless($candidate && $candidate->profile_status === 'active' && ! $candidate->deleted_at, 403);

        $applications = DB::table('applications as a')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')
            ->where('a.candidate_id', $candidate->id);
        $interviews = DB::table('interviews as i')->join('applications as a', 'a.id', '=', 'i.application_id')
            ->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->join('employers as e', 'e.id', '=', 'j.employer_id')
            ->where('a.candidate_id', $candidate->id)->whereIn('i.status', ['scheduled', 'confirmed'])->where('i.scheduled_at', '>', now());
        $placements = DB::table('placement_outcomes as p')->join('applications as a', 'a.id', '=', 'p.application_id')
            ->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->where('a.candidate_id', $candidate->id);

        $checks = [
            ['label' => 'Personal details', 'complete' => filled($candidate->first_name) && filled($candidate->last_name) && filled($candidate->gender) && filled($candidate->age)],
            ['label' => 'Location & preferences', 'complete' => filled($candidate->current_location) && filled($candidate->preferred_locations) && filled($candidate->relocation_preference)],
            ['label' => 'Skills & qualification', 'complete' => filled($candidate->skills) && filled($candidate->qualification) && filled($candidate->experience_type)],
            ['label' => 'Job preferences', 'complete' => filled($candidate->availability) && filled($candidate->preferred_industry) && $candidate->expected_monthly_salary !== null],
            ['label' => 'Languages', 'complete' => filled($candidate->languages)],
        ];
        $jobs = [];
        // Stop at three eligible jobs; cursor avoids loading the entire job pool into memory.
        $pool = DB::table('job_posts as j')->join('employers as e', 'e.id', '=', 'j.employer_id')
            ->whereIn('j.status', ['active', 'published'])->where('e.status', 'verified')->whereNull('j.deleted_at')->whereNull('e.deleted_at')
            ->where(fn ($q) => $q->whereNull('j.application_deadline')->orWhere('j.application_deadline', '>=', now('Asia/Kolkata')->toDateString()))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('applications as a')->whereColumn('a.job_post_id', 'j.id')->where('a.candidate_id', $candidate->id))
            ->select('j.*')->orderByDesc('j.id');
        foreach ($pool->cursor() as $job) {
            if ($eligibility->matches($job, $candidate)) {
                $jobs[] = collect((array) $job)->only(['id', 'title', 'district', 'state', 'salary_min', 'salary_max', 'salary_type', 'job_type', 'application_deadline'])->all();
                if (count($jobs) === 3) break;
            }
        }
        $due = 0;
        foreach ((clone $placements)->where('p.status', 'joined')->whereNotNull('p.joined_on')->select('p.id', 'p.joined_on')->get() as $placement) {
            $answered = DB::table('retention_responses')->where('placement_outcome_id', $placement->id)->pluck('day')->all();
            foreach ([3, 7, 15, 30, 90] as $day) {
                if (! in_array($day, $answered) && Carbon::parse($placement->joined_on, 'Asia/Kolkata')->addDays($day)->lte(now('Asia/Kolkata'))) $due++;
            }
        }
        $next = (clone $interviews)->orderBy('i.scheduled_at')->select('i.id', 'i.scheduled_at', 'i.mode', 'i.location', 'i.status', 'i.attendance_response', 'j.title', 'e.name as employer_name')->first();
        if ($next) $next->scheduled_at = Carbon::parse($next->scheduled_at, 'UTC')->toIso8601String();
        $center = $candidate->training_center_id ? DB::table('training_centers')->where('id', $candidate->training_center_id)->whereNull('deleted_at')->value('name') : null;

        return response()->json([
            'candidate' => $candidate->only(['first_name', 'last_name', 'candidate_code', 'availability', 'job_interest_status', 'batch_code', 'batch_end_date', 'training_status']),
            'training_center' => $center,
            'profile' => ['checks' => $checks, 'completion' => (int) round(collect($checks)->where('complete', true)->count() / count($checks) * 100),
                'resume_available' => filled($candidate->resume_path) && Storage::exists($candidate->resume_path),
                'communication_channels' => collect(['call', 'sms', 'whatsapp', 'email'])->filter(fn ($channel) => $candidate->{$channel.'_consent'})->values()],
            'summary' => ['applications' => (clone $applications)->count(), 'upcoming_interviews' => (clone $interviews)->count(),
                'joining_pending' => (clone $placements)->whereIn('p.status', ['selected', 'joining_pending'])->count(),
                'joined' => (clone $placements)->where('p.status', 'joined')->count(), 'check_ins_due' => $due],
            'next_interview' => $next,
            'recent_applications' => (clone $applications)->orderByDesc('a.id')->limit(5)->get(['a.id', 'a.status', 'a.submitted_at', 'a.interview_availability', 'j.title', 'j.district']),
            'placements' => (clone $placements)->orderByDesc('p.updated_at')->limit(3)->get(['p.id', 'p.status', 'p.expected_joining_on', 'p.joined_on', 'j.title']),
            'jobs' => $jobs,
            'updated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
