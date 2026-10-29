<?php
namespace App\Services;

use App\Models\{Candidate, User};
use Illuminate\Support\Facades\DB;

class PhoneLoginAccount
{
    public function error(string $phone, string $role): ?array
    {
        $candidate = Candidate::where('whatsapp', $phone)->first();
        $users = User::where('phone', $phone)->get();
        $keys = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $users->pluck('id'))->pluck('roles.key');
        $roles = [];
        if ($candidate) $roles['candidate'] = 'Candidate';
        if ($keys->contains('employer')) $roles['employer'] = 'Employer';
        if ($keys->contains('training_partner')) $roles['center'] = 'Training Center';
        if (isset($roles[$role])) return null;
        if ($roles) return ['message'=>'You are already registered as '.implode(' and ', $roles).'. Please select the matching account type to log in.', 'code'=>'ACCOUNT_ROLE_MISMATCH'];
        return ['message'=>'Sorry, we could not find your account. If you are new to EXOWORK, please register here.', 'code'=>'ACCOUNT_NOT_FOUND', 'registration_url'=>'/register/'.($role === 'center' ? 'training-center' : $role)];
    }
}
