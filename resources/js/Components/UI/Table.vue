<script setup>
/**
 * Styled wrapper around a plain <table>. Consumers write normal
 * <thead>/<tbody>/<tr>/<th>/<td> markup into the default slot — this only
 * establishes the shared container, scroll behavior, and row/header
 * styling via `:deep()`, so it stays a foundation rather than a data-grid.
 * Pass `:loading="true"` to show a spinner, or leave `rows` at 0 to fall
 * back to the `empty` slot.
 */
import Spinner from './Spinner.vue';

defineProps({
    loading: {
        type: Boolean,
        default: false,
    },
    empty: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-border bg-surface">
        <div v-if="loading" class="flex items-center justify-center gap-2 px-4 py-12 text-sm text-text-muted">
            <Spinner size="sm" label="" />
            Loading…
        </div>
        <div v-else-if="empty">
            <slot name="empty" />
        </div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm table-styled">
                <slot />
            </table>
        </div>
    </div>
</template>

<style scoped>
@reference '../../../css/app.css';

/* Same header as every list table (the `table-head` utility in app.css). */
.table-styled :deep(thead) {
    @apply table-head;
    border-bottom: 1px solid var(--color-border);
}

.table-styled :deep(th) {
    padding: 0.75rem 1rem;
}

.table-styled :deep(td) {
    padding: 0.75rem 1rem;
    color: var(--color-text);
}

.table-styled :deep(tbody tr) {
    border-bottom: 1px solid var(--color-border);
    transition: background-color 0.15s;
}

.table-styled :deep(tbody tr:hover) {
    background-color: color-mix(in srgb, var(--color-info-bg) 55%, transparent);
}

.table-styled :deep(tbody tr:last-child) {
    border-bottom: none;
}
</style>
