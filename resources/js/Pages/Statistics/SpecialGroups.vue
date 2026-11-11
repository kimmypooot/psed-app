<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Icon from '@/Components/UI/Icon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import MetricCard from '@/Components/Admin/MetricCard.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import { useListFilters } from '@/Composables/useListFilters';

/**
 * How many appointments were for a person with disability, a member of an indigenous group or a
 * solo parent, per month, quarter, semester or year. For PSED administrators; counted by the date
 * the appointment was received, across all Field Offices or one.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    rows: { type: Array, required: true },
    totals: { type: Object, required: true },
    periods: { type: Array, required: true },
    years: { type: Array, required: true },
    offices: { type: Array, required: true },
});

const filters = useListFilters('/statistics/special-groups', {
    period: props.filters.period,
    year: String(props.filters.year),
    field_office_id: props.filters.field_office_id === '' ? '' : String(props.filters.field_office_id),
});

const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));
const officeOptions = computed(() => [{ value: '', label: 'All Field Offices' }, ...props.offices.map((o) => ({ value: String(o.id), label: o.name }))]);
const yearly = computed(() => filters.period === 'yearly');
const hasData = computed(() => props.totals.total > 0);

const exportUrl = computed(() => {
    const query = new URLSearchParams({ period: filters.period, year: filters.year });
    if (filters.field_office_id) query.set('field_office_id', filters.field_office_id);

    return `/statistics/special-groups/export?${query}`;
});

// A plain download, so the browser handles it (no Inertia visit).
const download = () => window.location.assign(exportUrl.value);

const cards = [
    { key: 'pwd', label: 'Persons with Disability', icon: 'user', tone: 'primary' },
    { key: 'indigenous', label: 'Indigenous Group Members', icon: 'users', tone: 'success' },
    { key: 'solo_parent', label: 'Solo Parents', icon: 'userPlus', tone: 'info' },
];
const share = (n) => (props.totals.total ? Math.round((n / props.totals.total) * 100) : 0);
</script>

<template>
    <Head title="PWD, Indigenous and Solo Parent Counts" />

    <StaffLayout>
        <PageHeader
            title="PWD, Indigenous and Solo Parent Counts"
            description="Appointments received for a person with disability, a member of an indigenous group or a solo parent. A person can belong to more than one group, so the groups do not add up to the total."
            :breadcrumbs="[{ label: 'Statistics' }, { label: 'PWD, IP and Solo Parents' }]"
        />

        <Card class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-44">
                    <label for="f-period" class="block text-sm font-medium text-text">Per</label>
                    <Combobox id="f-period" v-model="filters.period" :allow-custom="false" :options="periods" class="mt-1" />
                </div>
                <div v-if="!yearly" class="w-44">
                    <label for="f-year" class="block text-sm font-medium text-text">Year</label>
                    <Combobox id="f-year" v-model="filters.year" :allow-custom="false" :options="yearOptions" class="mt-1" />
                </div>
                <div class="w-72">
                    <label for="f-office" class="block text-sm font-medium text-text">Field Office</label>
                    <Combobox id="f-office" v-model="filters.field_office_id" :allow-custom="false" :options="officeOptions" placeholder="All Field Offices" class="mt-1" />
                </div>
                <div class="ml-auto">
                    <Button variant="outline" @click="download"><Icon name="download" size="sm" />Download CSV</Button>
                </div>
            </div>
        </Card>

        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <MetricCard v-for="c in cards" :key="c.key" :label="c.label" :value="totals[c.key]" :icon="c.icon" :tone="c.tone" :progress="share(totals[c.key])">
                {{ share(totals[c.key]) }}% of all appointments
            </MetricCard>
            <MetricCard label="All appointments" :value="totals.total" icon="clipboard" tone="primary">in the period shown</MetricCard>
        </div>

        <Table :empty="!rows.length">
            <template #empty>
                <EmptyState icon="chartBar" title="No appointments yet" description="Nothing has been received for these filters." />
            </template>

            <thead>
                <tr>
                    <th scope="col">Period</th>
                    <th scope="col" class="text-right">Persons with Disability</th>
                    <th scope="col" class="text-right">Indigenous Group Members</th>
                    <th scope="col" class="text-right">Solo Parents</th>
                    <th scope="col" class="text-right">All Appointments</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.key">
                    <th scope="row" class="font-medium">{{ r.label }}</th>
                    <td class="text-right tabular-nums">{{ r.pwd }}</td>
                    <td class="text-right tabular-nums">{{ r.indigenous }}</td>
                    <td class="text-right tabular-nums">{{ r.solo_parent }}</td>
                    <td class="text-right tabular-nums">{{ r.total }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t border-border bg-surface-muted font-semibold">
                    <th scope="row" class="px-4 py-3">Total</th>
                    <td class="px-4 py-3 text-right tabular-nums">{{ totals.pwd }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ totals.indigenous }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ totals.solo_parent }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ totals.total }}</td>
                </tr>
            </tfoot>
        </Table>
        <p v-if="!hasData && rows.length" class="mt-2 text-sm text-text-muted">No appointments were received in this period.</p>
    </StaffLayout>
</template>
