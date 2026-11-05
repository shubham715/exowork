<?php

namespace App\Http\Controllers;

use App\Services\AccountImpersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function status(Request $request, AccountImpersonation $support)
    {
        $state = $request->session()->get(AccountImpersonation::KEY);
        return response()->json(['can_impersonate' => !$state && $support->authorized(Auth::guard('web')->user()),
            'active' => (bool) $state, 'session' => $state ? ['name' => $state['name'], 'organization' => $state['organization'], 'type' => $state['type'], 'expires_at' => $state['expires_at'], 'expired' => now()->timestamp >= $state['expires_at'], 'read_only' => true] : null])->header('Cache-Control', 'no-store, private');
    }

    public function start(Request $request, AccountImpersonation $support)
    {
        $data = $request->validate(['type' => ['required', 'in:candidate,center,employer'], 'id' => ['required', 'integer', 'min:1']]);
        return response()->json($support->start($request, $data['type'], $data['id']))->header('Cache-Control', 'no-store, private');
    }

    public function stop(Request $request, AccountImpersonation $support)
    {
        return response()->json($support->stop($request))->header('Cache-Control', 'no-store, private');
    }
}
