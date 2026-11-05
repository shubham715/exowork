<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountImpersonation
{
    public const KEY = 'support_impersonation';

    public function authorized(?User $user): bool
    {
        return $user && $user->status === 'active' && $user->hasPermission('users.manage')
            && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $user->id)->whereIn('roles.key', ['admin', 'superadmin'])->exists();
    }

    private function domainAllowed(User $user, string $type): bool
    {
        if (!$user->hasPermission('candidates.view')) return false;
        return match ($type) {
            'candidate' => $user->hasPermission('candidates.view'),
            'center' => $user->hasPermission('partners.view') || $user->hasPermission('partners.manage'),
            'employer' => $user->hasPermission('employers.view') || $user->hasPermission('employers.manage'),
        };
    }

    public function actor(array $state): ?User
    {
        $actor = User::find($state['actor_id']);
        return $this->authorized($actor) && $this->domainAllowed($actor, $state['type']) && hash_equals($state['actor_password'], hash('sha256', $actor->password)) ? $actor : null;
    }

    public function start(Request $request, string $type, int $id): array
    {
        abort_if($request->session()->has(self::KEY), 403, 'Return to admin before switching accounts.');
        $actor = Auth::guard('web')->user();
        abort_unless($this->authorized($actor), 403);
        abort_unless($this->domainAllowed($actor, $type), 403);
        $authenticated = (int) $request->session()->get('admin.authenticated_at', 0);
        abort_unless($authenticated > 0 && now()->timestamp - $authenticated <= 10800, 403, 'Please sign out and sign in to admin again before using Log in as.');
        [$target, $guard, $destination, $organization] = $this->resolve($type, $id);
        $state = ['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'actor_password' => hash('sha256', $actor->password),
            'authenticated_at' => $authenticated, 'target_id' => $target->id, 'subject_id' => $id, 'type' => $type,
            'guard' => $guard, 'name' => $target->name ?? trim($target->first_name.' '.$target->last_name),
            'organization' => $organization, 'expires_at' => now()->addMinutes(30)->timestamp];
        $this->audit($request, $state, 'impersonation.started');
        $this->clearGuards();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard($guard)->login($target, false);
        $request->session()->put(self::KEY, $state);
        $request->session()->regenerate();
        return ['destination' => $destination, 'csrf_token' => csrf_token()];
    }

    private function resolve(string $type, int $id): array
    {
        if ($type === 'candidate') {
            $target = Candidate::whereNull('deleted_at')->findOrFail($id);
            abort_unless($target->profile_status === 'active', 422, 'This candidate account is inactive.');
            return [$target, 'candidate', '/candidate/dashboard', null];
        }
        $table = $type === 'employer' ? 'employers' : 'training_centers';
        $foreign = $type === 'employer' ? 'employer_id' : 'training_center_id';
        $role = $type === 'employer' ? 'employer' : 'training_partner';
        $organization = DB::table($table)->whereNull('deleted_at')->find($id);
        abort_unless($organization, 404);
        abort_if($organization->status === 'inactive', 422, 'This organization is inactive.');
        $members = DB::table('organization_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.'.$foreign, $id)->where('m.status', 'active')->where('u.status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereColumn('ur.user_id', 'u.id')->where('r.key', $role))
            ->get(['u.id', 'm.membership_role']);
        $owners = $members->where('membership_role', 'owner')->unique('id');
        $eligible = $owners->isNotEmpty() ? $owners : $members->unique('id');
        abort_unless($eligible->count() === 1, 422, 'A unique active owner account is required to log in as this organization.');
        $target = User::findOrFail($eligible->first()->id);
        abort_if($target->hasPermission('users.manage') || DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $target->id)->whereIn('roles.key', ['admin', 'superadmin', 'agent'])->exists(), 403, 'Privileged accounts cannot be impersonated.');
        // Existing workspaces choose the first membership. Refuse ambiguous accounts rather than enter a different organization.
        abort_unless(DB::table('organization_memberships')->where('user_id', $target->id)->where('status', 'active')->whereNotNull($foreign)->count() === 1, 422, 'This account has multiple workspaces and cannot be impersonated safely.');
        return [$target, 'web', $type === 'employer' ? '/employer/dashboard' : '/center/dashboard', $organization->name];
    }

    public function targetValid(array $state): bool
    {
        $target = Auth::guard($state['guard'])->user();
        if (!$target || $target->id !== $state['target_id']) return false;
        if ($state['type'] === 'candidate') return $target->profile_status === 'active' && !$target->deleted_at;
        if ($target->status !== 'active' || $target->hasPermission('users.manage')) return false;
        if (!DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $target->id)->where('roles.key', $state['type'] === 'employer' ? 'employer' : 'training_partner')->exists()) return false;
        $foreign = $state['type'] === 'employer' ? 'employer_id' : 'training_center_id';
        $table = $state['type'] === 'employer' ? 'employers' : 'training_centers';
        return DB::table($table)->where('id', $state['subject_id'])->whereNull('deleted_at')->where('status', '!=', 'inactive')->exists()
            && DB::table('organization_memberships')->where('user_id', $target->id)->where($foreign, $state['subject_id'])->where('status', 'active')->exists()
            && DB::table('organization_memberships')->where('user_id', $target->id)->whereNotNull($foreign)->where('status', 'active')->count() === 1;
    }

    public function stop(Request $request): array
    {
        $state = $request->session()->get(self::KEY);
        abort_unless($state, 409, 'No support session is active.');
        $actor = $this->actor($state);
        if (!$actor) {
            $this->discard($request, $state, 'impersonation.revoked');
            abort(403, 'Admin access changed. Please sign in again.');
        }
        $this->audit($request, $state, 'impersonation.ended');
        $this->clearGuards();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard('web')->login($actor, false);
        $request->session()->put('admin.authenticated_at', $state['authenticated_at']);
        $request->session()->regenerate();
        return ['destination' => '/admin/'.match ($state['type']) { 'candidate' => 'candidates', 'center' => 'centers', default => 'employers' }, 'csrf_token' => csrf_token()];
    }

    public function discard(Request $request, array $state, string $event): void
    {
        $this->audit($request, $state, $event);
        $this->clearGuards();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function clearGuards(): void
    {
        Auth::guard('web')->logoutCurrentDevice();
        Auth::guard('candidate')->logoutCurrentDevice();
    }

    public function audit(Request $request, array $state, string $event, array $details = []): void
    {
        DB::table('audit_logs')->insert(['actor_user_id' => User::whereKey($state['actor_id'])->exists() ? $state['actor_id'] : null, 'action' => $event, 'subject_type' => $state['type'], 'subject_id' => $state['subject_id'],
            'changes' => json_encode(['support_session' => $state['id'], 'original_admin_id' => $state['actor_id'], 'target_user_id' => $state['target_id'], 'mode' => 'read_only', ...$details]), 'ip_address' => $request->ip(), 'created_at' => now()]);
    }
}
