<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Support\Security\SessionLimits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out once their sign-in passes the absolute cap. The idle
 * timeout needs nothing here: Laravel already drops a session that has been
 * idle for `session.lifetime`.
 */
class EnforceSessionLimits
{
    public function __construct(private readonly SessionLimits $limits) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        // A session from before the limits existed has no start: begin counting now.
        if (! $this->limits->started($session)) {
            $this->limits->start($session);
        }

        if (! $this->limits->expired($session)) {
            return $next($request);
        }

        AuditLog::record('session.limit_reached', user: $user, request: $request);

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['alive' => false, 'reason' => 'limit'], 401);
        }

        return redirect()->guest(route('login'))
            ->with('status', 'Your session reached its time limit. Please open PSED from IMIS again.');
    }
}
