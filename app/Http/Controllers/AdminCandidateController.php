<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCandidateController extends Controller
{
    private function authorize(Request $request, bool $manage = false): void
    {
        app(WhatsAppController::class)->admin($request, $manage);
    }

    private function query(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'availability' => ['nullable', 'array', 'max:3'], 'availability.*' => [Rule::in(['Yes', 'No', 'Available after training'])],
            'statuses' => ['nullable', 'array', 'max:2'], 'statuses.*' => [Rule::in(['active', 'inactive'])],
            'consent' => ['nullable', Rule::in(['yes', 'no'])],
            'industries' => ['nullable', 'array', 'max:100'], 'industries.*' => ['string', 'max:60'],
            'states' => ['nullable', 'array', 'max:100'], 'states.*' => ['string', 'max:80'],
            'qualifications' => ['nullable', 'array', 'max:100'], 'qualifications.*' => ['string', 'max:60'],
            'centers' => ['nullable', 'array', 'max:100'], 'centers.*' => ['string', 'max:255'],
            'training' => ['nullable', 'array', 'max:3'], 'training.*' => [Rule::in(['Ongoing', 'Completed', 'Not enrolled'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['first_name', 'qualification', 'preferred_industry', 'availability', 'created_at', 'applications_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', Rule::in([20, 25, 50, 100])],
            'ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['integer', 'distinct'],
        ]);
        $query = Candidate::whereNull('candidates.deleted_at');
        foreach (['availability' => 'availability', 'statuses' => 'profile_status', 'industries' => 'preferred_industry', 'states' => 'state', 'qualifications' => 'qualification', 'centers' => 'training_center', 'training' => 'training_status', 'ids' => 'id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->whereIn('candidates.'.$column, $filters[$key]);
            }
        }
        if (! empty($filters['consent'])) {
            $query->where('whatsapp_consent', $filters['consent'] === 'yes');
        }
        if (! empty($filters['from'])) {
            $query->where('candidates.created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('candidates.created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
        if ($search = trim($filters['search'] ?? '')) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($q) use ($search) {
                foreach (['first_name', 'last_name', 'candidate_code', 'whatsapp', 'email', 'district', 'training_center', 'skills'] as $column) {
                    $q->orWhereRaw("LOWER(candidates.$column) LIKE ? ESCAPE '!'", [$search]);
                }
                $name = DB::connection()->getDriverName() === 'mysql' ? "CONCAT(first_name, ' ', last_name)" : "first_name || ' ' || last_name";
                $q->orWhereRaw("LOWER($name) LIKE ? ESCAPE '!'", [$search]);
            });
        }

        // Keep list/export fields explicit: identity documents and login data must never enter the directory response.
        return $query->select(['candidates.id', 'candidate_code', 'first_name', 'last_name', 'whatsapp', 'email', 'whatsapp_consent', 'qualification', 'state', 'district', 'preferred_industry', 'availability', 'profile_status', 'training_center', 'training_status', 'batch_code', 'experience_type', 'skills', 'expected_monthly_salary', 'created_at', 'updated_at'])
            ->selectSub(DB::table('applications')->selectRaw('COUNT(*)')->whereColumn('candidate_id', 'candidates.id'), 'applications_count')
            ->selectSub(DB::table('whatsapp_messages')->selectRaw('COUNT(*)')->whereColumn('candidate_id', 'candidates.id'), 'whatsapp_count')
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->orderByDesc('candidates.id');
    }

    public function index(Request $request)
    {
        $this->authorize($request);
        $page = $this->query($request)->paginate((int) $request->input('per_page', 25));
        $base = Candidate::whereNull('deleted_at');
        $options = [];
        foreach (['industries' => 'preferred_industry', 'states' => 'state', 'qualifications' => 'qualification', 'centers' => 'training_center'] as $key => $column) {
            $options[$key] = (clone $base)->whereNotNull($column)->where($column, '!=', '')->distinct()->orderBy($column)->pluck($column);
        }

        return response()->json([...$page->toArray(), 'can_manage' => $request->user()->hasPermission('candidates.update'), 'counts' => [
            'all' => (clone $base)->count(), 'available' => (clone $base)->where('availability', 'Yes')->count(),
            'training' => (clone $base)->where('training_status', 'Ongoing')->count(), 'consented' => (clone $base)->where('whatsapp_consent', true)->count(),
            'inactive' => (clone $base)->where('profile_status', 'inactive')->count(),
        ], 'filters' => $options]);
    }

    public function export(Request $request)
    {
        $this->authorize($request);
        $query = $this->query($request);

        return response()->streamDownload(function () use ($query) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Candidate', 'Code', 'WhatsApp', 'Email', 'Qualification', 'Industry', 'District', 'State', 'Training center', 'Training status', 'Availability', 'Profile status', 'WhatsApp consent', 'Applications', 'Registered']);
            foreach ($query->lazy(500) as $c) {
                fputcsv($file, array_map(fn ($v) => preg_match('/^\s*[=+@\-]/', (string) $v) ? "'".$v : $v, [$c->first_name.' '.$c->last_name, $c->candidate_code, $c->whatsapp, $c->email, $c->qualification, $c->preferred_industry, $c->district, $c->state, $c->training_center, $c->training_status, $c->availability, $c->profile_status, $c->whatsapp_consent ? 'Yes' : 'No', $c->applications_count, $c->created_at]));
            }
            fclose($file);
        }, 'exowork-candidates.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function update(Request $request, string $code)
    {
        $this->authorize($request, true);
        $data = $request->validate(['availability' => ['required', Rule::in(['Yes', 'No', 'Available after training'])], 'skills' => ['required', 'string', 'max:2000'], 'expected_monthly_salary' => ['required', 'integer', 'between:0,10000000']]);
        DB::transaction(function () use ($request, $code, $data) {
            $candidate = Candidate::whereNull('deleted_at')->where('candidate_code', $code)->lockForUpdate()->firstOrFail();
            $candidate->update($data);
            $this->audit($request, $candidate->id, 'updated', ['fields' => array_keys($data)]);
        });

        return response()->json(['message' => 'Candidate work preferences updated.']);
    }

    public function actions(Request $request)
    {
        $this->authorize($request, true);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'distinct'], 'action' => ['required', Rule::in(['activate', 'disable'])]]);
        DB::transaction(function () use ($request, $data) {
            $rows = Candidate::whereNull('deleted_at')->whereIn('id', $data['ids'])->lockForUpdate()->get();
            abort_unless($rows->count() === count($data['ids']), 422, 'Some candidate profiles no longer exist. Refresh and try again.');
            foreach ($rows as $candidate) {
                $previous = $candidate->profile_status;
                $candidate->update(['profile_status' => $data['action'] === 'activate' ? 'active' : 'inactive']);
                $this->audit($request, $candidate->id, $data['action'], ['previous_status' => $previous, 'profile_status' => $candidate->profile_status]);
            }
        });

        return response()->json(['message' => count($data['ids']).' candidate profile(s) updated.']);
    }

    private function audit(Request $request, int $id, string $action, array $changes): void
    {
        DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => 'admin.candidate_'.$action, 'subject_type' => 'candidate', 'subject_id' => $id, 'changes' => json_encode($changes), 'ip_address' => $request->ip(), 'created_at' => now()]);
    }
}
