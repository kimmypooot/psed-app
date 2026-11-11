<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Where IMIS says a person has signed out of IMIS (docs/sso-logout.md in
 * IMIS). Server-to-server, so there is no cookie or CSRF token: the `sid`
 * IMIS gave at sign-in is the only credential. It ends the CARES session
 * that sign-in started; an unknown or already-ended `sid` is not an error.
 *
 * Request:  POST sid=<64 characters>
 * Response: 200 {success: true}
 */
class ImisSsoLogoutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $sid = $request->input('sid');

        if (is_string($sid) && strlen($sid) === 64) {
            $link = DB::table('imis_sessions')->where('sid_hash', hash('sha256', $sid));
            $sessionId = $link->value('session_id');

            if ($sessionId !== null) {
                Session::getHandler()->destroy($sessionId);
                $link->delete();
            }
        }

        return response()->json(['success' => true]);
    }
}
