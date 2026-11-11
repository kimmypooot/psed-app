import { reactive, readonly } from 'vue';

/**
 * The branded full-screen splash shown between signing in/out and the next
 * page — the legacy IMISTransition, rebuilt. State is module-level and the
 * splash is rendered once at the app root (app.js), so it stays on screen
 * while Inertia swaps the login page for the dashboard (or back).
 */
const state = reactive({
    visible: false,
    title: '',
    // A small line above the title, e.g. "Opening" over a system's name.
    lead: '',
    subtitle: '',
    name: '',
    note: '',
    // Skip the fade-in: used when the splash must cover a page that has
    // just been rendered underneath it (after signing in), so that page
    // never shows through the fade.
    instant: false,
});

let hideTimer = null;

const show = ({ title, lead = '', subtitle = '', name = '', note = '', instant = false }) => {
    window.clearTimeout(hideTimer);
    Object.assign(state, { visible: true, title, lead, subtitle, name, note, instant });
};

const hide = (delay = 0) => {
    window.clearTimeout(hideTimer);
    hideTimer = window.setTimeout(() => (state.visible = false), delay);
};

export const firstName = (name) => (name || '').trim().split(/\s+/)[0] || '';

export function useSplash() {
    return { splash: readonly(state), show, hide };
}
