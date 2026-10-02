<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminTrainingCenterController extends Controller
{
    private const TYPES = ['Individual', 'Private', 'Government', 'NGO', 'ITI', 'Polytechnic', 'College', 'Skill Training Center'];
    private const STATUSES = ['pending', 'verified', 'rejected', 'inactive'];

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('partners.view') || $request->user()?->hasPermission('partners.manage'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('partners.manage'), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $centers = DB::table('training_centers as c')
            ->leftJoin('training_partners as p', 'p.id', '=', 'c.training_partner_id')
            ->leftJoinSub(DB::table('training_batches')->selectRaw('training_center_id, count(*) as batches')->groupBy('training_center_id'), 'b', 'b.training_center_id', '=', 'c.id')
            ->leftJoinSub(DB::table('candidates')->whereNull('deleted_at')->selectRaw("training_center_id, count(*) as candidates, sum(case when profile_status = 'active' then 1 else 0 end) as active_candidates")->groupBy('training_center_id'), 'ca', 'ca.training_center_id', '=', 'c.id')
            ->leftJoinSub(DB::table('candidates as cc')->join('applications as a', 'a.candidate_id', '=', 'cc.id')->join('placement_outcomes as po', 'po.application_id', '=', 'a.id')->whereNotNull('po.joined_on')->whereNull('cc.deleted_at')->selectRaw('cc.training_center_id, count(distinct cc.id) as joined')->groupBy('cc.training_center_id'), 'pl', 'pl.training_center_id', '=', 'c.id')
            ->whereNull('c.deleted_at')
            ->select('c.*', 'p.name as partner_name', 'p.code as partner_code', 'p.status as partner_status', DB::raw('coalesce(b.batches, 0) as batches'), DB::raw('coalesce(ca.candidates, 0) as candidates'), DB::raw('coalesce(ca.active_candidates, 0) as active_candidates'), DB::raw('coalesce(pl.joined, 0) as joined'))
            ->orderByDesc('c.updated_at')->get()
            ->map(function ($center) {
                if ($center->status === 'active' && !$center->verified_at) $center->status = 'pending';
                $center->accreditations = json_decode($center->accreditations ?: '[]', true);
                $center->infrastructure_evidence = json_decode($center->infrastructure_evidence ?: '[]', true);
                return $center;
            });

        $partners = DB::table('training_partners')->whereNull('deleted_at')->orderBy('name')->get();

        return response()->json([
            'centers' => $centers,
            'partners' => $partners,
            'can_manage' => $request->user()->hasPermission('partners.manage'),
            'summary' => [
                'total' => $centers->count(),
                'verified' => $centers->where('status', 'verified')->count(),
                'pending' => $centers->where('status', 'pending')->count(),
                'candidates' => $centers->sum('candidates'),
            ],
        ]);
    }

    public function storePartner(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'pan' => ['nullable', 'string', 'size:10'], 'gst_udyam' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'], 'phone' => ['required', 'string', 'max:20'],
            'authorized_person' => ['required', 'string', 'max:255'], 'authorized_person_phone' => ['required', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:80'], 'district' => ['nullable', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:1000'],
        ]);
        $id = DB::table('training_partners')->insertGetId([...$data, 'code' => $this->nextCode('training_partners', 'EXO-TP-'), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'training_partner.created', 'training_partner', $id, $data);
        return response()->json(['partner' => DB::table('training_partners')->find($id)], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validatedCenter($request);
        $id = DB::table('training_centers')->insertGetId([...$data, 'code' => $this->nextCode('training_centers', 'EXO-CTR-'), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'training_center.created', 'training_center', $id, $data);
        return response()->json(['center' => DB::table('training_centers')->find($id)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request);
        $center = DB::table('training_centers')->whereNull('deleted_at')->find($id);
        abort_unless($center, 404);
        $data = $this->validatedCenter($request);
        DB::table('training_centers')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->audit($request, 'training_center.updated', 'training_center', $id, $data);
        return response()->json(['center' => DB::table('training_centers')->find($id)]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['status' => ['required', Rule::in(self::STATUSES)], 'review_remarks' => [$request->input('status') === 'rejected' ? 'required' : 'nullable', 'string', 'max:2000']]);
        $center = DB::table('training_centers')->whereNull('deleted_at')->find($id);
        abort_unless($center, 404);
        if ($data['status'] === 'verified') {
            foreach (['name', 'center_type', 'spoc_name', 'spoc_phone', 'spoc_email', 'state', 'district', 'city_block', 'address', 'pincode'] as $key) {
                abort_unless(trim((string) ($center->$key ?? '')), 422, 'Complete the center contact and location profile before approval.');
            }
        }
        $review = [...$data, 'verified_by_user_id' => $data['status'] === 'verified' ? $request->user()->id : null, 'verified_at' => $data['status'] === 'verified' ? now() : null, 'updated_at' => now()];
        DB::transaction(function () use ($request, $id, $data, $review) {
            DB::table('training_centers')->where('id', $id)->update($review);
            $this->audit($request, 'training_center.'.$data['status'], 'training_center', $id, $data);
        });
        return response()->json(['center' => DB::table('training_centers')->find($id)]);
    }

    private function validatedCenter(Request $request): array
    {
        $data = $request->validate([
            'training_partner_id' => ['nullable', 'integer', Rule::exists('training_partners', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'], 'center_type' => ['required', Rule::in(self::TYPES)],
            'project_name' => ['nullable', 'string', 'max:100'], 'spoc_name' => ['required', 'string', 'max:255'],
            'spoc_phone' => ['required', 'string', 'max:20'], 'spoc_email' => ['required', 'email', 'max:255'], 'website' => ['nullable', 'url', 'max:255'],
            'state' => ['required', 'string', 'max:80'], 'district' => ['required', 'string', 'max:80'], 'city_block' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:1000'], 'pincode' => ['required', 'digits:6'],
            'nearby_railway_station' => ['nullable', 'string', 'max:255'], 'nearby_bus_stand' => ['nullable', 'string', 'max:255'],
            'service_radius_km' => ['nullable', 'integer', 'between:1,500'], 'sector' => ['required', 'string', 'max:100'],
            'ssc_course' => ['nullable', 'string', 'max:255'], 'job_role' => ['required', 'string', 'max:255'],
            'minimum_qualification' => ['required', 'string', 'max:100'], 'batch_duration_hours' => ['required', 'integer', 'between:1,10000'],
            'batch_capacity' => ['required', 'integer', 'between:1,1000'], 'placement_commitment_percent' => ['required', 'integer', 'between:0,100'],
            'accreditations' => ['array'], 'accreditations.*' => ['string', Rule::in(['NSQF', 'SSC', 'NSDC', 'DGT'])],
            'infrastructure_evidence' => ['array'], 'infrastructure_evidence.*' => ['string', 'max:255'],
        ]);
        $data['accreditations'] = json_encode($data['accreditations'] ?? []);
        $data['infrastructure_evidence'] = json_encode($data['infrastructure_evidence'] ?? []);
        return $data;
    }

    private function nextCode(string $table, string $prefix): string
    {
        $number = ((int) DB::table($table)->max('id')) + 1;
        return $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function audit(Request $request, string $action, string $type, int $id, array $changes): void
    {
        DB::table('audit_logs')->insert(['actor_user_id' => $request->user()->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'changes' => json_encode($changes), 'ip_address' => $request->ip(), 'created_at' => now()]);
    }
}
