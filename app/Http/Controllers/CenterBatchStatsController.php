<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CenterBatchStatsController extends CenterCandidateController
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasPermission('batches.manage') && $request->user()->hasPermission('candidates.view'), 403);
        $center = $this->center($request);
        return $this->statsForCenters($request, [$center->id]);
    }

    public function adminIndex(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->status === 'active' && $user->hasPermission('candidates.view')
            && ($user->hasPermission('partners.view') || $user->hasPermission('partners.manage'))
            && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $user->id)->whereIn('roles.key', ['admin', 'superadmin'])->exists(), 403);
        $data = $request->validate(['partner' => ['nullable', 'string', 'regex:/^(partner|center):[1-9][0-9]*$/'], 'batch_id' => ['nullable', 'integer', 'min:1']]);
        if ($request->filled('batch_id')) abort_unless($request->filled('partner'), 422, 'Select a training partner first.');
        $partners = DB::table('training_partners')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($p) => ['id' => 'partner:'.$p->id, 'name' => $p->name, 'code' => $p->code, 'type' => 'partner']);
        $standalone = DB::table('training_centers')->whereNull('deleted_at')->whereNull('training_partner_id')->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($c) => ['id' => 'center:'.$c->id, 'name' => $c->name, 'code' => $c->code, 'type' => 'center']);
        $options = $partners->concat($standalone)->sortBy('name')->values();
        $selected = isset($data['partner']) ? $options->firstWhere('id', $data['partner']) : null;
        if (isset($data['partner'])) abort_unless($selected, 404, 'Training partner is unavailable.');
        $centers = collect();
        if ($selected) {
            $centers = DB::table('training_centers')->whereNull('deleted_at');
            $centers = $selected['type'] === 'partner' ? $centers->where('training_partner_id', (int) explode(':', $selected['id'])[1]) : $centers->where('id', (int) explode(':', $selected['id'])[1]);
            $centers = $centers->pluck('id');
        }
        return $this->statsForCenters($request, $centers->all(), ['partners' => $options, 'partner' => $selected]);
    }

    private function statsForCenters(Request $request, array $centerIds, array $extra = []): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['batch_id' => ['nullable', 'integer', 'min:1']]);
        $batches = DB::table('training_batches as b')->join('training_centers as tc', 'tc.id', '=', 'b.training_center_id')->whereIn('b.training_center_id', $centerIds)
            ->select('b.id', 'b.code', 'b.job_role', 'b.starts_on', 'b.ends_on', 'b.status', 'b.trainer', 'b.training_center_id', 'tc.name as center_name')
            ->selectSub(DB::table('candidates as c')->selectRaw('COUNT(*)')->whereColumn('c.training_batch_id', 'b.id')->whereColumn('c.training_center_id', 'b.training_center_id')->whereNull('c.deleted_at'), 'students_count')
            ->orderByDesc('b.id')->get();
        $batch = isset($data['batch_id']) ? $batches->firstWhere('id', (int) $data['batch_id']) : null;
        if (isset($data['batch_id'])) abort_unless($batch, 404);
        $students = collect();
        if ($batch) {
            $candidates = Candidate::where('training_center_id', $batch->training_center_id)->where('training_batch_id', $batch->id)->whereNull('deleted_at')->orderBy('first_name')->orderBy('id')->get();
            $applications = DB::table('applications')->whereIn('candidate_id', $candidates->pluck('id'))->get(['id', 'candidate_id', 'status'])->groupBy('candidate_id');
            $placements = DB::table('placement_outcomes as p')->join('applications as a', 'a.id', '=', 'p.application_id')->whereIn('a.candidate_id', $candidates->pluck('id'))->select('p.id', 'p.status', 'p.joined_on', 'a.candidate_id')->get()->groupBy('candidate_id');
            $retention = DB::table('retention_responses')->whereIn('placement_outcome_id', $placements->flatten(1)->pluck('id'))->orderByDesc('day')->orderByDesc('id')->get()->groupBy('placement_outcome_id');
            $students = $candidates->map(function ($c) use ($batch, $applications, $placements, $retention) {
                $fields = ['first_name', 'last_name', 'whatsapp', 'district', 'qualification', 'skills', 'preferred_industry', 'availability', 'training_status', 'current_location'];
                $profile = (int) round(collect($fields)->filter(fn ($field) => filled($c->$field))->count() / count($fields) * 100);
                $outcomes = $placements->get($c->id, collect());
                $hired = $outcomes->contains(fn ($p) => $p->joined_on || in_array($p->status, ['selected', 'offered', 'joining_pending', 'joined', 'still_employed']));
                $working = $outcomes->contains(function ($p) use ($retention) {
                    if (!$p->joined_on || $p->joined_on > now('Asia/Kolkata')->toDateString()) return false;
                    $latest = $retention->get($p->id, collect())->first();
                    return $latest ? in_array($latest->status, ['still_employed', 'needs_help']) : in_array($p->status, ['joined', 'still_employed']);
                });
                $training = $this->trainingState($batch, $c->training_status);
                $steps = [true, $profile === 100, $training === 'completed', $applications->get($c->id, collect())->isNotEmpty(), $hired, $working];
                return ['id' => $c->id, 'code' => $c->candidate_code, 'name' => trim($c->first_name.' '.$c->last_name), 'qualification' => $c->qualification, 'district' => $c->district,
                    'profile_percent' => $profile, 'training_state' => $training, 'steps' => $steps, 'completed_steps' => count(array_filter($steps)), 'applications_count' => $applications->get($c->id, collect())->count()];
            });
        }
        return response()->json([...$extra, 'batches' => $batches, 'batch' => $batch, 'students' => $students,
            'summary' => ['students' => $students->count(), 'profiles_complete' => $students->where('profile_percent', 100)->count(), 'applying' => $students->filter(fn ($s) => $s['steps'][3])->count(), 'hired' => $students->filter(fn ($s) => $s['steps'][4])->count(), 'working' => $students->filter(fn ($s) => $s['steps'][5])->count()]])->header('Cache-Control', 'no-store, private');
    }

    private function trainingState(object $batch, string $status): string
    {
        $today = now('Asia/Kolkata')->toDateString();
        if ($batch->status === 'cancelled') return 'cancelled';
        if ($batch->starts_on && $batch->starts_on > $today) return 'upcoming';
        if ($status === 'Completed' || $batch->status === 'completed') return 'completed';
        if ($batch->ends_on && $batch->ends_on < $today) return 'awaiting_confirmation';
        if ($batch->ends_on && $batch->ends_on <= now('Asia/Kolkata')->addDays(15)->toDateString()) return 'finishing_soon';
        return 'in_progress';
    }
}
