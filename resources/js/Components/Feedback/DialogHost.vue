<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import { trapTabKey } from '../../Utils/focusTrap';
import { toast, useDialog } from '../../Composables/useDialog';

/**
 * Renders useDialog()'s alert/confirm dialog and toast stack (the legacy
 * SweetAlert look: centered card, big status icon, navy confirm / red
 * cancel). Also turns the server's one-off flash messages into toasts, so
 * every "Saved." from a controller surfaces the same way.
 */
const { state, settle, dismissToast, holdToast, releaseToast } = useDialog();

const confirmRef = ref(null);
const alertRef = ref(null);
let returnFocus = null;

const icons = {
    warning: { name: 'exclamation', ring: 'border-warning-border text-warning bg-warning-bg' },
    success: { name: 'checkCircle', ring: 'border-success-border text-success bg-success-bg' },
    error: { name: 'xCircle', ring: 'border-danger-border text-danger bg-danger-bg' },
    info: { name: 'info', ring: 'border-info-border text-info bg-info-bg' },
    question: { name: 'info', ring: 'border-info-border text-info bg-info-bg' },
};

const confirmTone = { primary: 'bg-primary hover:bg-primary-hover', danger: 'bg-danger hover:bg-accent-hover', success: 'bg-success hover:opacity-90' };

watch(
    () => state.dialog,
    async (dialog) => {
        if (dialog) {
            returnFocus = document.activeElement;
            await nextTick();
            confirmRef.value?.focus();
        } else {
            returnFocus?.focus?.();
        }
    },
);

const onKeydown = (event) => {
    if (state.dialog) trapTabKey(event, alertRef.value);

    if (event.key === 'Escape' && state.dialog) {
        event.stopPropagation();
        settle(false);
    }
};

// Server flash messages → toasts.
let removeListener;
onMounted(() => {
    document.addEventListener('keydown', onKeydown, true);
    removeListener = router.on('success', (event) => {
        // Sign-in pages show their own status message inline.
        if (event.detail.page.component.startsWith('Auth/')) return;

        const flash = event.detail.page.props.flash ?? {};
        if (flash.status) toast.success(flash.status);
        if (flash.error) toast.error(flash.error);
    });
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown, true);
    removeListener?.();
});

// Each kind: a tinted icon badge and a short heading, so a toast reads at a glance.
const toastStyles = {
    success: { icon: 'checkCircle', title: 'Done', chip: 'bg-success-bg text-success' },
    info: { icon: 'info', title: 'Notice', chip: 'bg-info-bg text-info' },
    warning: { icon: 'exclamation', title: 'Heads up', chip: 'bg-warning-bg text-warning' },
    error: { icon: 'xCircle', title: 'Something went wrong', chip: 'bg-danger-bg text-danger' },
};
</script>

<template>
    <Teleport to="body">
        <!-- Alert / confirm -->
        <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0 scale-95" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div v-if="state.dialog" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-text/50" aria-hidden="true" @click="settle(false)" />
                <div
                    ref="alertRef"
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="dialog-title"
                    :aria-describedby="state.dialog.text ? 'dialog-text' : undefined"
                    class="relative w-full max-w-sm rounded-lg bg-surface p-6 text-center shadow-xl"
                >
                    <span
                        class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full border-4"
                        :class="icons[state.dialog.icon]?.ring ?? icons.info.ring"
                        aria-hidden="true"
                    >
                        <Icon :name="icons[state.dialog.icon]?.name ?? 'info'" size="lg" />
                    </span>
                    <h2 id="dialog-title" class="text-lg font-semibold text-text">{{ state.dialog.title }}</h2>
                    <p v-if="state.dialog.text" id="dialog-text" class="mt-2 text-sm whitespace-pre-line text-text-muted">{{ state.dialog.text }}</p>

                    <div class="mt-6 flex justify-center gap-3">
                        <button
                            ref="confirmRef"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                            :class="confirmTone[state.dialog.tone] ?? confirmTone.primary"
                            @click="settle(true)"
                        >
                            <Icon name="check" size="sm" />
                            {{ state.dialog.confirmText }}
                        </button>
                        <button
                            v-if="state.dialog.showCancel"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-danger px-4 py-2 text-sm font-medium text-white hover:bg-danger-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                            @click="settle(false)"
                        >
                            <Icon name="close" size="sm" />
                            {{ state.dialog.cancelText }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Toasts -->
        <div class="pointer-events-none fixed top-20 right-4 z-[95] flex w-96 max-w-[calc(100vw-2rem)] flex-col gap-2" role="status" aria-live="polite">
            <TransitionGroup
                enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
                enter-from-class="translate-y-2 opacity-0"
                leave-active-class="transition duration-150 motion-reduce:transition-none"
                leave-to-class="opacity-0"
                move-class="transition duration-200 motion-reduce:transition-none"
            >
                <div
                    v-for="item in state.toasts"
                    :key="item.id"
                    class="pointer-events-auto flex items-start gap-3 rounded-xl border border-border bg-surface p-3.5 pr-3 text-sm shadow-lg"
                    @mouseenter="holdToast(item.id)"
                    @mouseleave="releaseToast(item.id)"
                    @focusin="holdToast(item.id)"
                    @focusout="releaseToast(item.id)"
                >
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="toastStyles[item.type].chip" aria-hidden="true">
                        <Icon :name="toastStyles[item.type].icon" size="sm" />
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="font-semibold text-text">{{ toastStyles[item.type].title }}</p>
                        <p class="mt-0.5 text-text-muted">{{ item.message }}</p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-1 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        aria-label="Dismiss notification"
                        @click="dismissToast(item.id)"
                    >
                        <Icon name="close" size="sm" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
