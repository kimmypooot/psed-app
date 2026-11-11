<script setup>
import Icon from '../UI/Icon.vue';

/**
 * The legacy admin "metric-card": a label, one big number, a sub-line,
 * an optional progress bar, and a tinted icon chip, on a flat bordered
 * card. The number is the content — no chart needed for a single figure.
 * Pick `warning` only when the figure calls for attention (vacancies,
 * retirements), not for variety.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    icon: { type: String, required: true },
    tone: { type: String, default: 'primary', validator: (value) => ['primary', 'success', 'warning', 'info'].includes(value) },
    progress: { type: Number, default: null /** 0–100 */ },
});

const accents = {
    primary: { chip: 'bg-info-bg text-primary', bar: 'bg-primary' },
    success: { chip: 'bg-success-bg text-success', bar: 'bg-success' },
    warning: { chip: 'bg-warning-bg text-warning', bar: 'bg-warning' },
    info: { chip: 'bg-info-bg text-csc-blue-light', bar: 'bg-csc-blue-light' },
};
</script>

<template>
    <section class="h-full rounded-lg border border-border bg-surface p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-xs font-semibold tracking-wide text-text-muted uppercase">{{ label }}</h3>
                <p class="mt-1 text-3xl font-bold text-text">{{ value }}</p>
                <div class="mt-1 text-xs text-text-muted"><slot /></div>
            </div>
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg" :class="accents[tone].chip" aria-hidden="true">
                <Icon :name="icon" />
            </span>
        </div>
        <div v-if="progress !== null" class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-muted" role="presentation">
            <div class="h-full rounded-full" :class="accents[tone].bar" :style="{ width: `${Math.min(100, Math.max(0, progress))}%` }" />
        </div>
    </section>
</template>
