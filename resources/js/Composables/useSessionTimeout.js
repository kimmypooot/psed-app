import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * The idle-session manager, as in IMIS: counts down the server's idle timeout
 * and absolute sign-in limit (shared as `sessionLimits` by
 * HandleInertiaRequests), keeps the session alive while the user is
 * actually active, and warns before either limit is reached.
 *
 * Only real input counts as activity. While there is some, a heartbeat
 * goes to the server at most once a minute; an untouched tab sends
 * nothing, so it times out. Activity and server contact are shared
 * between tabs through localStorage, so a busy tab keeps the quiet ones
 * from warning.
 */
const HEARTBEAT_EVERY = 60_000;
const ACTIVITY_EVENTS = ['mousedown', 'keydown', 'scroll', 'touchstart', 'mousemove'];
const ACTIVITY_KEY = 'psed.session-activity';
const TOUCH_KEY = 'psed.session-touch';

const readShared = (key) => {
    try {
        return Number(window.localStorage.getItem(key)) || 0;
    } catch {
        return 0;
    }
};

const writeShared = (key, value) => {
    try {
        window.localStorage.setItem(key, String(value));
    } catch {
        // Storage unavailable — this tab still keeps its own count.
    }
};

const xsrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

export function useSessionTimeout(limits) {
    const now = ref(Date.now());
    // When the user last did something, and when the server last saw a request.
    let lastActivity = Date.now();
    let lastTouch = Date.now();
    let absoluteDeadline = null;
    let timer = null;
    let pinging = false;
    let ended = false;
    const absoluteAcknowledged = ref(false);

    const sync = () => {
        lastActivity = Math.max(lastActivity, readShared(ACTIVITY_KEY));
        lastTouch = Math.max(lastTouch, readShared(TOUCH_KEY));
    };

    const setLimits = (value) => {
        // A couple of seconds' slack, so the reload at zero lands after the server's limit, not before.
        absoluteDeadline = value ? Date.now() + value.absolute_remaining * 1000 + 2000 : null;
    };

    const idleRemaining = computed(() => {
        if (!limits.value) return Infinity;

        return Math.max(0, Math.ceil((lastTouch + limits.value.idle_seconds * 1000 - now.value) / 1000));
    });

    const absoluteRemaining = computed(() =>
        absoluteDeadline === null ? Infinity : Math.max(0, Math.ceil((absoluteDeadline - now.value) / 1000)),
    );

    const warning = computed(() => {
        if (!limits.value || ended) return null;

        const within = limits.value.warning_seconds;

        // The absolute limit can't be extended, so it wins once it's the nearer one.
        if (absoluteRemaining.value <= within && absoluteRemaining.value <= idleRemaining.value) {
            return absoluteAcknowledged.value ? null : { kind: 'absolute', seconds: absoluteRemaining.value };
        }

        return idleRemaining.value <= within ? { kind: 'idle', seconds: idleRemaining.value } : null;
    });

    const touched = () => {
        lastTouch = Date.now();
        writeShared(TOUCH_KEY, lastTouch);
    };

    const end = (reason) => {
        if (ended) return;
        ended = true;
        window.clearInterval(timer);

        // An idle session is already gone on the server; a limit-ended one
        // is signed out (with its own message) by the next request.
        if (reason === 'idle') {
            router.visit('/login?reason=idle', { replace: true });
        } else {
            router.reload();
        }
    };

    const heartbeat = async () => {
        if (pinging || ended) return;
        pinging = true;

        try {
            const response = await fetch('/session/heartbeat', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });

            if (response.status === 401 || response.status === 419) {
                const body = await response.json().catch(() => ({}));
                end(body.reason === 'limit' ? 'limit' : 'idle');

                return;
            }

            if (response.ok) {
                touched();
                setLimits((await response.json()).limits);
            }
        } catch {
            // Offline or a transient error — the next tick tries again.
        } finally {
            pinging = false;
        }
    };

    let lastRecorded = 0;
    const onActivity = () => {
        const time = Date.now();
        // mousemove fires constantly; a few seconds' resolution is plenty.
        if (time - lastRecorded < 5_000) return;
        lastRecorded = time;
        lastActivity = time;
        writeShared(ACTIVITY_KEY, time);
    };

    const tick = () => {
        sync();
        now.value = Date.now();

        if (!limits.value || ended) return;

        if (absoluteRemaining.value === 0) return end('limit');
        if (idleRemaining.value === 0) return end('idle');

        // While warning, respond to the first sign of life right away.
        const due = warning.value?.kind === 'idle' || now.value - lastTouch >= HEARTBEAT_EVERY;
        if (lastActivity > lastTouch && due) heartbeat();
    };

    const stayActive = () => {
        lastActivity = Date.now();
        writeShared(ACTIVITY_KEY, lastActivity);
        heartbeat();
    };

    const acknowledgeAbsolute = () => {
        absoluteAcknowledged.value = true;
    };

    // Every Inertia request reaches the server too, refreshing the session.
    const removeFinishListener = router.on('finish', touched);

    watch(limits, setLimits, { immediate: true });

    onMounted(() => {
        ACTIVITY_EVENTS.forEach((name) => window.addEventListener(name, onActivity, { passive: true }));
        timer = window.setInterval(tick, 1000);
    });

    onBeforeUnmount(() => {
        ACTIVITY_EVENTS.forEach((name) => window.removeEventListener(name, onActivity));
        window.clearInterval(timer);
        removeFinishListener();
    });

    return { warning, stayActive, acknowledgeAbsolute };
}
