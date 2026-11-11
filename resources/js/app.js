import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import TransitionSplash from './Components/Feedback/TransitionSplash.vue';
import DialogHost from './Components/Feedback/DialogHost.vue';

const appName = 'CARES — CSC Regional Office VIII';

// Boot preloader timing. SHOW_DELAY must match the fade-in delay in
// resources/css/boot.css (.imis-preloader).
const SHOW_DELAY = 120;
const MIN_VISIBLE = 400;
const FONT_WAIT = 1500;

/**
 * Removes the boot preloader in app.blade.php once Vue has rendered the first
 * page and the fonts are in (so text doesn't swap under the fade).
 *
 * The screen fades in only after SHOW_DELAY, so a page that is ready first
 * never shows it: it is just removed. Once it has shown, it stays for
 * MIN_VISIBLE, so it reads as a deliberate hand-off rather than a flicker.
 */
const dismissBootPreloader = async () => {
    const preloader = document.getElementById('boot-preloader');

    if (!preloader) return;

    // The "Opening <system>" card is drawn by the server and re-drawn
    // identically by ImisSso.vue: no hold, no fade.
    if (preloader.hasAttribute('data-instant')) {
        window.requestAnimationFrame(() => preloader.remove());
        return;
    }

    await Promise.race([document.fonts?.ready, new Promise((resolve) => window.setTimeout(resolve, FONT_WAIT))]);

    const shownFor = performance.now() - (window.__bootAt ?? 0) - SHOW_DELAY;

    if (shownFor < 0) {
        preloader.remove();
        return;
    }

    window.setTimeout(() => {
        preloader.classList.add('is-hidden');
        preloader.setAttribute('aria-busy', 'false');
        preloader.addEventListener('transitionend', (event) => event.target === preloader && preloader.remove());
        // Fallback in case transitionend never fires.
        window.setTimeout(() => preloader.remove(), 1000);
    }, Math.max(0, MIN_VISIBLE - shownFor));
};

createInertiaApp({
    title: (title) => (title ? `${title} — CARES` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        return pages[`./Pages/${name}.vue`]();
    },
    setup({ el, App, props, plugin }) {
        // TransitionSplash (sign-in/sign-out) and DialogHost (confirm dialogs,
        // toasts) live beside the page so they survive page swaps, as in IMIS.
        createApp({ render: () => [h(App, props), h(TransitionSplash), h(DialogHost)] })
            .use(plugin)
            .mount(el);

        dismissBootPreloader();
    },
    progress: { color: 'var(--color-accent)', delay: 100 },
});
