<script setup>
import { computed } from 'vue';
import { outcomeColors } from './colors';

/**
 * A ranked list of horizontal bars: one per category, longest first. With `mix` each bar is split into
 * what was approved, not approved and still pending, so the same chart says how many there are and how
 * they fared. Every figure is also written out, so the chart never has to be read by colour alone.
 */
const props = defineProps({
    /** [{ label, total, approved, rejected, pending }] */
    rows: { type: Array, required: true },
    mix: { type: Boolean, default: true },
    /** What the figure counts, for the tooltip and screen readers. */
    unit: { type: String, default: 'appointments' },
    /** Shown beside the label when the list is a top-N of more. */
    emptyText: { type: String, default: 'Nothing to show for these filters.' },
});

const grand = computed(() => props.rows.reduce((n, r) => n + r.total, 0));
const peak = computed(() => Math.max(1, ...props.rows.map((r) => r.total)));
const pct = (n, of) => (of ? Math.round((n / of) * 100) : 0);
const width = (n) => `${(n / peak.value) * 100}%`;
const tip = (r) => `${r.label}: ${r.total} ${props.unit}` + (props.mix ? ` (${r.approved} approved, ${r.rejected} not approved, ${r.pending} pending)` : '');
</script>

<template>
    <p v-if="!rows.length" class="py-6 text-center text-sm text-text-muted">{{ emptyText }}</p>
    <ul v-else class="space-y-2.5">
        <li v-for="r in rows" :key="r.label" class="group" :title="tip(r)">
            <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate font-medium text-text">{{ r.label }}</span>
                <span class="shrink-0 tabular-nums text-text-muted">
                    <strong class="font-semibold text-text">{{ r.total.toLocaleString() }}</strong>
                    <span class="ml-1.5 text-xs">{{ pct(r.total, grand) }}%</span>
                </span>
            </div>
            <div class="flex h-2.5 overflow-hidden rounded-full bg-surface-muted" role="img" :aria-label="tip(r)">
                <template v-if="mix">
                    <div class="h-full transition-all duration-500 motion-reduce:transition-none" :style="{ width: width(r.approved), background: outcomeColors.approved }" />
                    <div class="h-full transition-all duration-500 motion-reduce:transition-none" :style="{ width: width(r.rejected), background: outcomeColors.rejected }" />
                    <div class="h-full transition-all duration-500 motion-reduce:transition-none" :style="{ width: width(r.pending), background: outcomeColors.pending }" />
                </template>
                <div v-else class="h-full rounded-full bg-primary transition-all duration-500 motion-reduce:transition-none" :style="{ width: width(r.total) }" />
            </div>
        </li>
    </ul>
</template>
