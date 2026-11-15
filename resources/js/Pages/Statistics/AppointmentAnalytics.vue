<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import MetricCard from '@/Components/Admin/MetricCard.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import Donut from '@/Components/Charts/Donut.vue';
import RankedBars from '@/Components/Charts/RankedBars.vue';
import TrendBars from '@/Components/Charts/TrendBars.vue';
import { outcomeColors, outcomeLabels } from '@/Components/Charts/colors';
import { useListFilters } from '@/Composables/useListFilters';

/**
 * Appointment analytics for PSED administrators: how many appointments were received, how they were
 * decided and how long that took, and the same counts broken down by the details of the appointment.
 * Counted by the date received, across every Field Office or one. Figures are tallied on the server
 * (and cached until an appointment changes); this page only draws them.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    years: { type: Array, required: true },
    offices: { type: Array, required: true },
});

const filters = useListFilters('/statistics/appointments', {
    year: props.filters.year,
    field_office_id: props.filters.field_office_id === '' ? '' : String(props.filters.field_office_id),
    type: props.filters.type,
});

const yearOptions = computed(() => [{ value: 'all', label: 'All years' }, ...props.years.map((y) => ({ value: y, label: y }))]);
const officeOptions = computed(() => [{ value: '', label: 'All Field Offices' }, ...props.offices.map((o) => ({ value: String(o.id), label: o.name }))]);
const typeOptions = [
    { value: '', label: 'Plantilla and Casual / Contractual' },
    { value: 'plantilla', label: 'Plantilla only' },
    { value: 'casual-contractual', label: 'Casual / Contractual only' },
];

const s = computed(() => props.stats);
const empty = computed(() => s.value.total === 0);
const n = (v) => (v ?? 0).toLocaleString();
const pct = (v, of) => (of ? Math.round((v / of) * 1000) / 10 : 0);
const days = (v) => (v === null || v === undefined ? '—' : `${v}`);

const tabs = [
    { id: 'overview', label: 'Overview', icon: 'chartBar' },
    { id: 'details', label: 'Appointment details', icon: 'clipboard' },
    { id: 'processing', label: 'Processing time', icon: 'clock' },
    { id: 'sources', label: 'Offices, agencies and positions', icon: 'building' },
];
const tab = ref('overview');

const outcomeSlices = computed(() =>
    ['approved', 'rejected', 'pending'].map((k) => ({ label: outcomeLabels[k], value: s.value.outcome[k], color: outcomeColors[k] })),
);
const actionRows = computed(() => s.value.actions.map((a) => ({ label: a.label, total: a.count, approved: 0, rejected: 0, pending: 0 })));
const asRows = (list) => list.map((b) => ({ label: b.label, total: b.count, approved: 0, rejected: 0, pending: 0 }));

const scope = computed(() => {
    const parts = [props.filters.year === 'all' ? 'All years' : props.filters.year];
    parts.push(props.filters.field_office_id ? (props.offices.find((o) => String(o.id) === String(props.filters.field_office_id))?.name ?? '') : 'All Field Offices');
    if (props.filters.type) parts.push(props.filters.type === 'plantilla' ? 'Plantilla' : 'Casual / Contractual');

    return parts.join(' · ');
});

const groups = computed(() => [
    { label: 'Persons with disability', value: s.value.flags.pwd, icon: 'user' },
    { label: 'Indigenous group members', value: s.value.flags.indigenous, icon: 'users' },
    { label: 'Solo parents', value: s.value.flags.solo_parent, icon: 'userPlus' },
    { label: 'K-12 graduates', value: s.value.flags.k12, icon: 'clipboard' },
    { label: 'First-time eligibility users', value: s.value.flags.first_time, icon: 'checkCircle' },
]);

const turnaroundMax = computed(() => Math.max(1, ...s.value.turnaround.bands.map((b) => b.count)));
const agingTotal = computed(() => s.value.aging.reduce((t, a) => t + a.count, 0));
</script>

<template>
    <Head title="Appointment Analytics" />

    <StaffLayout>
        <PageHeader
            title="Appointment Analytics"
            description="What was received, how it was decided and how long that took, broken down by the details of the appointment. Counted by the date received."
            :breadcrumbs="[{ label: 'Statistics' }, { label: 'Appointment Analytics' }]"
        />

        <!-- Filters -->
        <Card class="mb-5">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-40">
                    <label for="f-year" class="block text-sm font-medium text-text">Year</label>
                    <Combobox id="f-year" v-model="filters.year" :allow-custom="false" :options="yearOptions" class="mt-1" />
                </div>
                <div class="w-80">
                    <label for="f-office" class="block text-sm font-medium text-text">Field Office</label>
                    <Combobox id="f-office" v-model="filters.field_office_id" :allow-custom="false" :options="officeOptions" placeholder="All Field Offices" class="mt-1" />
                </div>
                <div class="w-[22rem]">
                    <label for="f-type" class="block text-sm font-medium text-text">Appointments</label>
                    <Combobox id="f-type" v-model="filters.type" :allow-custom="false" :options="typeOptions" class="mt-1" />
                </div>
                <p class="ml-auto self-center text-xs text-text-muted">
                    <span class="font-medium text-text">{{ scope }}</span><br />
                    Figures as of {{ s.generated_at }}
                </p>
            </div>
        </Card>

        <EmptyState v-if="empty" icon="chartBar" title="No appointments for these filters" description="Nothing was received in this period. Try another year, office or type." />

        <template v-else>
            <!-- Headline figures -->
            <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard label="Received" :value="n(s.total)" icon="clipboard" tone="primary">appointments in the period</MetricCard>
                <MetricCard label="Approval rate" :value="s.approval_rate === null ? '—' : `${s.approval_rate}%`" icon="checkCircle" tone="success" :progress="s.approval_rate ?? 0">
                    {{ n(s.outcome.approved) }} of {{ n(s.decided) }} decided
                </MetricCard>
                <MetricCard label="Pending" :value="n(s.outcome.pending)" icon="clock" tone="warning" :progress="pct(s.outcome.pending, s.total)">
                    {{ pct(s.outcome.pending, s.total) }}% still awaiting action
                </MetricCard>
                <MetricCard label="Average turnaround" :value="s.turnaround.average === null ? '—' : `${s.turnaround.average} d`" icon="chartBar" tone="info">
                    median {{ days(s.turnaround.median) }} days, slowest {{ days(s.turnaround.slowest) }}
                </MetricCard>
                <MetricCard label="Processed by PSED" :value="n(s.breakdowns.processed_by.find((p) => p.label === 'PSED')?.total)" icon="building" tone="primary" :progress="pct(s.breakdowns.processed_by.find((p) => p.label === 'PSED')?.total ?? 0, s.total)">
                    {{ pct(s.breakdowns.processed_by.find((p) => p.label === 'PSED')?.total ?? 0, s.total) }}% (the rest by Field Offices)
                </MetricCard>
            </div>

            <!-- Which view -->
            <div class="mb-5 overflow-x-auto" role="tablist" aria-label="Analytics views">
                <div class="inline-flex gap-1 rounded-lg border border-border bg-surface p-1">
                    <button
                        v-for="t in tabs"
                        :key="t.id"
                        type="button"
                        role="tab"
                        :aria-selected="tab === t.id"
                        class="flex shrink-0 items-center gap-2 rounded-md px-3.5 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        :class="tab === t.id ? 'bg-primary text-white shadow-xs' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
                        @click="tab = t.id"
                    >
                        <Icon :name="t.icon" size="sm" />{{ t.label }}
                    </button>
                </div>
            </div>

            <!-- Overview -->
            <div v-show="tab === 'overview'" class="space-y-5" role="tabpanel">
                <div class="grid gap-5 lg:grid-cols-3">
                    <Card class="lg:col-span-2" :title="props.filters.year === 'all' ? 'Received per year' : `Received per month, ${props.filters.year}`" icon="chartBar" description="Each column is split by how the appointment was decided.">
                        <TrendBars :points="s.trend" />
                        <ul class="mt-4 flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-text-muted">
                            <li v-for="(label, key) in outcomeLabels" :key="key" class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm" :style="{ background: outcomeColors[key] }" />{{ label }}</li>
                        </ul>
                    </Card>

                    <Card title="How they were decided" icon="checkCircle" description="Every appointment received in the period.">
                        <Donut :slices="outcomeSlices" center-label="received" />
                        <div class="mt-5 border-t border-border pt-4">
                            <h3 class="mb-2 text-xs font-semibold tracking-wide text-text-muted uppercase">By CSC action</h3>
                            <RankedBars :rows="actionRows" :mix="false" />
                        </div>
                    </Card>
                </div>

                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <Card title="Status of employment" icon="clipboard"><RankedBars :rows="s.breakdowns.employment_status" /></Card>
                    <Card title="Nature of appointment" icon="clipboard"><RankedBars :rows="s.breakdowns.nature" /></Card>
                    <Card title="Salary grade" icon="chartBar"><RankedBars :rows="s.breakdowns.grade" /></Card>
                </div>

                <Card title="Special groups and eligibility" icon="users" description="A person can belong to more than one group, so these do not add up to the total.">
                    <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <li v-for="g in groups" :key="g.label" class="rounded-lg border border-border p-3 transition-colors hover:border-primary/30 hover:bg-info-bg/40">
                            <div class="flex items-center gap-2 text-xs text-text-muted"><Icon :name="g.icon" size="sm" class="text-primary" />{{ g.label }}</div>
                            <p class="mt-1 text-2xl font-bold text-text tabular-nums">{{ n(g.value) }}</p>
                            <p class="text-xs text-text-muted">{{ pct(g.value, s.total) }}% of appointments</p>
                        </li>
                    </ul>
                </Card>
            </div>

            <!-- Appointment details -->
            <div v-show="tab === 'details'" class="space-y-5" role="tabpanel">
                <p class="text-sm text-text-muted">Each bar is split into approved, not approved and pending, so the same chart shows how many there are and how they fared.</p>
                <div class="grid gap-5 md:grid-cols-2">
                    <Card title="Status of employment" icon="clipboard"><RankedBars :rows="s.breakdowns.employment_status" /></Card>
                    <Card title="Nature of appointment" icon="clipboard"><RankedBars :rows="s.breakdowns.nature" /></Card>
                    <Card title="Level" icon="chartBar"><RankedBars :rows="s.breakdowns.level" /></Card>
                    <Card title="Salary grade" icon="chartBar"><RankedBars :rows="s.breakdowns.grade" /></Card>
                    <Card title="Sex" icon="users"><RankedBars :rows="s.breakdowns.sex" /></Card>
                    <Card title="Sector" icon="building"><RankedBars :rows="s.breakdowns.sector" /></Card>
                    <Card title="Eligibility" icon="checkCircle" description="The eight most common."><RankedBars :rows="s.breakdowns.eligibility" /></Card>
                    <Card title="Mode of publication" icon="clipboard"><RankedBars :rows="s.breakdowns.publication" /></Card>
                </div>
            </div>

            <!-- Processing time -->
            <div v-show="tab === 'processing'" class="space-y-5" role="tabpanel">
                <div class="grid gap-5 lg:grid-cols-2">
                    <Card title="Days from receipt to decision" icon="clock" :description="`${n(s.turnaround.count)} decided appointments with a date of action.`">
                        <div class="mb-4 grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-lg bg-surface-muted p-3"><p class="text-2xl font-bold tabular-nums">{{ days(s.turnaround.average) }}</p><p class="text-xs text-text-muted">average days</p></div>
                            <div class="rounded-lg bg-surface-muted p-3"><p class="text-2xl font-bold tabular-nums">{{ days(s.turnaround.median) }}</p><p class="text-xs text-text-muted">median days</p></div>
                            <div class="rounded-lg bg-surface-muted p-3"><p class="text-2xl font-bold tabular-nums">{{ days(s.turnaround.slowest) }}</p><p class="text-xs text-text-muted">slowest</p></div>
                        </div>
                        <ul class="space-y-2.5">
                            <li v-for="b in s.turnaround.bands" :key="b.label">
                                <div class="mb-1 flex justify-between text-sm"><span class="font-medium">{{ b.label }}</span><span class="tabular-nums text-text-muted"><strong class="text-text">{{ n(b.count) }}</strong> <span class="text-xs">{{ pct(b.count, s.turnaround.count) }}%</span></span></div>
                                <div class="h-2.5 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-primary transition-all duration-500 motion-reduce:transition-none" :style="{ width: `${(b.count / turnaroundMax) * 100}%` }" /></div>
                            </li>
                        </ul>
                    </Card>

                    <div class="space-y-5">
                        <Card title="Who processed it" icon="building" description="PSED takes the higher salary grades; Field Offices the rest.">
                            <RankedBars :rows="s.breakdowns.processed_by" />
                            <table class="mt-4 w-full text-sm">
                                <thead class="text-xs text-text-muted"><tr><th class="py-1 text-left font-semibold">Average days to decide</th><th class="py-1 text-right font-semibold">Decided</th><th class="py-1 text-right font-semibold">Avg.</th></tr></thead>
                                <tbody>
                                    <tr v-for="p in s.turnaround.by_processor" :key="p.label" class="border-t border-border">
                                        <td class="py-1.5">{{ p.label }}</td><td class="py-1.5 text-right tabular-nums">{{ n(p.count) }}</td><td class="py-1.5 text-right font-semibold tabular-nums">{{ days(p.average) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </Card>

                        <Card title="Waiting for a decision" icon="clock" :description="`${n(agingTotal)} pending, by how long since they were received.`">
                            <ul class="space-y-2.5">
                                <li v-for="(a, i) in s.aging" :key="a.label">
                                    <div class="mb-1 flex justify-between text-sm"><span class="font-medium">{{ a.label }}</span><span class="tabular-nums"><strong>{{ n(a.count) }}</strong></span></div>
                                    <div class="h-2.5 overflow-hidden rounded-full bg-surface-muted">
                                        <div class="h-full rounded-full transition-all duration-500 motion-reduce:transition-none" :class="i >= 2 ? 'bg-danger' : i === 1 ? 'bg-warning' : 'bg-success'" :style="{ width: `${agingTotal ? (a.count / agingTotal) * 100 : 0}%` }" />
                                    </div>
                                </li>
                            </ul>
                        </Card>
                    </div>
                </div>
            </div>

            <!-- Offices, agencies and positions -->
            <div v-show="tab === 'sources'" class="space-y-5" role="tabpanel">
                <div class="grid gap-5 lg:grid-cols-2">
                    <Card title="Field Offices" icon="building" :description="`${s.office_count} with appointments${s.office_count > 12 ? '; the 12 busiest shown' : ''}.`"><RankedBars :rows="s.breakdowns.office" /></Card>
                    <Card title="Agencies" icon="building" :description="`${s.agency_count} agencies${s.agency_count > 10 ? '; the 10 with the most appointments' : ''}.`"><RankedBars :rows="s.breakdowns.agency" /></Card>
                    <Card title="Positions" icon="clipboard" :description="`${s.position_count} different position titles${s.position_count > 10 ? '; the 10 most common' : ''}.`" class="lg:col-span-2"><RankedBars :rows="s.breakdowns.position" /></Card>
                </div>
            </div>
        </template>
    </StaffLayout>
</template>
