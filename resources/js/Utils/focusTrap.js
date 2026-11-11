const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Keeps Tab / Shift+Tab inside `container` (a dialog). Call it from a
 * keydown handler; it does nothing for other keys.
 */
export const trapTabKey = (event, container) => {
    if (event.key !== 'Tab' || !container) return;

    const items = [...container.querySelectorAll(FOCUSABLE)].filter((element) => element.offsetParent !== null);

    if (items.length === 0) {
        event.preventDefault();
        container.focus();
        return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || active === container || !container.contains(active))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (active === last || !container.contains(active))) {
        event.preventDefault();
        first.focus();
    }
};
