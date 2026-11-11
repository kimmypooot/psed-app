<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from '../Components/UI/Icon.vue';
import SidebarNav from '../Components/Navigation/SidebarNav.vue';
import UserMenu from '../Components/Navigation/UserMenu.vue';
import AppFooter from '../Components/Navigation/AppFooter.vue';
import ModuleSearch from '../Components/Navigation/ModuleSearch.vue';
import AboutDialog from '../Components/Navigation/AboutDialog.vue';
import { useBranding } from '../Composables/useBranding';
import SessionTimeoutDialog from '../Components/Feedback/SessionTimeoutDialog.vue';

/**
 * The staff shell, after IMIS's AppShell in its admin variant: a fixed top
 * bar (menu toggle, logo, search, account menu), a navy sidebar that docks on
 * desktop (remembered per browser) and overlays on smaller screens, and the
 * shared footer.
 * Server flash messages show as toasts (DialogHost, mounted in app.js).
 */
// The server builds the sidebar for the signed-in role (App\Support\Modules).
const navItems = computed(() => usePage().props.navigation);

const STORAGE_KEY = 'cares.sidebar-open';
const DESKTOP_QUERY = '(min-width: 1024px)';
const brand = useBranding();

const page = usePage();
const aboutOpen = ref(false);
const sidebarOpen = ref(false);
const isDesktop = ref(false);
const menuButtonRef = ref(null);
const sidebarRef = ref(null);

const readPreference = () => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) !== '0';
    } catch {
        return true;
    }
};

const savePreference = (open) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, open ? '1' : '0');
    } catch {
        // Storage unavailable; the toggle still works for this page.
    }
};

const setSidebar = async (open) => {
    sidebarOpen.value = open;

    if (isDesktop.value) {
        savePreference(open);

        return;
    }

    await nextTick();
    if (open) {
        sidebarRef.value?.querySelector('a')?.focus();
    } else {
        menuButtonRef.value?.focus();
    }
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && sidebarOpen.value && !isDesktop.value) {
        setSidebar(false);
    }
};

const isTypingTarget = (element) =>
    element instanceof HTMLElement && (element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName));

// `/` (when not typing somewhere) or Ctrl/Cmd+K focuses the header search; the
// last visible box wins (the desktop one, or the mobile one under it).
const onGlobalKeydown = (event) => {
    const isSlash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey;
    const isCommandK = event.key.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey);

    if (!(isCommandK || (isSlash && !isTypingTarget(event.target)))) return;

    const target = [...document.querySelectorAll('[data-module-search]')].filter((input) => input.offsetParent !== null).at(-1);

    if (target) {
        event.preventDefault();
        target.focus();
        target.select();
    }
};

let mediaQuery;
let removeNavigateListener;

const onMediaChange = () => {
    isDesktop.value = mediaQuery.matches;
    sidebarOpen.value = isDesktop.value ? readPreference() : false;
};

onMounted(() => {
    mediaQuery = window.matchMedia(DESKTOP_QUERY);
    onMediaChange();
    mediaQuery.addEventListener('change', onMediaChange);
    window.addEventListener('keydown', onGlobalKeydown);
    removeNavigateListener = router.on('navigate', () => {
        if (!isDesktop.value) sidebarOpen.value = false;
    });
});

onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', onMediaChange);
    window.removeEventListener('keydown', onGlobalKeydown);
    removeNavigateListener?.();
});
</script>

<template>
    <div class="min-h-screen bg-surface-muted" @keydown="onKeydown">
        <a
            href="#main-content"
            class="sr-only z-50 rounded-md bg-surface px-4 py-2 text-sm font-medium text-primary shadow-sm print:hidden focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:outline-none focus:ring-2 focus:ring-focus-ring"
        >
            Skip to main content
        </a>

        <header
            class="fixed inset-x-0 top-0 z-40 flex h-16 items-center gap-2 border-b border-border bg-surface px-3 sm:px-4 print:hidden"
        >
            <button
                ref="menuButtonRef"
                type="button"
                class="rounded-md p-2 text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :aria-label="sidebarOpen ? 'Hide navigation menu' : 'Show navigation menu'"
                aria-controls="app-sidebar"
                :aria-expanded="sidebarOpen"
                @click="setSidebar(!sidebarOpen)"
            >
                <Icon name="menu" size="md" />
            </button>

            <Link
                href="/dashboard"
                class="flex items-center gap-2 rounded-md pr-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            >
                <img :src="brand.logo" alt="CSC" class="h-9 w-auto" />
                <span class="hidden flex-col sm:flex">
                    <span class="text-lg leading-tight font-semibold text-primary">CSC RO VIII</span>
                    <span class="text-[11px] leading-tight text-text-muted">Centralized Appointment Attestation and Reporting via Electronic System</span>
                </span>
            </Link>

            <div class="hidden flex-1 justify-center px-6 md:flex">
                <ModuleSearch id="header-search" />
            </div>

            <div class="ml-auto flex items-center gap-1">
                <UserMenu v-if="page.props.auth.user" :user="page.props.auth.user" @about="aboutOpen = true" />
            </div>
        </header>

        <AboutDialog :open="aboutOpen" @close="aboutOpen = false" />
        <SessionTimeoutDialog v-if="page.props.auth.user" />

        <div
            v-if="sidebarOpen && !isDesktop"
            class="fixed inset-0 top-16 z-30 bg-text/50 print:hidden"
            aria-hidden="true"
            @click="setSidebar(false)"
        />

        <aside
            id="app-sidebar"
            ref="sidebarRef"
            class="fixed bottom-0 left-0 top-16 z-30 flex w-64 flex-col bg-csc-blue text-white transition-transform duration-200 motion-reduce:transition-none print:hidden"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            :role="isDesktop ? undefined : 'dialog'"
            :aria-modal="isDesktop ? undefined : sidebarOpen"
            aria-label="Navigation menu"
            :inert="!sidebarOpen"
        >
            <div class="scrollbar-on-dark min-h-0 flex-1 overflow-y-auto py-4">
                <SidebarNav :items="navItems" variant="admin" />
            </div>

            <footer class="shrink-0 border-t border-white/15 px-6 py-3 text-xs leading-relaxed text-white/70">
                <p class="font-medium text-white/90">CARES v{{ page.props.app?.version }}</p>
                <p>CSC Regional Office VIII</p>
            </footer>
        </aside>

        <div
            class="flex min-h-screen flex-col pt-16 transition-[padding] duration-200 motion-reduce:transition-none print:pt-0! print:pl-0!"
            :class="{ 'lg:pl-64': sidebarOpen }"
        >
            <div class="border-b border-border bg-surface px-4 py-2 md:hidden print:hidden">
                <ModuleSearch id="header-search-mobile" />
            </div>

            <main id="main-content" tabindex="-1" class="flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8">
                <slot />
            </main>

            <AppFooter class="print:hidden" @about="aboutOpen = true" />
        </div>
    </div>
</template>
