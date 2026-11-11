<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import { firstName, useSplash } from '../../Composables/useSplash';

/**
 * The account menu, after IMIS's (Components/Navigation/UserMenu.vue):
 * who you are and your CARES role, a way back to IMIS (where accounts
 * and roles are managed), the public page, and Sign out (confirmed).
 */
const props = defineProps({
    user: {
        type: Object,
        required: true,
        /** { name, username, email, photo_url, role_label } */
    },
});

const emit = defineEmits(['about']);

const page = usePage();
const { show: showSplash, hide: hideSplash } = useSplash();
const open = ref(false);
const rootRef = ref(null);
const buttonRef = ref(null);

const close = () => {
    open.value = false;
};

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) {
        close();
    }
};

const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    const hadFocus = rootRef.value?.contains(document.activeElement);
    close();
    if (hadFocus) buttonRef.value?.focus();
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

const openAbout = () => {
    close();
    emit('about');
};

const confirmingLogout = ref(false);

const requestLogout = () => {
    close();
    confirmingLogout.value = true;
};

// As in IMIS: the "Signing Out" splash, a short hold so it can be read, then
// it stays up until the sign-in page has rendered underneath it.
const logout = () => {
    confirmingLogout.value = false;
    showSplash({
        title: 'Signing Out',
        subtitle: 'See you soon, {name}',
        name: firstName(props.user.name),
        note: 'Redirecting to the sign-in page…',
    });

    window.setTimeout(() => {
        router.post('/logout', {}, { onFinish: () => hideSplash(500) });
    }, 700);
};

const itemClass =
    'flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] text-text hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring';
const groupClass = 'border-b border-border py-1';
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            ref="buttonRef"
            type="button"
            class="group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :aria-label="`Account menu for ${user.name}`"
            :aria-expanded="open"
            aria-controls="user-menu-panel"
            @click="open = !open"
        >
            <UserAvatar :name="user.name" :photo-url="user.photo_url" highlight />
            <span class="hidden flex-col whitespace-nowrap text-left leading-tight sm:flex">
                <span class="font-semibold text-text">{{ user.name }}</span>
                <span class="text-xs text-text-muted">{{ user.position || user.role_label }}</span>
            </span>
            <Icon name="chevronDown" size="sm" class="shrink-0 text-text-muted" />
        </button>

        <div
            v-if="open"
            id="user-menu-panel"
            class="absolute right-0 z-50 mt-2 max-h-[calc(100vh-5rem)] w-64 overflow-y-auto rounded-md border border-border bg-surface py-1 shadow-lg"
        >
            <div class="border-b border-border px-3 py-2">
                <!-- The trigger already shows name and position from sm up. -->
                <div class="sm:hidden">
                    <p class="text-sm font-semibold break-words text-text">{{ user.name }}</p>
                    <p v-if="user.position" class="text-xs break-words text-text-muted">{{ user.position }}</p>
                </div>
                <p class="text-xs font-medium text-primary">CARES {{ user.role_label }}</p>
                <p class="text-xs break-all text-text-muted">@{{ user.username }}<template v-if="user.email"> · {{ user.email }}</template></p>
            </div>

            <div role="group" aria-label="Elsewhere" :class="groupClass">
                <a v-if="page.props.imisUrl" :href="page.props.imisUrl" :class="itemClass" @click="close">
                    <Icon name="home" size="sm" />
                    Back to IMIS
                </a>
                <button type="button" :class="itemClass" @click="openAbout">
                    <Icon name="info" size="sm" />
                    About CARES
                </button>
            </div>

            <div class="pt-1">
                <button type="button" :class="itemClass" @click="requestLogout">
                    <Icon name="logout" size="sm" />
                    Sign out
                </button>
            </div>
        </div>

        <Modal :open="confirmingLogout" title="Sign out of CARES?" icon="logout" size="sm" @close="confirmingLogout = false">
            <div class="flex flex-col items-center text-center">
                <div class="relative rounded-full">
                    <span class="absolute -inset-2 rounded-full bg-primary/30 blur-md motion-safe:animate-breathe" aria-hidden="true" />
                    <span class="relative flex rounded-full">
                        <UserAvatar :name="user.name" :photo-url="user.photo_url" size="xl" />
                        <span class="pointer-events-none absolute inset-0 rounded-full border border-primary" aria-hidden="true" />
                    </span>
                    <span class="absolute -right-1 -bottom-1 flex h-8 w-8 items-center justify-center rounded-full bg-primary text-white ring-4 ring-surface">
                        <Icon name="logout" size="sm" />
                    </span>
                </div>
                <p class="mt-4 text-base font-semibold break-words text-text">{{ user.name }}</p>
                <p v-if="user.position" class="text-xs break-words text-text-muted">{{ user.position }}</p>
                <p class="mt-4 text-sm text-text-muted">
                    Leaving already, {{ firstName(user.name) }}? Your work is saved and your session will end on this device. To come back, open CARES from IMIS.
                </p>
            </div>

            <template #footer>
                <div class="flex w-full flex-col gap-3">
                    <Button variant="danger" class="w-full justify-center" data-autofocus @click="logout">
                        <Icon name="logout" size="sm" />
                        Sign out
                    </Button>
                    <Button variant="primary" class="w-full justify-center" @click="confirmingLogout = false">
                        Stay signed in
                    </Button>
                </div>
            </template>
        </Modal>
    </div>
</template>
