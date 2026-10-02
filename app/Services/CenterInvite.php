<?php
namespace App\Services;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class CenterInvite
{
    public static function resolve(string $token): object
    {
        try { $id = Crypt::decryptString($token); }
        catch (\Throwable $e) { throw ValidationException::withMessages(['invite_token' => 'This invitation is invalid.']); }
        $center = DB::table('training_centers')->leftJoin('training_partners', 'training_partners.id', '=', 'training_centers.training_partner_id')
            ->where('training_centers.id', $id)->whereNull('training_centers.deleted_at')
            ->select('training_centers.id', 'training_centers.name', 'training_centers.training_partner_id', 'training_partners.name as partner_name')->first();
        if (!$center) throw ValidationException::withMessages(['invite_token' => 'This training center is no longer accepting registrations.']);
        return $center;
    }
}
