<script setup>
import { computed } from 'vue';

/**
 * A ring split into slices, the total in the middle, and a legend that writes every figure out.
 * Drawn as stroked circle arcs, so there is no chart library to load.
 */
const props = defineProps({
    /** [{ label, value, color }] */
    slices: { type: Array, required: true },
    centerLabel: { type: String, default: 'Total' },
});

const R = 42;
const C = 2 * Math.PI * R;
const total = computed(() => props.slices.reduce((n, s) => n + s.value, 0));
const arcs = computed(() => {
    let offset = 0;

    return props.slices.map((s) => {
        const len = total.value ? (s.value / total.value) * C : 0;
        const arc = { ...s, dash: `${len} ${C - len}`, offset: -offset };
        offset += len;

        return arc;
    });
});
const pct = (n) => (total.value ? Math.round((n / total.value) * 1000) / 10 : 0);
</script>

<template>
    <div class="flex flex-col items-center gap-5 sm:flex-row">
        <div class="relative size-40 shrink-0">
            <svg viewBox="0 0 100 100" class="size-full -rotate-90" role="img" :aria-label="slices.map((s) => `${s.label} ${s.value}`).join(', ')">
                <circle cx="50" cy="50" :r="R" fill="none" stroke="var(--color-surface-muted)" stroke-width="12" />
                <circle
                    v-for="a in arcs"
                    :key="a.label"
                    cx="50"
                    cy="50"
                    :r="R"
                    fill="none"
                    :stroke="a.color"
                    stroke-width="12"
                    :stroke-dasharray="a.dash"
                    :stroke-dashoffset="a.offset"
                    class="transition-all duration-700 motion-reduce:transition-none"
                />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-bold text-text tabular-nums">{{ total.toLocaleString() }}</span>
                <span class="text-xs text-text-muted">{{ centerLabel }}</span>
            </div>
        </div>

        <ul class="w-full min-w-0 flex-1 space-y-2 text-sm">
            <li v-for="s in slices" :key="s.label" class="flex items-center justify-between gap-3">
                <span class="flex min-w-0 items-center gap-2 text-text-muted"><i class="size-2.5 shrink-0 rounded-sm" :style="{ background: s.color }" /><span class="truncate">{{ s.label }}</span></span>
                <span class="shrink-0 tabular-nums"><strong class="font-semibold text-text">{{ s.value.toLocaleString() }}</strong><span class="ml-1.5 text-xs text-text-muted">{{ pct(s.value) }}%</span></span>
            </li>
        </ul>
    </div>
</template>
