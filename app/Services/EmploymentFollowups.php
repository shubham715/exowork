<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EmploymentFollowups
{
    public function create(int $candidate, ?int $application, string $key, string $notes): void
    {
        if (DB::table('candidate_followups')->where('workflow_key', $key)->exists()) return;
        $agent = DB::table('candidates')->where('id', $candidate)->value('assigned_agent_id');
        if (! $agent || ! DB::table('users')->where('id', $agent)->where('status', 'active')->exists()) {
            $agent = DB::table('users')->where('status', 'active')->whereExists(fn ($q) => $q->selectRaw('1')->from('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->whereColumn('user_roles.user_id', 'users.id')->whereIn('roles.key', ['agent', 'admin', 'superadmin']))->orderBy('id')->value('id');
        }
        if ($agent) DB::table('candidate_followups')->insertOrIgnore(['workflow_key' => $key, 'candidate_id' => $candidate, 'application_id' => $application, 'agent_id' => $agent, 'channel' => 'call', 'status' => 'open', 'due_at' => now(), 'notes' => $notes, 'created_at' => now(), 'updated_at' => now()]);
    }
}
