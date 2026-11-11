<script setup>
import Icon from './Icon.vue';

/**
 * The one content card: white, a 1px border (no shadow — the design is
 * flat), a section heading with an optional brand-tinted icon, a muted
 * description under it, and actions (e.g. "Add User") on the right.
 * The heading is plain sentence case in body color so the page title
 * stays the only navy heading and leads the eye. Replace the whole
 * heading with the `header` slot; add a bordered `footer` if needed.
 */
defineProps({
    title: { type: String, default: null },
    icon: { type: String, default: null },
    description: { type: String, default: null },
});
</script>

<template>
    <section class="rounded-lg border border-border bg-surface p-5 transition-colors duration-200 hover:border-primary/25">
        <header v-if="title || description || $slots.header || $slots.actions" class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <slot name="header">
                <div class="min-w-0">
                    <h2 v-if="title" class="flex items-center gap-2.5 text-base font-semibold text-text">
                        <span v-if="icon" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                            <Icon :name="icon" size="sm" />
                        </span>
                        {{ title }}
                    </h2>
                    <p v-if="description" class="mt-0.5 text-sm text-text-muted">{{ description }}</p>
                </div>
            </slot>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <slot />

        <footer v-if="$slots.footer" class="mt-4 border-t border-border pt-4">
            <slot name="footer" />
        </footer>
    </section>
</template>
