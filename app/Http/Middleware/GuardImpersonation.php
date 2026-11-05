<?php

namespace App\Http\Middleware;

use App\Services\AccountImpersonation;
use Closure;
use Illuminate\Http\Request;

class GuardImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        $state = $request->session()->get(AccountImpersonation::KEY);
        if (!$state) return $next($request);
        $support = app(AccountImpersonation::class);
        if (!$support->actor($state)) {
            $support->discard($request, $state, 'impersonation.revoked');
            return $request->expectsJson() ? response()->json(['message' => 'Admin access changed. Please sign in again.'], 401) : redirect('/admin225');
        }
        // Return uses only the original server-side actor; no posted actor IDs or redirect URLs are accepted.
        if ($request->is('auth/impersonation', 'auth/impersonation/stop', 'auth/csrf-token')) return $next($request);
        if ($request->is('auth/logout')) {
            $support->discard($request, $state, 'impersonation.signed_out');
            return response()->json(['message' => 'Signed out.', 'csrf_token' => csrf_token()]);
        }
        if (!$support->targetValid($state)) {
            $support->audit($request, $state, 'impersonation.target_revoked');
            $result = $support->stop($request);
            return $request->expectsJson() ? response()->json(['message' => 'Support account access changed. Returning to admin.', 'support_destination' => $result['destination']], 409) : redirect($result['destination']);
        }
        if (now()->timestamp >= $state['expires_at']) {
            $support->audit($request, $state, 'impersonation.expired');
            $result = $support->stop($request);
            return $request->expectsJson() ? response()->json(['message' => 'Support session expired. Returning to admin.', 'support_destination' => $result['destination']], 409) : redirect($result['destination']);
        }
        $prefix = $state['type'] === 'candidate' ? 'candidate' : ($state['type'] === 'center' ? 'center' : 'employer');
        $allowed = $request->is($prefix, $prefix.'/*', $prefix.'-api/*', 'public-api/hero-slides*', 'api/master-data*', 'api/health');
        if (!$allowed || !in_array($request->method(), ['GET', 'HEAD'], true)) {
            $support->audit($request, $state, 'impersonation.blocked', ['method' => $request->method(), 'path' => $request->path()]);
            abort(403, 'View-only support session. Return to the admin panel to make changes.');
        }
        $response = $next($request);
        if ($response->getStatusCode() < 400) $support->audit($request, $state, 'impersonation.viewed', ['method' => $request->method(), 'path' => $request->path()]);
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
