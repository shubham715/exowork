<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:candidate,center,employer,admin'],
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $identifier = trim($data['identifier']);
        if ($data['role'] === 'candidate') {
            $candidate = Candidate::query()->where('email', strtolower($identifier))->orWhere('whatsapp', preg_replace('/\D+/', '', $identifier))->first();
            if (! $candidate || ! $candidate->password || ! Hash::check($data['password'], $candidate->password) || $candidate->profile_status !== 'active') {
                return response()->json(['message' => 'Invalid credentials.'], 422);
            }
            Auth::guard('web')->logout();
            Auth::guard('candidate')->login($candidate, $data['remember'] ?? false);
            $destination = '/candidate/dashboard';
        } else {
            $user = User::query()->where('email', strtolower($identifier))->orWhere('phone', preg_replace('/\D+/', '', $identifier))->first();
            $roleKeys = $data['role'] === 'center' ? ['training_partner'] : ($data['role'] === 'admin' ? ['admin', 'superadmin', 'agent'] : ['employer']);
            $hasRole = $user && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $user->id)->whereIn('roles.key', $roleKeys)->exists();
            if (! $user || $user->status !== 'active' || ! $hasRole || ! Hash::check($data['password'], $user->password)) {
                return response()->json(['message' => 'Invalid credentials.'], 422);
            }
            Auth::guard('candidate')->logout();
            Auth::guard('web')->login($user, $data['remember'] ?? false);
            $user->forceFill(['last_login_at' => now()])->save();
            $destination = $data['role'] === 'center' ? (DB::table('organization_memberships')->join('training_centers', 'training_centers.id', '=', 'organization_memberships.training_center_id')->where('organization_memberships.user_id', $user->id)->where('organization_memberships.status', 'active')->where('training_centers.onboarding_step', '<', 3)->exists() ? '/center/onboarding' : '/center/dashboard') : ($data['role'] === 'employer' ? '/employer/dashboard' : '/admin/dashboard');
        }
        $request->session()->regenerate();

        return response()->json(['destination' => $destination, 'csrf_token' => csrf_token()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        Auth::guard('candidate')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.', 'csrf_token' => csrf_token()]);
    }
}
