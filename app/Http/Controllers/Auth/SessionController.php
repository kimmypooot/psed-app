<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CARES has no sign-in form: staff open it from IMIS. The "login" page only
 * says so and links back to IMIS, and is where refused sign-ins land.
 */
class SessionController extends Controller
{
    public function create(Request $request): Response
    {
        // ?reason=idle comes from the browser's idle countdown, after the
        // server has already dropped the idle session.
        if ($request->query('reason') === 'idle' && ! $request->session()->has('status')) {
            $request->session()->now('status', 'You were signed out after a period of inactivity.');
        }

        return Inertia::render('Auth/SignIn', [
            'imisUrl' => config('services.imis.portal_url'),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuditLog::record('logout', $request->user());

        // Signing out of CARES ends its link to the IMIS session (the person stays signed in to IMIS).
        DB::table('imis_sessions')->where('session_id', $request->session()->getId())->delete();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have signed out of CARES.');
    }
}
