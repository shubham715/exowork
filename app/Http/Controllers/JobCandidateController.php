<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\HiringCredits;
use App\Services\JobEligibility;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class JobCandidateController extends Controller
{
    private const FILTERS = ['state', 'district', 'qualification', 'experience_type', 'availability'];

    public function __construct(private JobEligibility $eligibility) {}

    private function job(Request $request, int $id, bool $lock = false): object
    {
        $employer = app(EmployerWorkspaceController::class)->employer($request);
        abort_unless($employer->status === 'verified', 403, 'Verify your company to browse candidates.');
        $job = DB::table('job_posts')->where('employer_id', $employer->id)->whereNull('deleted_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($id);
        abort_unless($job, 404);
        return $job;
    }

    private function profiles(object $job)
    {
        return Candidate::query()->whereNull('deleted_at')->where('profile_status', 'active')
            ->where(fn ($q) => $q->where(fn ($consent) => $consent->whereNotNull('terms_accepted_at')->whereNotNull('privacy_accepted_at'))
                ->orWhere(fn ($import) => $import->where('registration_source', 'admin_import')->whereNotNull('created_by_user_id')->whereNotNull('import_data')))
            ->whereNotIn('id', DB::table('job_candidate_skips')->where('job_post_id', $job->id)->select('candidate_id'))
            ->addSelect('candidates.*')
            ->addSelect(['unlocked_at' => $this->reviewDates($job, 'unlocked_at'), 'shortlisted_at' => $this->reviewDates($job, 'shortlisted_at')]);
    }

    private function reviewDates(object $job, string $column)
    {
        return DB::table('job_candidate_profiles')->where('job_post_id', $job->id)->whereColumn('candidate_id', 'candidates.id')->select($column);
    }

    public function index(Request $request, int $id)
    {
        $job = $this->job($request, $id);
        $rules = array_fill_keys([...self::FILTERS, 'area', 'search'], 'nullable|string|max:150');
        $filters = $request->validate([...$rules, 'page' => 'nullable|integer|min:1|max:1000000', 'sort' => 'nullable|in:newest,name', 'scope' => 'nullable|in:all,matching']);
        $pool = $this->profiles($job)->orderByDesc('id')->get();
        $pool->each(fn ($candidate) => $candidate->setAttribute('job_match', $this->eligibility->matches($job, $candidate)));
        $scope = $filters['scope'] ?? 'matching';
        $matches = $scope === 'all' ? $pool : $pool->where('job_match', true);
        $options = [];
        foreach (self::FILTERS as $key) {
            $options[$key] = $matches->pluck($key)->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
            if (filled($filters[$key] ?? null)) {
                $matches = $matches->where($key, $filters[$key]);
            }
        }
        $area = trim($filters['area'] ?? '');
        $search = trim($filters['search'] ?? '');
        $matches = $matches->filter(fn ($c) =>
            ($area === '' || ($c->location_consent && mb_stripos((string) $c->current_location, $area) !== false))
            && ($search === '' || mb_stripos($c->first_name.' '.$c->last_name.' '.$c->skills, $search) !== false)
        );
        if (($filters['sort'] ?? '') === 'name') {
            $matches = $matches->sortBy(fn ($c) => mb_strtolower($c->first_name.' '.$c->last_name));
        }
        $matches = $matches->sortByDesc('shortlisted_at')->values();
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($matches->count() / 20)));
        return response()->json([
            'job' => $job, 'balance' => HiringCredits::balance($job), 'filter_options' => $options,
            'scope' => $scope, 'pool_total' => $pool->count(), 'matching_total' => $pool->where('job_match', true)->count(),
            'total' => $matches->count(), 'page' => $page, 'per_page' => 20,
            'can_invite' => $this->isLive($job),
            'candidates' => $matches->forPage($page, 20)->map(fn ($c) => [
                ...$c->only(['id', 'candidate_code', 'first_name', 'last_name', 'qualification', 'district', 'state', 'skills', 'experience_type', 'availability', 'training_course']),
                'job_match' => (bool)$c->job_match,
                'unlocked' => (bool) $c->unlocked_at, 'shortlisted' => (bool) $c->shortlisted_at,
                ...($c->unlocked_at ? [
                    ...$this->unlockedDetails($job, $c),
                ] : []),
            ])->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function unlockedDetails(object $job, Candidate $candidate): array
    {
        return [
            ...$candidate->only(['id', 'experience_details', 'email', 'whatsapp', 'whatsapp_consent']),
            'unlocked' => true,
            'area' => $candidate->location_consent ? $candidate->current_location : null,
            'has_resume' => $this->resumeFile($job, $candidate) !== null,
        ];
    }

    private function resumeFile(object $job, Candidate $candidate): ?array
    {
        $application = DB::table('applications')->where('job_post_id', $job->id)
            ->where('candidate_id', $candidate->id)->whereNotNull('resume_path')
            ->orderByDesc('id')->first(['resume_path', 'resume_name']);
        foreach ([[$application?->resume_path, $application?->resume_name], [$candidate->resume_path, null]] as [$path, $name]) {
            if ($path && Storage::exists($path)) {
                return [$path, $name ?: $candidate->candidate_code.'-resume.'.pathinfo($path, PATHINFO_EXTENSION)];
            }
        }
        return null;
    }

    private function selection(Request $request): array
    {
        return $request->validate(['candidate_ids' => 'required|array|list|min:1|max:500', 'candidate_ids.*' => 'required|integer|min:1|distinct'])['candidate_ids'];
    }

    public function unlock(Request $request, int $id)
    {
        $ids = $this->selection($request);
        return DB::transaction(function () use ($request, $id, $ids) {
            $job = $this->job($request, $id, true);
            $profiles = $this->profiles($job)->whereIn('id', $ids)->get();
            abort_unless($profiles->count() === count($ids), 404, 'Some selected candidates are no longer available.');
            $locked = $profiles->reject(fn ($c) => $c->unlocked_at);
            $cost = $locked->count();
            $balance = HiringCredits::balance($job);
            if ($cost > $balance) {
                return response()->json([
                    'code' => 'INSUFFICIENT_PROFILE_CREDITS', 'balance' => $balance, 'required' => $cost,
                    'message' => 'Insufficient credits. Contact admin to buy more credits for this job.',
                ], 422);
            }
            if ($cost) {
                DB::table('job_candidate_profiles')->upsert($locked->map(fn ($c) => [
                    'job_post_id' => $id, 'candidate_id' => $c->id, 'unlocked_at' => now(),
                ])->values()->all(), ['job_post_id', 'candidate_id'], ['unlocked_at']);
                DB::table('job_posts')->where('id', $id)->increment('profile_credits_used', $cost);
            }
            return response()->json([
                'unlocked' => $cost, 'balance' => $balance - $cost,
                'candidates' => $profiles->map(fn ($candidate) => $this->unlockedDetails($job, $candidate))->values(),
            ])->header('Cache-Control', 'no-store, private');
        }, 3);
    }

    public function shortlist(Request $request, int $id, int $candidate)
    {
        $shortlisted = $request->validate(['shortlisted' => 'required|boolean'])['shortlisted'];
        return DB::transaction(function () use ($request, $id, $candidate, $shortlisted) {
            $job = $this->job($request, $id, true);
            $this->profile($job, $candidate);
            DB::table('job_candidate_profiles')->upsert([
                'job_post_id' => $id, 'candidate_id' => $candidate, 'shortlisted_at' => $shortlisted ? now() : null,
            ], ['job_post_id', 'candidate_id'], ['shortlisted_at']);
            return response()->json(['shortlisted' => (bool) $shortlisted]);
        }, 3);
    }

    public function reject(Request $request, int $id, int $candidate)
    {
        return DB::transaction(function () use ($request, $id, $candidate) {
            $job = $this->job($request, $id, true);
            if (! DB::table('job_candidate_skips')->where('job_post_id', $id)->where('candidate_id', $candidate)->exists()) {
                $this->profile($job, $candidate);
                DB::table('job_candidate_skips')->insert(['job_post_id' => $id, 'candidate_id' => $candidate, 'created_at' => now()]);
            }
            return response()->json(['skipped' => true]);
        }, 3);
    }

    private function profile(object $job, int $id, bool $unlocked = false): Candidate
    {
        $profile = $this->profiles($job)->find($id);
        abort_unless($profile && (!$unlocked || $profile->unlocked_at), 404);
        return $profile;
    }

    public function resume(Request $request, int $id, int $candidate)
    {
        $job = $this->job($request, $id);
        $file = $this->resumeFile($job, $this->profile($job, $candidate, true));
        abort_unless($file, 404, 'This candidate has no resume available for this job.');
        return Storage::download($file[0], $file[1], [
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function export(Request $request, int $id)
    {
        $job = $this->job($request, $id);
        $ids = $this->selection($request);
        $profiles = $this->profiles($job)->whereIn('id', $ids)->get()->filter(fn ($c) => $c->unlocked_at)->sortBy('first_name');
        abort_unless($profiles->count() === count($ids), 422, 'Unlock every selected profile before exporting.');
        return response()->streamDownload(function () use ($profiles) {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Name', 'Phone number', 'Email', 'Candidate ID', 'District', 'State', 'Qualification', 'Experience', 'Skills', 'Availability'], ',', '"', '');
            foreach ($profiles as $c) {
                $row = [trim($c->first_name.' '.$c->last_name), $c->whatsapp, $c->email, $c->candidate_code, $c->district, $c->state, $c->qualification, $c->experience_type, $c->skills, $c->availability];
                fputcsv($file, array_map(fn ($value) => preg_match('/^(?:\s*[=+@-]|[\t\r\n])/', (string) $value) ? "'".$value : $value, $row), ',', '"', '');
            }
            fclose($file);
        }, "job-{$id}-candidates.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function isLive(object $job): bool
    {
        return in_array($job->status, ['active', 'published'])
            && (!$job->application_deadline || $job->application_deadline >= now('Asia/Kolkata')->toDateString());
    }

    public function invite(Request $request, int $id, int $candidate, WhatsAppWorkflow $workflow, WhatsAppCloud $cloud)
    {
        $job = $this->job($request, $id);
        $profile = $this->profile($job, $candidate, true);
        abort_unless($this->isLive($job), 422, 'WhatsApp invitations are available for live jobs.');
        abort_unless($profile->whatsapp_consent, 422, 'This candidate has not enabled WhatsApp messages.');
        abort_if($cloud->missing(), 422, 'WhatsApp is unavailable. Contact admin.');
        $record = $workflow->employerInvitation($job, $profile, $request->user()->id);
        abort_unless($record, 422, 'The job opportunity WhatsApp template needs administrator approval.');
        return response()->json(['status' => $record->status], 202);
    }
}
