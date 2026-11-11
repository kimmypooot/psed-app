<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Minimal previous/next pager for a Laravel paginator. Takes plain URLs
 * rather than the paginator's `links` array, whose labels contain HTML
 * entities ("&laquo; Previous") that would need v-html to render.
 */
defineProps({
    prevUrl: {
        type: String,
        default: null,
    },
    nextUrl: {
        type: String,
        default: null,
    },
    currentPage: {
        type: Number,
        required: true,
    },
    lastPage: {
        type: Number,
        required: true,
    },
});

const linkClass =
    'rounded-md border border-border bg-surface px-3 py-1.5 font-medium text-text transition-colors hover:border-primary/40 hover:bg-info-bg hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <nav v-if="lastPage > 1" class="mt-4 flex items-center justify-between gap-3 text-sm" aria-label="Pagination">
        <Link v-if="prevUrl" :href="prevUrl" :class="linkClass" preserve-scroll>Previous</Link>
        <span v-else />

        <span class="text-text-muted">Page {{ currentPage }} of {{ lastPage }}</span>

        <Link v-if="nextUrl" :href="nextUrl" :class="linkClass" preserve-scroll>Next</Link>
        <span v-else />
    </nav>
</template>
