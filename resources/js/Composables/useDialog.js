import { reactive, readonly } from 'vue';

/**
 * Promise-based alerts, confirmations, and toasts — the legacy IMIS's
 * SweetAlert2 dialogs ("Are you sure?", "Saved!", "Deleted!") rebuilt
 * without the dependency. Rendered once by DialogHost at the app root.
 *
 *   if (await confirm({ title: 'Deactivate user?', text: '…', tone: 'danger' })) { … }
 *   toast.success('Saved!')
 */
const state = reactive({
    dialog: null,
    toasts: [],
});

let nextToastId = 1;

const open = (options, withCancel) =>
    new Promise((resolve) => {
        state.dialog = {
            icon: options.icon ?? (withCancel ? 'warning' : 'info'),
            title: options.title,
            text: options.text ?? '',
            confirmText: options.confirmText ?? (withCancel ? 'Confirm' : 'OK'),
            cancelText: options.cancelText ?? 'Cancel',
            tone: options.tone ?? 'primary',
            showCancel: withCancel,
            resolve,
        };
    });

/**
 * Asks a yes/no question. Resolves true on confirm, false on cancel,
 * Escape, or backdrop click.
 */
export const confirm = (options) => open(options, true);

/**
 * A one-button message (e.g. an error the user must acknowledge).
 */
export const alert = (options) => open(options, false);

const settle = (result) => {
    const dialog = state.dialog;
    state.dialog = null;
    dialog?.resolve(result);
};

// Toasts auto-dismiss, but hovering one holds it open so it can be read
// (and its timer restarts when the pointer leaves).
const timers = new Map();
const MAX_TOASTS = 4;

const startTimer = (id) => {
    const item = state.toasts.find((candidate) => candidate.id === id);
    if (!item?.timeout) return;
    window.clearTimeout(timers.get(id));
    timers.set(id, window.setTimeout(() => dismissToast(id), item.timeout));
};

const push = (type, message, timeout) => {
    const id = nextToastId++;
    // A burst of toasts never stacks past the screen: the oldest makes room.
    while (state.toasts.length >= MAX_TOASTS) dismissToast(state.toasts[0].id);
    state.toasts.push({ id, type, message, timeout });
    startTimer(id);
};

export const dismissToast = (id) => {
    window.clearTimeout(timers.get(id));
    timers.delete(id);
    state.toasts = state.toasts.filter((item) => item.id !== id);
};

export const holdToast = (id) => {
    window.clearTimeout(timers.get(id));
    timers.delete(id);
};

export const releaseToast = (id) => startTimer(id);

export const toast = {
    success: (message) => push('success', message, 3000),
    info: (message) => push('info', message, 4000),
    warning: (message) => push('warning', message, 6000),
    // Errors stay until dismissed, so they can't be missed.
    error: (message) => push('error', message, null),
};

export function useDialog() {
    return { state: readonly(state), confirm, alert, toast, settle, dismissToast, holdToast, releaseToast };
}
