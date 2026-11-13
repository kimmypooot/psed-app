<script setup>
import { computed } from 'vue';
import Combobox from './Combobox.vue';
import TextInput from './TextInput.vue';
import FormGroup from './FormGroup.vue';

/**
 * Choose the period a report covers: a year, a quarter, a month, or any range of dates.
 * `v-model` is { kind, year, quarter, month, from, to } (see Utils/period.js). `idPrefix` keeps the field ids
 * of two pickers on one page apart.
 */
const period = defineModel({ type: Object, required: true });

const props = defineProps({
    years: { type: Array, required: true },
    idPrefix: { type: String, default: 'period' },
});

const kinds = [
    { value: 'year', label: 'Year' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'month', label: 'Month' },
    { value: 'custom', label: 'Dates' },
];
const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));
const quarterOptions = [1, 2, 3, 4].map((q) => ({ value: String(q), label: `Q${q} (${['Jan-Mar', 'Apr-Jun', 'Jul-Sep', 'Oct-Dec'][q - 1]})` }));
const monthOptions = Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: new Date(2000, i, 1).toLocaleString('en', { month: 'long' }) }));
</script>

<template>
    <div>
        <div class="inline-flex rounded-lg border border-border bg-surface p-0.5" role="group" aria-label="Kind of period">
            <button
                v-for="k in kinds"
                :key="k.value"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="period.kind === k.value ? 'bg-primary text-white shadow-xs' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
                :aria-pressed="period.kind === k.value"
                @click="period.kind = k.value"
            >
                {{ k.label }}
            </button>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <template v-if="period.kind !== 'custom'">
                <FormGroup :id="`${idPrefix}-year`" label="Year" required>
                    <Combobox :id="`${idPrefix}-year`" v-model="period.year" :options="yearOptions" :allow-custom="false" />
                </FormGroup>
                <FormGroup v-if="period.kind === 'quarter'" :id="`${idPrefix}-quarter`" label="Quarter" required>
                    <Combobox :id="`${idPrefix}-quarter`" v-model="period.quarter" :options="quarterOptions" :allow-custom="false" />
                </FormGroup>
                <FormGroup v-if="period.kind === 'month'" :id="`${idPrefix}-month`" label="Month" required>
                    <Combobox :id="`${idPrefix}-month`" v-model="period.month" :options="monthOptions" :allow-custom="false" />
                </FormGroup>
            </template>
            <template v-else>
                <TextInput :id="`${idPrefix}-from`" v-model="period.from" type="date" label="First day" required />
                <TextInput :id="`${idPrefix}-to`" v-model="period.to" type="date" label="Last day" required />
            </template>
        </div>
    </div>
</template>
