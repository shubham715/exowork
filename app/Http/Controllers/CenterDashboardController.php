<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CenterDashboardController extends Controller
{
    public function identity(Request $request): JsonResponse
    {
        $center = DB::table('organization_memberships as m')
            ->join('training_centers as c', 'c.id', '=', 'm.training_center_id')
            ->leftJoin('training_partners as p', 'p.id', '=', 'c.training_partner_id')
            ->where('m.user_id', $request->user()->id)->where('m.status', 'active')
            ->whereNull('c.deleted_at')
            ->select('c.name', 'c.code', 'c.status', DB::raw('coalesce(p.name, c.name) as org_name'))->first();
        abort_unless($center, 403, 'No active training center membership.');
        $words = preg_split('/\s+/', trim($center->name));
        $center->initials = strtoupper(implode('', array_map(fn ($word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
        return response()->json(['center' => $center]);
    }

    public function show(Request $request): JsonResponse
    {
        $membership = DB::table('organization_memberships as m')
            ->join('training_centers as c', 'c.id', '=', 'm.training_center_id')
            ->leftJoin('training_partners as p', 'p.id', '=', 'c.training_partner_id')
            ->where('m.user_id', $request->user()->id)
            ->where('m.status', 'active')
            ->whereNull('c.deleted_at')
            ->select('c.id', 'c.name', 'c.code', 'c.status', 'c.placement_commitment_percent', 'c.training_partner_id', 'p.name as partner_name')
            ->first();

        abort_unless($membership, 403, 'No active training center membership.');

        $candidates = DB::table('candidates')->where('training_center_id', $membership->id)->whereNull('deleted_at');
        $now = now();
        $tomorrowStart = now('Asia/Kolkata')->startOfDay()->addDay()->utc();
        $tomorrowEnd = $tomorrowStart->copy()->addDay()->subSecond();

        $interviews = DB::table('interviews as i')
            ->join('applications as a', 'a.id', '=', 'i.application_id')
            ->join('candidates as c', 'c.id', '=', 'a.candidate_id')
            ->where('c.training_center_id', $membership->id)
            ->whereNull('c.deleted_at')
            ->where('i.scheduled_at', '>=', $now)
            ->whereNotIn('i.status', ['cancelled', 'completed', 'selected', 'rejected', 'no_show']);

        $joined = DB::table('placement_outcomes as po')
            ->join('applications as a', 'a.id', '=', 'po.application_id')
            ->join('candidates as c', 'c.id', '=', 'a.candidate_id')
            ->where('c.training_center_id', $membership->id)
            ->whereNull('c.deleted_at')
            ->whereNotNull('po.joined_on');

        $pendingJoins = DB::table('placement_outcomes as po')
            ->join('applications as a', 'a.id', '=', 'po.application_id')
            ->join('candidates as c', 'c.id', '=', 'a.candidate_id')
            ->where('c.training_center_id', $membership->id)
            ->whereNull('c.deleted_at')
            ->whereNull('po.joined_on')
            ->whereIn('po.status', ['selected', 'offered', 'joining_pending']);

        $totalCandidates = (clone $candidates)->count();
        $availableCandidates = (clone $candidates)->where('availability', 'Yes')->count();
        $batchCount = DB::table('training_batches')->where('training_center_id', $membership->id)->count();
        $readyPool = (clone $candidates)->where('availability', 'Yes')->where('training_status', 'Completed')->count();

        $batches = DB::table('training_batches as b')
            ->leftJoin('candidates as c', function ($join) {
                $join->on('c.training_batch_id', '=', 'b.id')->whereNull('c.deleted_at');
            })
            ->where('b.training_center_id', $membership->id)
            ->groupBy('b.id', 'b.code', 'b.job_role', 'b.starts_on', 'b.ends_on', 'b.capacity', 'b.status')
            ->orderByRaw("case when b.status in ('active', 'planned') then 0 else 1 end")
            ->orderByRaw('case when b.ends_on is null then 1 else 0 end')
            ->orderBy('b.ends_on')
            ->limit(5)
            ->get([
                'b.id', 'b.code', 'b.job_role', 'b.starts_on', 'b.ends_on', 'b.capacity', 'b.status',
                DB::raw('count(distinct c.id) as candidate_count'),
                DB::raw("sum(case when c.training_status = 'Completed' and c.availability = 'Yes' then 1 else 0 end) as ready_count"),
            ])
            ->map(function ($batch) {
                $batch->candidate_count = (int) $batch->candidate_count;
                $batch->ready_count = (int) $batch->ready_count;
                $batch->readiness_percent = $batch->candidate_count ? (int) round($batch->ready_count / $batch->candidate_count * 100) : 0;
                return $batch;
            });

        $supply = DB::table('candidates as c')->join('training_batches as b', 'b.id', '=', 'c.training_batch_id')
            ->where('c.training_center_id', $membership->id)->where('b.training_center_id', $membership->id)->whereNull('c.deleted_at')
            ->whereNotIn('b.status', ['cancelled', 'completed'])
            ->whereBetween('b.ends_on', [now('Asia/Kolkata')->toDateString(), now('Asia/Kolkata')->addDays(29)->toDateString()])
            ->selectRaw("coalesce(nullif(c.preferred_industry, ''), 'Other') as industry, count(*) as candidates")
            ->groupBy('industry')->orderByDesc('candidates')->get()
            ->map(fn ($row) => ['industry' => $row->industry, 'candidates' => (int) $row->candidates]);
        $jobs = DB::table('job_posts as j')
            ->leftJoin('employers as e', 'e.id', '=', 'j.employer_id')
            ->where('j.status', 'published')->whereNull('j.deleted_at')->where('j.openings', '>', 0)
            ->orderByDesc('j.published_at')->limit(5)
            ->get(['j.id', 'j.title', 'j.industry', 'j.state', 'j.district', 'j.location', 'j.openings', 'e.name as employer_name']);
        $jobs = $jobs->map(function ($job) use ($membership) {
            $fits = DB::table('candidates')->where('training_center_id', $membership->id)->whereNull('deleted_at')->where('availability', 'Yes')->where('training_status', 'Completed');
            if ($job->industry) $fits->whereRaw('lower(preferred_industry) = ?', [mb_strtolower($job->industry)]);
            $job->candidate_fit_count = $fits->count();
            return $job;
        })->filter(fn ($job) => $job->candidate_fit_count > 0)->values();

        return response()->json([
            'center' => $membership,
            'metrics' => [
                'candidates' => $totalCandidates,
                'available' => $availableCandidates,
                'batches' => $batchCount,
                'interviews' => (clone $interviews)->distinct()->count('c.id'),
                'interviews_tomorrow' => (clone $interviews)->whereBetween('i.scheduled_at', [$tomorrowStart, $tomorrowEnd])->distinct()->count('c.id'),
                'joined' => (clone $joined)->distinct()->count('c.id'),
                'pending_joins' => (clone $pendingJoins)->distinct()->count('c.id'),
                'incomplete_profiles' => (clone $candidates)->where(function ($query) {
                    $query->whereNull('resume_path')->orWhereNull('terms_accepted_at')->orWhereNull('privacy_accepted_at');
                })->count(),
                'ready_pool' => $readyPool,
            ],
            'batches' => $batches,
            'supply' => $supply,
            'jobs' => $jobs,
        ]);
    }
}

