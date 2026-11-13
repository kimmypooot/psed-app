<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a Field Office employee who works as more than one office (a Director II
 * who heads several) act as one at a time.
 *
 * For the rest of the request the user's `field_office_id` is the office they chose,
 * so every policy, gate and query that already reads `$user->field_office_id`
 * filters and writes as that office, in SQL, with no change anywhere else. The
 * choice lives in the session and is checked against the offices IMIS gave them
 * on every request; it is never saved to the account.
 *
 * Until they have chosen, they are sent to the chooser. A user with one office
 * (almost everyone) passes straight through at the cost of one query.
 */
class ApplyActiveOffice
{
    public const SESSION_KEY = 'active_office_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $offices = $user->workOfficeIds();

        if (count($offices) < 2) {
            return $next($request);
        }

        $chosen = $request->session()->get(self::SESSION_KEY);

        if (! in_array($chosen, $offices, true)) {
            $request->session()->forget(self::SESSION_KEY);

            if ($request->routeIs('office.*', 'logout', 'sso*', 'session.heartbeat')) {
                return $next($request);
            }

            return $request->expectsJson()
                ? response()->json(['message' => 'Choose the office you are working as first.'], 409)
                : redirect()->route('office.choose');
        }

        $user->homeOfficeId ??= $user->field_office_id;
        // Synced as "original" so a stray $user->save() can never write the chosen office to the account.
        $user->setAttribute('field_office_id', $chosen)->syncOriginalAttribute('field_office_id');
        $user->unsetRelation('fieldOffice');

        return $next($request);
    }
}
