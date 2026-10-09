<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmploymentWorkflowController extends Controller
{
    private function admin(Request $request, bool $write = false): void
    {
        app(WhatsAppController::class)->admin($request, $write);
    }

    public function candidate(Request $request)
    {
        $c = $request->user('candidate');
        abort_unless($c && $c->profile_status === 'active' && ! $c->deleted_at, 403);
        $applications = DB::table('applications as a')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->where('a.candidate_id', $c->id)->select('a.id', 'a.status', 'a.interview_availability', 'j.title', 'j.district', 'j.salary_min', 'j.salary_max')->get();
        $interviews = DB::table('interviews as i')->join('applications as a', 'a.id', '=', 'i.application_id')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->join('employers as e', 'e.id', '=', 'j.employer_id')->where('a.candidate_id', $c->id)->select('i.*', 'j.title', 'e.name as employer_name', 'e.contact_name', 'e.contact_phone')->get();
        $placements = DB::table('placement_outcomes as p')->join('applications as a', 'a.id', '=', 'p.application_id')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->join('employers as e', 'e.id', '=', 'j.employer_id')->where('a.candidate_id', $c->id)->select('p.*', 'j.title', 'e.name as employer_name')->get();
        $responses = DB::table('retention_responses')->whereIn('placement_outcome_id', $placements->pluck('id'))->get();
        return response()->json(['candidate' => $c->only(['first_name', 'job_interest_status', 'whatsapp_consent']), 'applications' => $applications, 'interviews' => $interviews, 'placements' => $placements, 'responses' => $responses])->header('Cache-Control', 'no-store');
    }

    public function respond(Request $request)
    {
        $c = $request->user('candidate');
        abort_unless($c && $c->profile_status === 'active' && ! $c->deleted_at, 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['interest', 'availability', 'attendance', 'retention'])], 'id' => 'nullable|integer|min:1', 'status' => 'required|string|max:30', 'day' => ['nullable', Rule::in([3, 7, 15, 30, 90])], 'notes' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($c, $data) {
            if ($data['kind'] === 'interest') {
                abort_unless(in_array($data['status'], ['interested', 'not_interested']), 422);
                DB::table('candidates')->where('id', $c->id)->update(['job_interest_status' => $data['status'], 'updated_at' => now()]);
                return;
            }
            if ($data['kind'] === 'availability') {
                abort_unless(in_array($data['status'], ['within_3_days', 'within_7_days', 'later']), 422);
                $row = DB::table('applications')->where('candidate_id', $c->id)->find($data['id'] ?? 0);
                abort_unless($row, 404);
                DB::table('applications')->where('id', $row->id)->update(['interview_availability' => $data['status'], 'updated_at' => now()]);
                $this->task($c->id, $row->id, 'availability:'.$row->id, 'Candidate availability: '.str_replace('_', ' ', $data['status']).'. Call to finalise interview.');
            } elseif ($data['kind'] === 'attendance') {
                abort_unless(in_array($data['status'], ['confirmed', 'needs_help', 'cannot_attend']), 422);
                $row = DB::table('interviews as i')->join('applications as a', 'a.id', '=', 'i.application_id')->where('a.candidate_id', $c->id)->where('i.id', $data['id'] ?? 0)->select('i.*')->first();
                abort_unless($row && in_array($row->status, ['scheduled', 'confirmed']) && $row->scheduled_at > now()->toDateTimeString(), 422, 'This interview is no longer open for confirmation.');
                DB::table('interviews')->where('id', $row->id)->update(['attendance_response' => $data['status'], 'updated_at' => now()]);
                if ($data['status'] !== 'confirmed') $this->task($c->id, $row->application_id, 'attendance:'.$row->id, 'Interview response: '.str_replace('_', ' ', $data['status']).'. '.($data['notes'] ?? ''));
            } else {
                abort_unless(in_array($data['status'], ['still_employed', 'needs_help', 'left']) && ! empty($data['day']), 422);
                $row = DB::table('placement_outcomes as p')->join('applications as a', 'a.id', '=', 'p.application_id')->where('a.candidate_id', $c->id)->where('p.id', $data['id'] ?? 0)->select('p.*')->first();
                abort_unless($row && $row->joined_on && \Carbon\Carbon::parse($row->joined_on, 'Asia/Kolkata')->addDays($data['day'])->lte(now('Asia/Kolkata')), 422, 'This check-in is not due yet.');
                DB::table('retention_responses')->updateOrInsert(['placement_outcome_id' => $row->id, 'day' => $data['day']], ['status' => $data['status'], 'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                if ($data['status'] !== 'still_employed') $this->task($c->id, $row->application_id, 'retention:'.$row->id.':'.$data['day'], 'High-risk employment check-in: '.str_replace('_', ' ', $data['status']).'. '.($data['notes'] ?? ''));
            }
        });
        return response()->json(['message' => 'Response saved. The EXOWORK team can now follow up.']);
    }

    private function task(int $candidate, int $application, string $key, string $notes): void
    {
        app(\App\Services\EmploymentFollowups::class)->create($candidate, $application, $key, $notes);
    }

    public function index(Request $request)
    {
        $this->admin($request);
        $applications = DB::table('applications as a')->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->whereNull('c.deleted_at')->whereNull('j.deleted_at')->select('a.*', 'c.first_name', 'c.last_name', 'c.candidate_code', 'j.title')->orderByDesc('a.id')->limit(200)->get();
        $interviews = DB::table('interviews as i')->join('applications as a', 'a.id', '=', 'i.application_id')->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->whereNull('c.deleted_at')->select('i.*', 'c.first_name', 'c.last_name', 'c.candidate_code', 'j.title')->orderByDesc('i.scheduled_at')->limit(200)->get();
        $placements = DB::table('placement_outcomes as p')->join('applications as a', 'a.id', '=', 'p.application_id')->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('job_posts as j', 'j.id', '=', 'a.job_post_id')->whereNull('c.deleted_at')->select('p.*', 'c.first_name', 'c.last_name', 'c.candidate_code', 'j.title')->orderByDesc('p.updated_at')->limit(200)->get();
        $followups = DB::table('candidate_followups as f')->join('candidates as c', 'c.id', '=', 'f.candidate_id')->join('users as u', 'u.id', '=', 'f.agent_id')->select('f.*', 'c.first_name', 'c.last_name', 'c.candidate_code', 'u.name as owner')->orderByDesc('f.id')->limit(200)->get();
        $responses = DB::table('retention_responses')->whereIn('placement_outcome_id', $placements->pluck('id'))->get();
        $interest_counts = DB::table('candidates')->whereNull('deleted_at')->selectRaw('job_interest_status, count(*) as total')->groupBy('job_interest_status')->pluck('total', 'job_interest_status');
        return response()->json(compact('applications', 'interviews', 'placements', 'followups', 'responses', 'interest_counts'))->header('Cache-Control', 'no-store');
    }

    public function save(Request $request)
    {
        $this->admin($request, true);
        $data = $request->validate(['kind' => ['required', Rule::in(['interview', 'placement', 'followup'])], 'id' => 'nullable|integer|min:1', 'application_id' => 'nullable|integer|exists:applications,id', 'scheduled_at' => 'nullable|date', 'location' => 'nullable|string|max:255', 'mode' => ['nullable', Rule::in(['Online', 'On-site', 'Phone'])], 'status' => 'required|string|max:30', 'joined_on' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'expected_joining_on' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000']);
        $id = DB::transaction(function () use ($data, $request) {
            if ($data['kind'] === 'interview') {
                abort_unless(! empty($data['application_id']) && ! empty($data['scheduled_at']) && ! empty($data['location']) && in_array($data['status'], ['scheduled', 'confirmed', 'completed', 'no_show', 'cancelled']), 422, 'Choose application, date, location and a valid interview status.');
                if (in_array($data['status'], ['scheduled', 'confirmed'])) abort_unless(\Carbon\Carbon::parse($data['scheduled_at'], 'Asia/Kolkata')->isFuture(), 422, 'Choose a future interview date.');
                $values = ['application_id' => $data['application_id'], 'scheduled_at' => \Carbon\Carbon::parse($data['scheduled_at'], 'Asia/Kolkata')->utc(), 'location' => $data['location'], 'mode' => $data['mode'] ?? 'On-site', 'status' => $data['status'], 'notes' => $data['notes'] ?? null, 'agent_id' => $request->user()->id, 'updated_at' => now()];
                if (! empty($data['id'])) {
                    $old = DB::table('interviews')->find($data['id']); abort_unless($old && $old->application_id === $data['application_id'], 404);
                    DB::table('interviews')->where('id', $old->id)->update($values); return $old->id;
                }
                return DB::table('interviews')->insertGetId([...$values, 'created_at' => now()]);
            }
            if ($data['kind'] === 'placement') {
                abort_unless(! empty($data['application_id']) && in_array($data['status'], ['selected', 'rejected', 'joining_pending', 'joined']), 422);
                abort_if($data['status'] === 'joined' && empty($data['joined_on']), 422, 'Record the actual joining date.');
                DB::table('applications')->where('id', $data['application_id'])->lockForUpdate()->first();
                DB::table('placement_outcomes')->updateOrInsert(['application_id' => $data['application_id']], ['status' => $data['status'], 'joined_on' => $data['status'] === 'joined' ? $data['joined_on'] : null, 'expected_joining_on' => $data['expected_joining_on'] ?? null, 'verified_at' => now(), 'verified_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                return DB::table('placement_outcomes')->where('application_id', $data['application_id'])->value('id');
            }
            abort_unless(! empty($data['id']) && in_array($data['status'], ['open', 'completed']), 422);
            abort_unless(DB::table('candidate_followups')->where('id', $data['id'])->exists(), 404);
            DB::table('candidate_followups')->where('id', $data['id'])->update(['status' => $data['status'], 'completed_at' => $data['status'] === 'completed' ? now() : null, 'notes' => $data['notes'] ?? null, 'updated_at' => now()]);
            return $data['id'];
        });
        DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'employment.'.$data['kind'].'_saved', 'subject_type' => $data['kind'], 'subject_id' => $id, 'changes' => json_encode(['status' => $data['status']]), 'ip_address' => $request->ip(), 'created_at' => now()]);
        return response()->json(['message' => 'Saved. Approved WhatsApp workflow notifications will be queued by the scheduler.']);
    }
}
