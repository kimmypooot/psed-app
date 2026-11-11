<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Security\SessionLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keep-alive for the idle-timeout countdown. The browser calls it only after
 * real user activity, so an unattended tab still times out. Reaching here
 * already refreshes the session; EnforceSessionLimits answers 401 once the
 * absolute limit has passed, and an idle-expired session never gets past the
 * `auth` middleware.
 */
class SessionHeartbeatController extends Controller
{
    public function __invoke(Request $request, SessionLimits $limits): JsonResponse
    {
        return response()->json([
            'alive' => true,
            'limits' => $limits->forClient($request->session()),
        ]);
    }
}
