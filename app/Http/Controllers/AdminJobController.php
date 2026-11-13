<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminJobController extends Controller
{
    private function authorizeJobs(Request $request, bool $manage = false): void
    {
        $user = $request->user();
        $staff = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_id', $user->id)->whereIn('roles.key', ['admin', 'superadmin', 'agent'])->exists();
        abort_unless($staff && ($user->hasPermission('jobs.manage') || (!$manage && $user->hasPermission('jobs.view'))), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeJobs($request);
        $jobs = DB::table('job_posts as j')->join('employers as e', 'e.id', '=', 'j.employer_id')
            ->whereNull('j.deleted_at')->whereNull('e.deleted_at')->select('j.*', 'e.name as company')->orderByDesc('j.id')->get();
        return response()->json(['jobs' => $jobs, 'can_manage' => $request->user()->hasPermission('jobs.manage')]);
    }

    public function status(Request $request, int $id)
    {
        return $this->mutate($request, $id, false);
    }

    public function destroy(Request $request, int $id)
    {
        return $this->mutate($request, $id, true);
    }

    private function mutate(Request $request, int $id, bool $delete)
    {
        $this->authorizeJobs($request, true);
        $data = $request->validate($delete ? ['confirmation' => ['required', 'in:DELETE']] : [
            'status' => ['required', Rule::in(['active', 'paused'])],
            'expected_status' => ['required', 'string'],
        ]);
        DB::transaction(function () use ($request, $id, $delete, $data) {
            $job = DB::table('job_posts')->whereNull('deleted_at')->lockForUpdate()->find($id);
            abort_unless($job, 404);
            $employer = DB::table('employers')->whereNull('deleted_at')->lockForUpdate()->find($job->employer_id);
            abort_unless($employer, 404);
            if (!$delete) {
                abort_if($job->status !== $data['expected_status'], 409, 'This job changed. Refresh the list and try again.');
                if ($data['status'] === 'active') {
                    if ($employer->status !== 'verified') {
                        throw ValidationException::withMessages(['status' => 'Verify the employer before activating this job.']);
                    }
                    $rules = [];
                    foreach (['title', 'description', 'industry', 'state', 'district', 'location', 'department', 'education', 'experience', 'responsibilities', 'skills'] as $field) {
                        $rules[$field] = ['required', 'string'];
                    }
                    $rules += [
                        'openings' => ['required', 'integer', 'min:1', 'max:100000'],
                        'salary_min' => ['nullable', 'integer', 'min:0'],
                        'salary_max' => ['nullable', 'integer', 'min:0', 'gte:salary_min'],
                        'job_type' => ['required', Rule::in(['Full-Time','Part-Time','Contract','Internship','Freelance'])],
                        'workplace_type' => ['required', Rule::in(['On-site','Remote','Hybrid'])],
                        'salary_type' => ['required', Rule::in(['Fixed','Hourly','Incentive-based','Negotiable'])],
                        'application_deadline' => ['required', 'date', 'after_or_equal:'.now('Asia/Kolkata')->toDateString()],
                    ];
                    Validator::make((array) $job, $rules)->validate();
                }
            }
            $changes = ['status' => $delete ? 'closed' : $data['status'], 'updated_at' => now()];
            if ($delete) $changes['deleted_at'] = now();
            elseif ($data['status'] === 'active' && !$job->published_at) $changes['published_at'] = now();
            DB::table('job_posts')->where('id', $id)->update($changes);
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->user()->id, 'action' => $delete ? 'admin.job_deleted' : 'admin.job_status_changed',
                'subject_type' => 'job_post', 'subject_id' => $id,
                'changes' => json_encode(['before' => ['status' => $job->status], 'after' => $changes]),
                'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        });
        return response()->json(['message' => $delete ? 'Job deleted. Application history has been preserved.' : 'Job status updated.']);
    }
}
