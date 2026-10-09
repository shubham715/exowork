<?php
namespace App\Http\Controllers;
use App\Services\DemoDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class AdminDataManagementController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless(DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_id', $request->user()->id)->whereIn('roles.key', ['admin', 'superadmin'])->exists() && $request->user()->hasPermission('users.manage'), 403);
    }
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $counts = [];
        foreach (DemoDataService::SECTIONS as $table) {
            $base = DB::table($table)->whereNull('deleted_at');
            $counts[$table] = ['total' => (clone $base)->count(), 'manual' => (clone $base)->whereNull('demo_run_id')->count(), 'auto' => (clone $base)->whereNotNull('demo_run_id')->count()];
        }
        $credentials = DB::table('demo_credentials')->orderBy('section')->orderBy('id')->get()->map(function ($row) {
            $row->password = Crypt::decryptString($row->password);
            return $row;
        });
        return response()->json(compact('counts', 'credentials'))->header('Cache-Control', 'no-store, private');
    }
    public function generate(Request $request, DemoDataService $service)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['mode' => ['required', Rule::in(['append', 'replace'])], 'confirmation' => ['required', 'in:CREATE DEMO DATA']]);
        DB::transaction(function () use ($data, $service) {
            if ($data['mode'] === 'replace') $service->clean('all', false);
            $service->generate();
        });
        return $this->index($request);
    }
    public function destroy(Request $request, DemoDataService $service)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['section' => ['required', Rule::in([...DemoDataService::SECTIONS, 'all'])], 'scope' => ['required', Rule::in(['dummy', 'all'])], 'confirmation' => ['required', 'in:DELETE DATA']]);
        $service->clean($data['section'], $data['scope'] === 'dummy');
        return $this->index($request);
    }
    public function network(Request $request)
    {
        $this->authorizeAdmin($request);
        $jobs = DB::table('job_posts as j')->join('employers as e', 'e.id', '=', 'j.employer_id')->whereNull('j.deleted_at')->whereNull('e.deleted_at')->select('j.*', 'e.name as company')->orderByDesc('j.id')->get();
        $batches = DB::table('training_batches as b')->join('training_centers as c', 'c.id', '=', 'b.training_center_id')->whereNull('c.deleted_at')->select('b.*', 'c.name as center', 'c.district')->selectSub(DB::table('candidates')->selectRaw('COUNT(*)')->whereColumn('training_batch_id', 'b.id')->whereNull('deleted_at'), 'students')->orderBy('b.ends_on')->get();
        return response()->json(compact('jobs', 'batches'));
    }
    public function export(Request $request)
    {
        $this->authorizeAdmin($request);
        $rows = $this->index($request)->getData()->credentials;
        return response()->streamDownload(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Section', 'Name', 'Email / username', 'Demo password', 'Generation']);
            foreach ($rows as $row) fputcsv($file, array_map(fn ($v) => preg_match('/^[=+@\-]/', $v) ? "'".$v : $v, [$row->section, $row->name, $row->email, $row->password, $row->demo_run_id]));
            fclose($file);
        }, 'demo-credentials.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
    }
}

