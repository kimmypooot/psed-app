<script setup>
import { computed } from 'vue';
import { toneClasses, tones } from './tones';

/**
 * Semantic status badge. Status is conveyed by the label text plus color,
 * never color alone — pass the human-readable word (e.g. "Active",
 * "Pending") as the default slot and pick the closest `tone`.
 */
const props = defineProps({
    tone: {
        type: String,
        default: 'neutral',
        validator: (value) => tones.includes(value),
    },
});

const classes = computed(() => toneClasses[props.tone]);
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium" :class="classes">
        <slot />
    </span>
</template>
