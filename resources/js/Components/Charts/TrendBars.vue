<script setup>
import { computed } from 'vue';
import { outcomeColors } from './colors';

/**
 * Columns, one per month (or per year), each stacked into approved, not approved and pending. The
 * gridlines are a fixed four steps of a "nice" ceiling so the scale reads at a glance. Hovering or
 * focusing a column opens its figures; they are also in the label for screen readers.
 */
const props = defineProps({
    /** [{ label, total, approved, rejected, pending }] */
    points: { type: Array, required: true },
});

const ceiling = computed(() => {
    const max = Math.max(1, ...props.points.map((p) => p.total));
    const step = 10 ** Math.floor(Math.log10(max));
    const nice = [1, 2, 2.5, 5, 10].map((m) => m * step).find((c) => c >= max) ?? max;

    return nice;
});
const ticks = computed(() => [0, 1, 2, 3, 4].map((i) => Math.round((ceiling.value / 4) * i)));
const h = (n) => `${(n / ceiling.value) * 100}%`;
const label = (p) => `${p.label}: ${p.total} received, ${p.approved} approved, ${p.rejected} not approved, ${p.pending} pending`;
</script>

<template>
    <div class="flex gap-3">
        <div class="relative h-60 w-8 shrink-0 text-right text-[0.6875rem] text-text-muted tabular-nums" aria-hidden="true">
            <span v-for="t in ticks" :key="t" class="absolute right-0 -translate-y-1/2" :style="{ bottom: h(t) }">{{ t }}</span>
        </div>

        <div class="relative min-w-0 flex-1">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-60" aria-hidden="true">
                <div v-for="t in ticks" :key="t" class="absolute inset-x-0 border-t border-dashed border-border/70" :style="{ bottom: h(t) }" />
            </div>

            <div class="relative flex h-60 items-end gap-1.5 sm:gap-2.5" role="list" aria-label="Appointments received over time">
                <div
                    v-for="p in points"
                    :key="p.label"
                    role="listitem"
                    tabindex="0"
                    :aria-label="label(p)"
                    class="group relative flex h-full min-w-0 flex-1 flex-col justify-end rounded-t focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                >
                    <div class="flex flex-col-reverse overflow-hidden rounded-t-md transition-[filter] duration-150 group-hover:brightness-110 group-focus-visible:brightness-110" :style="{ height: h(p.total) }">
                        <div :style="{ height: p.total ? `${(p.approved / p.total) * 100}%` : 0, background: outcomeColors.approved }" />
                        <div :style="{ height: p.total ? `${(p.rejected / p.total) * 100}%` : 0, background: outcomeColors.rejected }" />
                        <div :style="{ height: p.total ? `${(p.pending / p.total) * 100}%` : 0, background: outcomeColors.pending }" />
                    </div>

                    <div
                        class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden w-44 -translate-x-1/2 rounded-md border border-border bg-surface p-2.5 text-xs shadow-lg group-hover:block group-focus-visible:block"
                        aria-hidden="true"
                    >
                        <p class="font-semibold text-text">{{ p.label }} · {{ p.total }} received</p>
                        <p class="mt-1 flex items-center justify-between text-text-muted"><span><i class="mr-1.5 inline-block size-2 rounded-sm" :style="{ background: outcomeColors.approved }" />Approved</span>{{ p.approved }}</p>
                        <p class="flex items-center justify-between text-text-muted"><span><i class="mr-1.5 inline-block size-2 rounded-sm" :style="{ background: outcomeColors.rejected }" />Not approved</span>{{ p.rejected }}</p>
                        <p class="flex items-center justify-between text-text-muted"><span><i class="mr-1.5 inline-block size-2 rounded-sm" :style="{ background: outcomeColors.pending }" />Pending</span>{{ p.pending }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-2 flex gap-1.5 sm:gap-2.5" aria-hidden="true">
                <span v-for="p in points" :key="p.label" class="min-w-0 flex-1 truncate text-center text-[0.6875rem] text-text-muted">{{ p.label }}</span>
            </div>
        </div>
    </div>
</template>
