<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CenterWorkspaceController extends Controller
{
    private function center(Request $request): object
    {
        $center = DB::table('organization_memberships as m')->join('training_centers as c', 'c.id', '=', 'm.training_center_id')
            ->where('m.user_id', $request->user()->id)->where('m.status', 'active')->whereNull('c.deleted_at')->select('c.*')->first();
        abort_unless($center, 403, 'No active training center membership.');
        return $center;
    }

    public function show(Request $request)
    {
        $center = $this->center($request);
        $start = now('Asia/Kolkata')->startOfDay();
        $end = $start->copy()->addDays(29)->endOfDay();
        $candidates = DB::table('candidates')->where('training_center_id', $center->id)->whereNull('deleted_at');
        $base = fn ($table, $alias) => DB::table("$table as $alias")->join('applications as a', 'a.id', '=', "$alias.application_id")
            ->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')
            ->leftJoin('employers as e', 'e.id', '=', 'j.employer_id')->where('c.training_center_id', $center->id)->whereNull('c.deleted_at');
        $interviews = $base('interviews', 'i')->orderBy('i.scheduled_at')->get(['i.*', 'c.id as candidate_id', 'c.first_name', 'c.last_name', 'c.candidate_code', 'j.title as job_title', 'e.name as employer_name'])->map(function ($record) { $record->full_name = trim($record->first_name.' '.$record->last_name); return $record; });
        $placements = $base('placement_outcomes', 'p')->orderByDesc('p.updated_at')->get(['p.*', 'c.id as candidate_id', 'c.first_name', 'c.last_name', 'c.candidate_code', 'j.title as job_title', 'e.name as employer_name'])->map(function ($record) { $record->full_name = trim($record->first_name.' '.$record->last_name); return $record; });
        $batches = DB::table('training_batches as b')->leftJoin('candidates as c', function ($join) use ($center) {
            $join->on('c.training_batch_id', '=', 'b.id')->where('c.training_center_id', $center->id)->whereNull('c.deleted_at');
        })->where('b.training_center_id', $center->id)->whereNotIn('b.status', ['cancelled', 'completed'])->whereDate('b.ends_on', '>=', $start->toDateString())
            ->groupBy('b.id', 'b.code', 'b.job_role', 'b.ends_on', 'b.starts_on', 'b.status')->orderBy('b.ends_on')
            ->get(['b.id', 'b.code', 'b.job_role', 'b.ends_on', 'b.starts_on', 'b.status', DB::raw('count(c.id) as candidate_count'), DB::raw("sum(case when c.availability = 'Yes' then 1 else 0 end) as available_count")]);
        $upcomingInterviews = $interviews->filter(fn ($i) => $i->scheduled_at >= now()->toDateTimeString() && $i->scheduled_at <= $end->copy()->utc()->toDateTimeString() && !in_array($i->status, ['cancelled', 'completed', 'rejected', 'selected', 'no_show']));
        $pending = $placements->filter(fn ($p) => !$p->joined_on && in_array($p->status, ['selected', 'offered', 'joining_pending']));
        $upcomingJoins = $pending->filter(fn ($p) => $p->expected_joining_on && $p->expected_joining_on >= $start->toDateString() && $p->expected_joining_on <= $end->toDateString());
        $windowBatches = $batches->filter(fn ($b) => $b->ends_on <= $end->toDateString());
        $weeks = collect(range(0, 4))->map(function ($index) use ($start, $end, $windowBatches, $upcomingInterviews, $upcomingJoins) {
            $from = $start->copy()->addDays($index * 7); $to = $from->copy()->addDays(6)->min($end);
            return ['from' => $from->toDateString(), 'to' => $to->toDateString(),
                'candidates' => (int) $windowBatches->filter(fn ($b) => $b->ends_on >= $from->toDateString() && $b->ends_on <= $to->toDateString())->sum('candidate_count'),
                'interviews' => $upcomingInterviews->filter(fn ($i) => Carbon::parse($i->scheduled_at, 'UTC')->timezone('Asia/Kolkata')->toDateString() >= $from->toDateString() && Carbon::parse($i->scheduled_at, 'UTC')->timezone('Asia/Kolkata')->toDateString() <= $to->toDateString())->unique('candidate_id')->count(),
                'joining' => $upcomingJoins->filter(fn ($p) => $p->expected_joining_on >= $from->toDateString() && $p->expected_joining_on <= $to->toDateString())->unique('candidate_id')->count()];
        });
        $jobs = DB::table('job_posts as j')->leftJoin('employers as e', 'e.id', '=', 'j.employer_id')->where('j.status', 'published')->whereNull('j.deleted_at')->where('j.openings', '>', 0)->orderByDesc('j.published_at')->get(['j.*', 'e.name as employer_name'])->map(function ($job) use ($candidates) {
            $matches = (clone $candidates)->where('availability', 'Yes')->where('training_status', 'Completed');
            if ($job->industry) $matches->whereRaw('lower(preferred_industry) = ?', [mb_strtolower($job->industry)]);
            $job->candidate_fit_count = $matches->count(); return $job;
        })->filter(fn ($j) => $j->candidate_fit_count > 0)->values();
        $interviewsForDisplay = $interviews->map(function ($interview) {
            $copy = clone $interview;
            $copy->scheduled_at = Carbon::parse($interview->scheduled_at, 'UTC')->timezone('Asia/Kolkata')->format('Y-m-d H:i:s');
            return $copy;
        });
        return response()->json(['center' => $center, 'partner' => $center->training_partner_id ? DB::table('training_partners')->find($center->training_partner_id) : null,
            'interviews' => $interviewsForDisplay, 'placements' => $placements, 'jobs' => $jobs, 'pipeline' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'batches' => $batches->take(3)->values(), 'weeks' => $weeks,
                'metrics' => ['completing' => (int) $windowBatches->sum('candidate_count'), 'interviews' => $upcomingInterviews->unique('candidate_id')->count(), 'joining' => $upcomingJoins->unique('candidate_id')->count(), 'undated' => $pending->filter(fn ($p) => !$p->expected_joining_on)->unique('candidate_id')->count()]],
            'performance' => ['candidates' => (clone $candidates)->count(), 'available' => (clone $candidates)->where('availability', 'Yes')->count(), 'interviewed' => $interviews->where('status', 'completed')->unique('candidate_id')->count(), 'selected' => $placements->whereIn('status', ['selected', 'offered', 'joining_pending', 'joined'])->unique('candidate_id')->count(), 'joined' => $placements->filter(fn ($p) => $p->joined_on)->unique('candidate_id')->count()]]);
    }

    public function joining(Request $request, int $id)
    {
        $center = $this->center($request);
        $data = $request->validate(['expected_joining_on' => ['present', 'nullable', 'date_format:Y-m-d']]);
        $record = DB::table('placement_outcomes as p')->join('applications as a', 'a.id', '=', 'p.application_id')->join('candidates as c', 'c.id', '=', 'a.candidate_id')
            ->where('p.id', $id)->where('c.training_center_id', $center->id)->whereNull('c.deleted_at')->select('p.*')->first();
        abort_unless($record, 404);
        abort_unless(!$record->joined_on && in_array($record->status, ['selected', 'offered', 'joining_pending']), 422, 'Only pending joining records can be updated.');
        DB::transaction(function () use ($request, $record, $id, $data) {
            DB::table('placement_outcomes')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'center.joining_date_updated', 'subject_type' => 'placement_outcome', 'subject_id' => $id,
                'changes' => json_encode(['expected_joining_on' => ['from' => $record->expected_joining_on, 'to' => $data['expected_joining_on']]]), 'ip_address' => $request->ip(), 'created_at' => now()]);
        });
        return response()->json(['saved' => true]);
    }
}


