<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import DashboardBanner from '@/Components/Admin/DashboardBanner.vue';
import MetricCard from '@/Components/Admin/MetricCard.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Icon from '@/Components/UI/Icon.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps({
    generatedAt: { type: String, required: true },
    period: { type: String, required: true },
    stats: { type: Object, required: true },
    trend: { type: Array, required: true },
    recent: { type: Array, required: true },
    rule: { type: Object, default: null },
    offices: { type: Array, default: () => [] },
});

const page = usePage();
const roleLabel = computed(() => page.props.auth.user.role_label);

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ onFinish: () => (refreshing.value = false) });
};

const maxBar = computed(() => Math.max(1, ...props.trend.flatMap((m) => [m.received, m.acted])));
const queueTotal = computed(() => Math.max(1, props.stats.field_office_queue + props.stats.psed_queue));

const formatDate = (value) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';

const actionTone = (action) =>
    ({ Approved: 'success', Validated: 'success', Disapproved: 'danger', Invalidated: 'danger', Pending: 'warning' })[action] ?? 'neutral';
</script>

<template>
    <Head title="Dashboard" />

    <StaffLayout>
        <DashboardBanner
            :badge="roleLabel"
            badge-icon="clipboard"
            :actions="[
                { label: 'Encode appointment', href: '/appointments/create', icon: 'plus' },
                { label: 'Generate reports', href: '/reports', icon: 'documentText' },
            ]"
            :generated-at="generatedAt"
            :refreshing="refreshing"
            @refresh="refresh"
        />

        <p class="mb-3 text-xs font-semibold tracking-wide text-text-muted uppercase">{{ period }}</p>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard label="Received" :value="stats.received" icon="download" tone="primary">appointments received this month</MetricCard>
            <MetricCard label="Acted upon" :value="stats.acted" icon="checkCircle" tone="success">approved, validated, disapproved or invalidated</MetricCard>
            <MetricCard label="Backlog" :value="stats.backlog" icon="clock" :tone="stats.backlog ? 'warning' : 'success'">still waiting for action</MetricCard>
            <MetricCard label="First-time entrants" :value="stats.first_time" icon="userPlus" tone="info">absorbed this month</MetricCard>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <Card title="Received and acted, by month" icon="chartBar" class="lg:col-span-2">
                <div class="flex h-48 items-end gap-2" role="img" :aria-label="`Appointments received and acted upon in each month of ${period.split(' ')[1]}`">
                    <div v-for="m in trend" :key="m.month" class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1">
                        <div class="flex flex-1 items-end justify-center gap-0.5">
                            <div class="w-1/2 rounded-t bg-chart-1" :style="{ height: `${(m.received / maxBar) * 100}%`, minHeight: m.received ? '2px' : '0' }" :title="`${m.received} received`" />
                            <div class="w-1/2 rounded-t bg-chart-2" :style="{ height: `${(m.acted / maxBar) * 100}%`, minHeight: m.acted ? '2px' : '0' }" :title="`${m.acted} acted`" />
                        </div>
                        <span class="text-center text-[10px] text-text-muted">{{ m.month }}</span>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-4 text-xs text-text-muted">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-chart-1" />Received</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-chart-2" />Acted upon</span>
                </div>
            </Card>

            <Card title="Who has the backlog" icon="building">
                <div class="space-y-4">
                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-text">Field Office</span>
                            <span class="text-text-muted">{{ stats.field_office_queue }} pending</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-csc-blue-light" :style="{ width: `${(stats.field_office_queue / queueTotal) * 100}%` }" /></div>
                    </div>
                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-text">PSED</span>
                            <span class="text-text-muted">{{ stats.psed_queue }} pending</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-chart-2" :style="{ width: `${(stats.psed_queue / queueTotal) * 100}%` }" /></div>
                    </div>
                </div>
                <p v-if="rule" class="mt-5 rounded-md bg-info-bg p-3 text-xs leading-relaxed text-info">
                    <strong>{{ rule.office }}</strong> ({{ rule.head_label }}): appointments of SG {{ rule.psed_from_sg }} and above are processed by PSED, the rest by the Field Office.
                </p>
            </Card>
        </div>

        <Card v-if="offices.length" title="Field Offices" icon="building" description="This month, and what is waiting to be processed." class="mt-4">
            <Table>
                <thead>
                    <tr>
                        <th scope="col">Office</th>
                        <th scope="col">Routing rule</th>
                        <th scope="col" class="text-right">Received</th>
                        <th scope="col" class="text-right">Acted</th>
                        <th scope="col" class="text-right">Field Office backlog</th>
                        <th scope="col" class="text-right">PSED backlog</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in offices" :key="o.id">
                        <td class="font-medium">{{ o.name }}</td>
                        <td class="text-text-muted">PSED from SG {{ o.psed_from_sg }}</td>
                        <td class="text-right tabular-nums">{{ o.received }}</td>
                        <td class="text-right tabular-nums">{{ o.acted }}</td>
                        <td class="text-right tabular-nums">{{ o.fo_backlog }}</td>
                        <td class="text-right tabular-nums">{{ o.psed_backlog }}</td>
                    </tr>
                </tbody>
            </Table>
        </Card>

        <Card title="Recently encoded" icon="clipboard" class="mt-4">
            <template #actions>
                <Link href="/appointments" class="text-sm font-medium text-primary hover:underline">View all</Link>
            </template>

            <p v-if="!recent.length" class="py-6 text-center text-sm text-text-muted">No appointments have been encoded yet.</p>

            <Table v-else>
                <thead>
                    <tr>
                        <th scope="col">Appointee</th>
                        <th scope="col">Position</th>
                        <th scope="col">Received</th>
                        <th scope="col">Processed by</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in recent" :key="a.id">
                        <td>
                            <Link :href="`/appointments/${a.id}/edit`" class="font-medium text-primary hover:underline">{{ a.full_name }}</Link>
                            <div class="text-xs text-text-muted">{{ a.agency }}<span v-if="offices.length"> · {{ a.office }}</span></div>
                        </td>
                        <td>{{ a.position }} <span class="text-text-muted">(SG {{ a.salary_grade }})</span></td>
                        <td class="whitespace-nowrap">{{ formatDate(a.date_received) }}</td>
                        <td><Badge :tone="a.processed_by === 'psed' ? 'info' : 'neutral'">{{ a.processed_by_label }}</Badge></td>
                        <td><Badge v-if="a.csc_action" :tone="actionTone(a.csc_action)">{{ a.csc_action }}</Badge><span v-else class="text-text-muted">—</span></td>
                    </tr>
                </tbody>
            </Table>
        </Card>
    </StaffLayout>
</template>
