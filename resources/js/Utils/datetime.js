/**
 * Timestamp helpers for `<input type="datetime-local">`, which has no
 * timezone. The server sends/stores ISO-8601 instants (UTC); these convert
 * to and from the browser's local wall-clock time so an admin in Manila
 * picking "8:00 AM" means 8:00 AM Manila time, not 8:00 AM UTC.
 */

const pad = (value) => String(value).padStart(2, '0');

/** ISO-8601 string (or null) → "YYYY-MM-DDTHH:mm" in local time, for the input. */
export function toLocalInput(iso) {
    if (!iso) return '';

    const date = new Date(iso);

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Input value (local wall-clock time) → ISO-8601 instant, or null when empty. */
export function fromLocalInput(value) {
    return value ? new Date(value).toISOString() : null;
}

/** ISO-8601 string → human-readable local date/time. */
export function formatDateTime(iso) {
    if (!iso) return '';

    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/** ISO-8601 string → human-readable local date only. */
export function formatDate(iso) {
    if (!iso) return '';

    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'long' });
}

/**
 * A calendar date ("2020-05-01", e.g. a birthday) → human-readable, read
 * as that day wherever the viewer is. new Date("2020-05-01") would be UTC
 * midnight, which shows as the previous day in timezones behind UTC.
 */
export function formatCalendarDate(value) {
    if (!value) return '';

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { dateStyle: 'long' });
}

/** ISO-8601 string → "just now", "5 min ago", "3 h ago", "Yesterday", or the date once it is older. */
export function timeAgo(iso) {
    if (!iso) return '';

    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 60 * 24) return `${Math.round(minutes / 60)} h ago`;
    if (minutes < 60 * 48) return 'Yesterday';
    if (minutes < 60 * 24 * 7) return `${Math.round(minutes / (60 * 24))} days ago`;

    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
}

/** ISO-8601 string → which group a list should file it under: Today, Yesterday, This week or Earlier. */
export function dayGroup(iso) {
    const date = new Date(iso);
    const startOfToday = new Date();
    startOfToday.setHours(0, 0, 0, 0);
    const days = Math.floor((startOfToday.getTime() - date.getTime()) / 86400000) + 1;

    if (date >= startOfToday) return 'Today';
    if (days <= 1) return 'Yesterday';
    if (days <= 7) return 'This week';

    return 'Earlier';
}

/** ISO-8601 string → whole days since then (0 for today). */
export function daysSince(iso) {
    if (!iso) return 0;

    return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86400000));
}
