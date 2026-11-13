<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Combobox from '@/Components/Forms/Combobox.vue';

/**
 * Which row of the Summary Database workbook each agency belongs to. Most match by their names; the rest are
 * paired here once, and the report remembers. An agency can also be said to have no row of its own, in which
 * case it is counted in its Field Office's row.
 */
const props = defineProps({
    offices: { type: Array, required: true },
    error: { type: String, default: null },
    noRow: { type: String, required: true },
});

const key = (office, agency) => `${office.id}|${agency}`;
const choices = reactive({});
const initial = {};

// What each editable agency is set to now: nothing yet, a row, or "no row of its own".
watch(
    () => props.offices,
    (offices) => {
        for (const o of offices) {
            for (const a of o.agencies) {
                if (a.status === 'auto') continue;

                const value = a.status === 'mapped' ? a.template : a.status === 'none' ? props.noRow : '';
                choices[key(o, a.agency)] = value;
                initial[key(o, a.agency)] = value;
            }
        }
    },
    { immediate: true },
);

const options = (office) => [
    { value: '', label: 'Not paired yet' },
    { value: props.noRow, label: 'No row of its own (counted in the office row)' },
    ...office.rows.map((r) => ({ value: r, label: r })),
];

// Unmatched first, then what was paired by hand; what matched by itself needs nothing.
const attention = (office) => office.agencies.filter((a) => a.status !== 'auto').sort((x, y) => (x.status === 'unmatched' ? 0 : 1) - (y.status === 'unmatched' ? 0 : 1));
const automatic = (office) => office.agencies.filter((a) => a.status === 'auto');
const counts = (office) => ({
    open: office.agencies.filter((a) => a.status === 'unmatched').length,
    paired: office.agencies.filter((a) => a.status === 'mapped' || a.status === 'none').length,
});

const changes = computed(() =>
    props.offices.flatMap((o) =>
        attention(o)
            .filter((a) => (choices[key(o, a.agency)] ?? '') !== (initial[key(o, a.agency)] ?? ''))
            .map((a) => ({ field_office_id: o.id, agency: a.agency, choice: choices[key(o, a.agency)] ?? '' })),
    ),
);
const open = computed(() => props.offices.reduce((sum, o) => sum + counts(o).open, 0));

const save = () => router.put('/settings/summary-agencies', { items: changes.value }, { preserveScroll: true });
</script>

<template>
    <Head title="Summary Agencies" />

    <StaffLayout>
        <PageHeader
            title="Summary Agencies"
            description="Pair an agency with its row of the Summary Database workbook when their names differ. The report remembers each pairing."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Summary Agencies' }]"
        />

        <Alert v-if="error" tone="danger" class="mb-4">{{ error }}</Alert>

        <Alert v-else-if="open === 0" tone="success" class="mb-4">Every agency has a row in the workbook, matched by name or paired by you.</Alert>
        <Alert v-else tone="warning" class="mb-4">
            {{ open }} agenc{{ open === 1 ? 'y has' : 'ies have' }} no row yet. Until one is paired, its appointments are counted in its Field Office's own row of the workbook, with a note.
        </Alert>

        <div class="space-y-4">
            <Card
                v-for="office in offices"
                :key="office.id"
                :title="office.name"
                icon="building"
                :description="`${counts(office).open} to pair · ${counts(office).paired} paired by you · ${automatic(office).length} matched by name`"
            >
                <ul v-if="attention(office).length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="a in attention(office)" :key="a.agency" class="grid gap-3 p-3 md:grid-cols-2 md:items-center">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-text">
                                {{ a.agency }}
                                <Badge v-if="a.status === 'unmatched'" tone="warning">Not paired</Badge>
                                <Badge v-else-if="a.status === 'none'" tone="neutral">No row of its own</Badge>
                                <Badge v-else tone="success">Paired</Badge>
                            </p>
                            <p v-if="a.status === 'unmatched' && a.suggestion && choices[key(office, a.agency)] !== a.suggestion" class="mt-1 text-xs text-text-muted">
                                Closest row: {{ a.suggestion }}
                                <button type="button" class="ml-1 font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="choices[key(office, a.agency)] = a.suggestion">Use it</button>
                            </p>
                        </div>
                        <div>
                            <label :for="`sa-${office.id}-${a.agency}`" class="sr-only">Row of the workbook for {{ a.agency }}</label>
                            <Combobox :id="`sa-${office.id}-${a.agency}`" v-model="choices[key(office, a.agency)]" :options="options(office)" :allow-custom="false" />
                        </div>
                    </li>
                </ul>
                <p v-else class="flex items-center gap-2 text-sm text-success"><Icon name="checkCircle" size="sm" />Every agency of this office matches a row by name.</p>

                <details v-if="automatic(office).length" class="mt-3 text-sm">
                    <summary class="cursor-pointer font-medium text-primary">{{ automatic(office).length }} matched by name</summary>
                    <ul class="mt-2 space-y-1 text-text-muted">
                        <li v-for="a in automatic(office)" :key="a.agency">{{ a.agency }} <span class="text-xs">→ {{ a.template }}</span></li>
                    </ul>
                </details>
            </Card>
        </div>

        <div class="sticky bottom-4 z-20 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface p-3 shadow-lg">
            <p class="text-sm" role="status">
                <span v-if="changes.length" class="font-medium text-text">{{ changes.length }} change{{ changes.length === 1 ? '' : 's' }} not saved yet</span>
                <span v-else class="text-text-muted">No changes</span>
            </p>
            <Button variant="success" :disabled="!changes.length" @click="save"><Icon name="check" size="sm" />Save pairings</Button>
        </div>
    </StaffLayout>
</template>
