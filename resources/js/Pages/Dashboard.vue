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
import ProgressBar from '@/Components/UI/ProgressBar.vue';

const props = defineProps({
    generatedAt: { type: String, required: true },
    period: { type: String, required: true },
    stats: { type: Object, required: true },
    trend: { type: Array, required: true },
    recent: { type: Array, required: true },
    /** The oldest appointments still waiting for action. */
    waiting: { type: Array, default: () => [] },
    /** A Field Office's balance carried into the year, and how much of it is still waiting (null for PSED, or when there is none). */
    balance: { type: Object, default: null },
    /** This month, as the appointments list's date filter takes it. */
    month: { type: Object, required: true },
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
// A figure that leads to the list behind it.
const cardLink = 'group block h-full rounded-lg transition-shadow hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
const queueLink = 'block rounded-md p-1.5 -m-1.5 transition-colors hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
const queueTotal = computed(() => Math.max(1, props.stats.field_office_queue + props.stats.psed_queue));

const formatDate = (value) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';

const actionTone = (action) =>
    ({ Approved: 'success', Validated: 'success', Disapproved: 'danger', Invalidated: 'danger', Pending: 'info' })[action ?? 'Pending'] ?? 'neutral';
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
            <!-- The two figures the list can show exactly are links to it -->
            <Link :href="`/appointments?date_from=${month.from}&date_to=${month.to}`" :class="cardLink">
                <MetricCard label="Received" :value="stats.received" icon="download" tone="primary">
                    appointments received this month
                    <span class="mt-1 flex items-center gap-1 font-medium text-primary">See them<Icon name="arrowRight" size="sm" /></span>
                </MetricCard>
            </Link>
            <MetricCard label="Acted upon" :value="stats.acted" icon="checkCircle" tone="success">approved, validated, disapproved or invalidated</MetricCard>
            <Link href="/appointments?status=pending" :class="cardLink">
                <MetricCard label="Backlog" :value="stats.backlog" icon="clock" :tone="stats.backlog ? 'warning' : 'success'">
                    still waiting for action
                    <span class="mt-1 flex items-center gap-1 font-medium text-primary">See what is pending<Icon name="arrowRight" size="sm" /></span>
                </MetricCard>
            </Link>
            <MetricCard label="First-time entrants" :value="stats.first_time" icon="userPlus" tone="info">absorbed this month</MetricCard>
        </div>

        <!-- What this office carried into the year, and how much of it is still waiting -->
        <Card
            v-if="balance"
            class="mt-4"
            :title="`Carried over from ${balance.year - 1}`"
            icon="archive"
            :description="`Appointments still unacted on December 31, ${balance.year - 1}, and how many of them have been acted on since.`"
        >
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-text-muted">Opening balance</dt>
                    <dd class="text-2xl font-bold tabular-nums">{{ balance.value }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Acted on since January</dt>
                    <dd class="text-2xl font-bold text-success tabular-nums">{{ balance.acted }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Still waiting</dt>
                    <dd class="text-2xl font-bold tabular-nums" :class="balance.remaining ? 'text-primary' : 'text-success'">{{ balance.remaining }}</dd>
                </div>
            </dl>
            <div class="mt-4">
                <ProgressBar :value="balance.value ? Math.round((balance.acted / balance.value) * 100) : 100" label="Share of the opening balance acted on" tone="success" />
            </div>
            <p class="mt-3 text-xs text-text-muted">
                <template v-if="balance.source === 'psed'">Set by PSED<span v-if="balance.set_by"> ({{ balance.set_by }})</span><span v-if="balance.set_at"> on {{ formatDate(balance.set_at.slice(0, 10)) }}</span>.</template>
                <template v-else>PSED has not set this balance, so it is counted from the appointments in CARES.</template>
                <span v-if="balance.note"> Note: {{ balance.note }}</span>
            </p>
        </Card>

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
                    <Link href="/appointments?status=pending&processor=field_office" :class="queueLink">
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-text">Field Office</span>
                            <span class="text-text-muted">{{ stats.field_office_queue }} pending</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-csc-blue-light" :style="{ width: `${(stats.field_office_queue / queueTotal) * 100}%` }" /></div>
                    </Link>
                    <Link href="/appointments?status=pending&processor=psed" :class="queueLink">
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-text">PSED</span>
                            <span class="text-text-muted">{{ stats.psed_queue }} pending</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-chart-2" :style="{ width: `${(stats.psed_queue / queueTotal) * 100}%` }" /></div>
                    </Link>
                </div>
                <p v-if="rule" class="mt-5 rounded-md bg-info-bg p-3 text-xs leading-relaxed text-info">
                    <strong>{{ rule.office }}</strong> ({{ rule.head_label }}): appointments of SG {{ rule.psed_from_sg }} and above are processed by PSED, the rest by the Field Office.
                </p>
            </Card>
        </div>

        <!-- The next thing to pick up -->
        <Card title="Waiting longest" icon="clock" description="The oldest appointments still waiting for action." class="mt-4">
            <template #actions>
                <Link href="/appointments?status=pending&sort=date_received&dir=asc" class="text-sm font-medium text-primary hover:underline">See all pending</Link>
            </template>

            <p v-if="!waiting.length" class="flex items-center justify-center gap-2 py-6 text-sm text-success">
                <Icon name="checkCircle" size="sm" />Nothing is waiting. Every appointment has been acted upon.
            </p>

            <Table v-else>
                <thead>
                    <tr>
                        <th scope="col">Appointee</th>
                        <th scope="col">Position</th>
                        <th scope="col">Received</th>
                        <th scope="col" class="text-right">Waiting</th>
                        <th scope="col">Processed by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in waiting" :key="a.id">
                        <td>
                            <Link :href="`/appointments/${a.id}/edit`" class="font-medium text-primary hover:underline">{{ a.full_name }}</Link>
                            <div class="text-xs text-text-muted">{{ a.agency }}<span v-if="offices.length"> · {{ a.office }}</span></div>
                        </td>
                        <td class="text-xs">{{ a.position }} <span class="text-text-muted">(SG {{ a.salary_grade }})</span></td>
                        <td class="text-xs whitespace-nowrap">{{ formatDate(a.date_received) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <template v-if="a.days === 0">Today</template>
                            <template v-else><span class="font-semibold tabular-nums">{{ a.days }}</span> <span class="text-xs text-text-muted">{{ a.days === 1 ? 'day' : 'days' }}</span></template>
                        </td>
                        <td><Badge :tone="a.processed_by === 'psed' ? 'info' : 'neutral'">{{ a.processed_by_label }}</Badge></td>
                    </tr>
                </tbody>
            </Table>
        </Card>

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
                        <td><Badge :tone="actionTone(a.csc_action)">{{ a.csc_action ?? 'Pending' }}</Badge></td>
                    </tr>
                </tbody>
            </Table>
        </Card>
    </StaffLayout>
</template>
