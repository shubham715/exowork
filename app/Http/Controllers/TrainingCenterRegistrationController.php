<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TrainingCenterRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_name' => ['nullable', 'string', 'max:255'],
            'center_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'regex:/^\d{10}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'state' => ['required', 'string', 'max:80'],
            'district' => ['required', 'string', 'max:80'],
        ]);

        $data['organization_name'] = $data['organization_name'] ?? $data['center_name'];
        $userId = DB::transaction(function () use ($data) {
            $userId = DB::table('users')->insertGetId([
                'name' => $data['organization_name'], 'email' => strtolower($data['email']), 'phone' => $data['phone'],
                'password' => Hash::make($data['password']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $centerId = DB::table('training_centers')->insertGetId([
                'training_partner_id' => null,
                'code' => 'EXO-CTR-'.str_pad((string) (DB::table('training_centers')->max('id') + 1), 4, '0', STR_PAD_LEFT),
                'name' => $data['center_name'], 'spoc_phone' => $data['phone'], 'spoc_email' => strtolower($data['email']), 'state' => $data['state'], 'district' => $data['district'], 'status' => 'active',
                'onboarding_step' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $roleId = DB::table('roles')->where('key', 'training_partner')->value('id');
            DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);
            DB::table('organization_memberships')->insert(['user_id' => $userId, 'training_partner_id' => null, 'training_center_id' => $centerId, 'membership_role' => 'owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            return $userId;
        });
        Auth::guard('candidate')->logout();
        Auth::guard('web')->loginUsingId($userId);
        $request->session()->regenerate();
        return response()->json(['destination' => '/center/onboarding', 'csrf_token' => csrf_token()], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $center = $this->center($request);
        $partner = DB::table('training_partners')->find($center->training_partner_id);
        return response()->json(['step' => $center->onboarding_step, 'partner' => $partner, 'center' => $center]);
    }

    public function update(Request $request): JsonResponse
    {
        $center = $this->center($request);
        $control = $request->validate([
            'step' => ['required', 'integer', 'between:0,3'],
            'completed_step' => ['required', 'integer', 'between:0,2'],
            'draft' => ['sometimes', 'boolean'],
        ]);
        $completedStep = (int) $control['completed_step'];
        $draft = (bool) ($control['draft'] ?? false);
        abort_unless((int) $control['step'] === $completedStep + ($draft ? 0 : 1), 422, 'Invalid onboarding step.');
        // Onboarding describes the center itself; courses belong to batches and students.
        $required = Rule::requiredIf(! $draft);
        $data = $request->validate([
            'step' => ['required', 'integer', 'between:0,3'],
            'center_type' => [$required, 'nullable', Rule::in(['Individual', 'Private', 'Government', 'NGO', 'ITI', 'Polytechnic', 'College', 'Skill Training Center'])],
            'spoc_name' => [$required, 'nullable', 'string', 'max:255'],
            'spoc_phone' => [$required, 'nullable', 'regex:/^\d{10}$/'],
            'spoc_email' => [$required, 'nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'state' => [$required, 'nullable', 'string', 'max:80'],
            'district' => [$required, 'nullable', 'string', 'max:80'],
            'city_block' => [$required, 'nullable', 'string', 'max:100'],
            'address' => [$required, 'nullable', 'string', 'max:1000'],
            'pincode' => [$required, 'nullable', 'digits:6'],
        ], [], [
            'spoc_name' => 'contact person', 'spoc_phone' => 'contact mobile',
            'spoc_email' => 'contact email', 'city_block' => 'city or town', 'pincode' => 'PIN code',
        ]);
        $step = (int) $data['step']; unset($data['step']);
        DB::transaction(function () use ($center, $data, $step) {
            $partnerLocation = ['state' => $data['state'] ?? $center->state, 'district' => $data['district'] ?? $center->district];
            $data['onboarding_step'] = max($center->onboarding_step, $step);
            if ($data['onboarding_step'] >= 3) $data['onboarding_step'] = 3;
            DB::table('training_centers')->where('id', $center->id)->update([...$data, ...$partnerLocation, 'updated_at' => now()]);
        });
        return response()->json(['step' => max($center->onboarding_step, $step), 'saved' => true]);
    }

    private function center(Request $request): object
    {
        $center = DB::table('organization_memberships as m')->join('training_centers as c', 'c.id', '=', 'm.training_center_id')
            ->where('m.user_id', $request->user()->id)->where('m.status', 'active')->whereNull('c.deleted_at')->select('c.*')->first();
        abort_unless($center, 404);
        return $center;
    }
}
