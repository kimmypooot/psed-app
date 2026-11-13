<script setup>
import { computed } from 'vue';

/**
 * A progress bar that only claims what is true. With a `value` (0-100) it is a measured
 * percentage, such as bytes uploaded. Without one it sweeps back and forth: work is going on,
 * and nothing says how far along it is. Both are announced to assistive technology; with
 * reduced motion the sweep becomes a still, half-filled bar.
 */
const props = defineProps({
    /** 0-100, or null when the progress is not known. */
    value: { type: Number, default: null },
    /** What the bar is measuring, for screen readers. */
    label: { type: String, required: true },
    tone: { type: String, default: 'primary', validator: (v) => ['primary', 'success'].includes(v) },
});

const determinate = computed(() => props.value !== null);
const percent = computed(() => Math.min(100, Math.max(0, props.value ?? 0)));
const fill = computed(() => (props.tone === 'success' ? 'bg-success' : 'bg-primary'));
</script>

<template>
    <div
        role="progressbar"
        :aria-label="label"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="determinate ? percent : undefined"
        class="h-2 w-full overflow-hidden rounded-full bg-surface-muted"
    >
        <div v-if="determinate" class="h-full rounded-full transition-[width] duration-200 ease-out motion-reduce:transition-none" :class="fill" :style="{ width: `${percent}%` }" />
        <div v-else class="h-full w-2/5 rounded-full motion-safe:animate-indeterminate motion-reduce:w-1/2 motion-reduce:opacity-60" :class="fill" />
    </div>
</template>
