<script setup>
import Button from './Button.vue';

/**
 * Shared full-page presentation for 403/404/419/429/500/503 — kept
 * professional and generic; never renders stack traces, SQL, file paths,
 * or other internal details (those never reach the frontend to begin
 * with — the backend only ever passes a status code and a plain message).
 */
defineProps({
    code: {
        type: [String, Number],
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-surface-muted px-4">
        <div class="w-full max-w-md rounded-lg border border-border bg-surface p-8 text-center shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wider text-danger">Error {{ code }}</p>
            <h1 class="mt-2 text-xl font-semibold text-text">{{ title }}</h1>
            <p class="mt-2 text-sm text-text-muted">{{ message }}</p>
            <Button href="/dashboard" class="mt-6">Return to dashboard</Button>
        </div>
    </div>
</template>
