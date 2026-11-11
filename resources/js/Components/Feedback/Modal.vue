<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import Icon from '../UI/Icon.vue';
import { trapTabKey } from '../../Utils/focusTrap';

/**
 * Generic accessible dialog, styled after the legacy IMIS Bootstrap
 * modals: optional colored header (`tone`: success for "Add …", primary
 * for "Edit …", danger for destructive flows), a scrolling body, and a
 * footer pinned to the bottom. Covers confirmation/form/information use
 * cases via slots — it does not know what content it holds.
 *
 * Slots: default (body), `summary` (a strip under the header, e.g. the
 * record being edited), `footer` (actions).
 */
const props = defineProps({
    open: {
        type: Boolean,
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value),
    },
    tone: {
        type: String,
        default: 'neutral',
        validator: (value) => ['neutral', 'primary', 'success', 'danger'].includes(value),
    },
    icon: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['close']);

const titleId = `modal-title-${useId()}`;
const dialogRef = ref(null);
let triggerElement = null;

const sizeClasses = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-2xl', xl: 'max-w-5xl' };
const headerClasses = {
    neutral: 'border-b border-border text-text',
    primary: 'bg-primary text-white',
    success: 'bg-success text-white',
    danger: 'bg-danger text-white',
};

const close = () => emit('close');

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        close();
    }

    trapTabKey(event, dialogRef.value);
};

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            triggerElement = document.activeElement;
            await nextTick();
            const autofocus = dialogRef.value?.querySelector('[data-autofocus]');
            (autofocus ?? dialogRef.value)?.focus();
            document.addEventListener('keydown', onKeydown);
            document.body.style.overflow = 'hidden';
        } else {
            document.removeEventListener('keydown', onKeydown);
            document.body.style.overflow = '';
            triggerElement?.focus?.();
        }
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-100" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-text/50" aria-hidden="true" @click="close" />

            <div
                ref="dialogRef"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-lg bg-surface shadow-xl focus:outline-none"
                :class="sizeClasses[size]"
            >
                <div class="flex shrink-0 items-center justify-between gap-4 px-5 py-3" :class="headerClasses[tone]">
                    <h2 :id="titleId" class="flex items-center gap-2 text-base font-semibold">
                        <Icon v-if="icon" :name="icon" size="sm" />
                        {{ title }}
                    </h2>
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        :class="tone === 'neutral' ? 'text-text-muted hover:bg-surface-muted hover:text-text' : 'text-white/85 hover:bg-white/15 hover:text-white'"
                        aria-label="Close dialog"
                        @click="close"
                    >
                        <Icon name="close" size="sm" />
                    </button>
                </div>

                <div v-if="$slots.summary" class="shrink-0 border-b border-border bg-surface-muted px-5 py-3">
                    <slot name="summary" />
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                    <slot />
                </div>

                <div v-if="$slots.footer" class="flex shrink-0 justify-end gap-3 border-t border-border bg-surface px-5 py-3">
                    <slot name="footer" />
                </div>
            </div>
        </div>
        </Transition>
    </Teleport>
</template>
