<?php

namespace App\Http\Middleware;

use App\Services\CommunicationSettings;
use Closure;
use Illuminate\Http\Request;

class ApplyCommunicationSettings
{
    public function handle(Request $request, Closure $next)
    {
        app(CommunicationSettings::class)->apply();

        return $next($request);
    }
}
