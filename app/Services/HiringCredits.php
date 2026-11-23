<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HiringCredits
{
    public static function defaultAllocation(): int
    {
        return (int) (DB::table('hiring_settings')->where('id', 1)->value('default_profile_credits') ?? 100);
    }

    public static function balance(object $job): int
    {
        return $job->profile_credits - $job->profile_credits_used;
    }
}
