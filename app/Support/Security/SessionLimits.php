<?php

namespace App\Support\Security;

use Illuminate\Contracts\Session\Session;

/**
 * The session limits IMIS applies, kept the same here: an idle timeout
 * (Laravel's own `session.lifetime`) and an absolute cap per sign-in
 * (`session.absolute_lifetime`), enforced by EnforceSessionLimits and counted
 * down in the browser by useSessionTimeout. PSED has no "remember me", so
 * both always apply.
 */
class SessionLimits
{
    private const STARTED = 'auth.started_at';

    /**
     * Starts the clock of a new sign-in.
     */
    public function start(Session $session): void
    {
        $session->put(self::STARTED, now()->getTimestamp());
    }

    public function started(Session $session): bool
    {
        return $session->has(self::STARTED);
    }

    public function idleSeconds(): int
    {
        return (int) config('session.lifetime') * 60;
    }

    /**
     * Seconds left before the absolute cap ends this sign-in.
     */
    public function absoluteRemaining(Session $session): int
    {
        $started = (int) $session->get(self::STARTED, now()->getTimestamp());

        return max(0, $started + (int) config('session.absolute_lifetime') * 60 - now()->getTimestamp());
    }

    public function expired(Session $session): bool
    {
        return $this->started($session) && $this->absoluteRemaining($session) === 0;
    }

    /**
     * What the browser needs to warn before a timeout. Durations, not
     * timestamps, so the client's clock never has to agree with the server's.
     *
     * @return array{idle_seconds: int, absolute_remaining: int, warning_seconds: int}
     */
    public function forClient(Session $session): array
    {
        return [
            'idle_seconds' => $this->idleSeconds(),
            'absolute_remaining' => $this->absoluteRemaining($session),
            'warning_seconds' => (int) config('session.idle_warning') * 60,
        ];
    }
}
