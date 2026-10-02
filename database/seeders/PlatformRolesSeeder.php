<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlatformRolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'candidate' => 'Candidate',
            'training_partner' => 'Training partner',
            'employer' => 'Employer',
            'agent' => 'Placement agent',
            'admin' => 'Administrator',
            'superadmin' => 'Super administrator',
        ];
        $permissions = [
            'candidates.view', 'candidates.create', 'candidates.update', 'candidates.assign',
            'partners.view', 'partners.manage', 'batches.manage',
            'employers.view', 'employers.manage', 'jobs.view', 'jobs.manage',
            'applications.manage', 'interviews.manage', 'followups.manage',
            'placements.manage', 'reports.view', 'users.manage', 'permissions.manage', 'masters.manage',
        ];
        foreach ($roles as $key => $name) {
            DB::table('roles')->updateOrInsert(['key' => $key], ['name' => $name, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ($permissions as $key) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['updated_at' => now(), 'created_at' => now()]);
        }
        $grants = [
            'training_partner' => ['candidates.view', 'candidates.create', 'candidates.update', 'batches.manage', 'jobs.view', 'interviews.manage', 'placements.manage'],
            'employer' => ['candidates.view', 'jobs.view', 'jobs.manage', 'interviews.manage', 'placements.manage'],
            'agent' => ['candidates.view', 'candidates.update', 'jobs.view', 'applications.manage', 'interviews.manage', 'followups.manage', 'placements.manage'],
            'admin' => array_diff($permissions, ['permissions.manage']),
            'superadmin' => $permissions,
        ];
        foreach ($grants as $role => $keys) {
            $roleId = DB::table('roles')->where('key', $role)->value('id');
            foreach ($keys as $key) {
                DB::table('role_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => DB::table('permissions')->where('key', $key)->value('id'),
                ]);
            }
        }
    }
}
