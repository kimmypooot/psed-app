// Plain fetch for the pages that keep their own state (uploads, live progress,
// the review) instead of reloading. Laravel's XSRF cookie is sent back as the header it checks.
const xsrfToken = () => {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
};

/**
 * Sends a request and answers { ok, status, data } (data is null when the
 * answer was not JSON). `body` is a FormData for uploads; `headers` are added
 * to the standard ones.
 */
export const request = async (method, url, body = undefined, headers = {}) => {
    const response = await fetch(url, {
        method,
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', ...headers },
    });

    let data = null;

    try {
        data = await response.json();
    } catch {
        // Not JSON (a gateway error page, say); the status still tells the story.
    }

    return { ok: response.ok, status: response.status, data };
};

/**
 * Like request(), but reports how much of the upload has been sent, which fetch cannot do:
 * `onUploadProgress(percent)` is called with 0-100 as the body goes up. Answers { ok, status, data }.
 */
export const requestWithProgress = (method, url, body, onUploadProgress) =>
    new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) onUploadProgress?.(Math.round((event.loaded / event.total) * 100));
        };

        const done = () => {
            let data = null;

            try {
                data = JSON.parse(xhr.responseText);
            } catch {
                // Not JSON (a gateway error page, say); the status still tells the story.
            }

            resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, data });
        };

        xhr.onload = done;
        xhr.onerror = () => resolve({ ok: false, status: 0, data: { message: 'The connection was lost. Check your network and try again.' } });
        xhr.send(body);
    });

/**
 * Sends `payload` as JSON.
 */
export const requestJson = (method, url, payload) => request(method, url, JSON.stringify(payload), { 'Content-Type': 'application/json' });

/**
 * The first message of a failed answer: a validation error, the server's own
 * message, or a generic line.
 */
export const errorMessage = (result) => {
    const errors = result.data?.errors;

    return (errors && Object.values(errors).flat()[0]) || result.data?.message || 'Something went wrong. Please try again.';
};
