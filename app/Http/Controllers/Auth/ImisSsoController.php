<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use App\Support\Security\SessionLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Signs staff in from the IMIS portal, the only way into CARES. IMIS sends
 * the browser here with a one-time `?sso_token=`; this redeems it
 * server-to-server at IMIS, which says who it was issued for and which unit
 * they belong to. The unit decides the role: PSED employees are administrators,
 * Field and Satellite Office employees work as their own office.
 *
 * The account here is a stub linked by `imis_user_id`: created on first
 * sign-in and rewritten from IMIS on every sign-in, so a role changed or
 * removed in IMIS takes effect the next time the person opens CARES.
 */
class ImisSsoController extends Controller
{
    /**
     * Where IMIS sends the browser. Shows the "signing you in" splash at once;
     * the page posts the token back to store() while it stays up.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $token = $request->query('sso_token');

        if (! config('services.imis.sso_url') || ! is_string($token) || $token === '') {
            return $this->refuse('Single sign-on is not available. Please open CARES from IMIS.');
        }

        return Inertia::render('Auth/ImisSso', ['token' => $token]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $url = config('services.imis.sso_url');
        $token = $request->input('sso_token');

        if (! $url || ! is_string($token) || $token === '') {
            return $this->refuse('Single sign-on is not available. Please open CARES from IMIS.');
        }

        try {
            $response = Http::asForm()->timeout(10)->post($url, [
                'token' => $token,
                'audience' => config('services.imis.audience'),
            ]);
        } catch (Throwable $e) {
            Log::warning('IMIS sign-in failed', ['message' => $e->getMessage()]);

            return $this->refuse('IMIS could not be reached. Please try again in a moment.');
        }

        $imis = $response->successful() && $response->json('success') === true ? $response->json('user') : null;

        if (! is_array($imis) || ! is_int($imis['id'] ?? null) || ! is_string($imis['username'] ?? null)) {
            return $this->refuse('Your IMIS sign-in could not be used here. Please open CARES from IMIS again.');
        }

        // Who gets in is decided by the person's IMIS unit, not by a per-person grant:
        // PSED employees are the administrators, a Field or Satellite Office's employees
        // work as that office, and no one else belongs here.
        $unit = trim((string) ($imis['office_code'] ?? ''));
        $office = null;

        if ($unit !== '' && $unit === config('psed.imis.psed_unit_code')) {
            $role = UserRole::Admin;
        } else {
            $office = $unit !== '' ? FieldOffice::where('imis_code', $unit)->first() : null;

            if ($office === null) {
                $isOfficeStaff = in_array($imis['office_type'] ?? null, ['field_office', 'satellite_office'], true);

                return $this->refuse($isOfficeStaff
                    ? 'Your IMIS office is not set up in CARES as a Field Office. Please contact PSED.'
                    : 'CARES is for PSED and Field Office staff, and your IMIS unit is neither. Please contact PSED if you need access.');
            }

            $role = UserRole::FieldOffice;
        }

        $user = User::where('imis_user_id', $imis['id'])->first() ?? new User;

        if (User::where('username', $imis['username'])->whereKeyNot($user->id)->exists()) {
            return $this->refuse('Your account clashes with an existing CARES account. Please contact your administrator.');
        }

        $name = trim(trim((string) ($imis['fname'] ?? '')).' '.trim((string) ($imis['lname'] ?? '')));

        $user->fill([
            'imis_user_id' => $imis['id'],
            'username' => $imis['username'],
            'name' => $name ?: $imis['username'],
            'email' => ($imis['email'] ?? '') ?: null,
            'position' => mb_substr(trim((string) ($imis['position'] ?? '')), 0, 255) ?: null,
            'role' => $role,
            'field_office_id' => $office?->id,
            'photo_url' => $this->photoUrl($imis),
            'last_login_at' => now(),
        ])->save();

        AuditLog::record('login', $user, after: ['method' => 'imis', 'role' => $role->value], user: $user, request: $request);

        Auth::login($user);
        $request->session()->regenerate();
        app(SessionLimits::class)->start($request->session());
        $this->rememberImisSession($imis, $request->session()->getId());

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Ties this session to IMIS's, so signing out of IMIS can end it
     * (ImisSsoLogoutController). Only a hash of IMIS's secret `sid` is kept.
     * IMIS sends no `sid` when the token was not issued from an IMIS session.
     *
     * @param  array<string, mixed>  $imis
     */
    private function rememberImisSession(array $imis, string $sessionId): void
    {
        $sid = $imis['sid'] ?? null;

        if (! is_string($sid) || strlen($sid) !== 64) {
            return;
        }

        // Housekeeping: a link older than the longest a session can live points at nothing.
        DB::table('imis_sessions')
            ->where('created_at', '<', now()->subMinutes((int) config('session.absolute_lifetime') + 60))
            ->delete();

        DB::table('imis_sessions')->updateOrInsert(
            ['sid_hash' => hash('sha256', $sid)],
            ['session_id' => $sessionId, 'created_at' => now()],
        );
    }

    /**
     * The person's IMIS photo: an http(s) URL that fits the column, or null.
     *
     * @param  array<string, mixed>  $imis
     */
    private function photoUrl(array $imis): ?string
    {
        $url = $imis['photo_url'] ?? null;

        return is_string($url) && strlen($url) <= 255 && preg_match('#^https?://#i', $url) ? $url : null;
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
