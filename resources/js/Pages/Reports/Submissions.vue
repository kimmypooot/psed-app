<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Table from '@/Components/UI/Table.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import Textarea from '@/Components/Forms/Textarea.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';

/**
 * PSED, the consolidator: which Field Offices have submitted which report for the month. Open a
 * submission to download it, accept it, or return it to the office with what to correct.
 */
const props = defineProps({
    period: { type: Object, required: true },
    years: { type: Array, required: true },
    types: { type: Array, required: true },
    offices: { type: Array, required: true },
    summary: { type: Object, required: true },
});

const monthName = (m) => new Date(2000, m - 1, 1).toLocaleString(undefined, { month: 'long' });
const months = Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: monthName(i + 1) }));
const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));

const month = ref(String(props.period.month));
const year = ref(String(props.period.year));
const go = () => router.get('/reports/submissions', { year: year.value, month: month.value }, { preserveScroll: true, replace: true });

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) : '');
const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');
const shortDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: "short", day: "numeric" });
const downloadUrl = computed(() => `/reports/submissions/download?year=${props.period.year}&month=${props.period.month}`);

const tone = { submitted: 'info', accepted: 'success', returned: 'danger' };
const typeLabel = (value) => props.types.find((t) => t.value === value)?.label ?? value;

// --- Review dialog ---------------------------------------------------------
const reviewing = ref(null); // { office, cell }
const returning = ref(false);
const acting = ref(false);
const form = useForm({ reason: '' });

// Kept in step with the page props, so the dialog shows the result of accepting or returning.
const current = computed(() => {
    if (!reviewing.value) return null;
    const office = props.offices.find((o) => o.id === reviewing.value.office.id);

    return office ? { office, cell: office.cells.find((c) => c.type === reviewing.value.cell.type) } : null;
});

const review = (office, cell) => {
    reviewing.value = { office, cell };
    returning.value = false;
    form.reset();
    form.clearErrors();
};
const close = () => (reviewing.value = null);

const accept = () => {
    acting.value = true;
    router.post(`/reports/submissions/${current.value.cell.current.id}/accept`, {}, { preserveScroll: true, onFinish: () => (acting.value = false) });
};
const sendBack = () =>
    form.post(`/reports/submissions/${current.value.cell.current.id}/return`, { preserveScroll: true, onSuccess: () => (returning.value = false) });
</script>

<template>
    <Head title="Report Submissions" />

    <StaffLayout>
        <PageHeader
            title="Report Submissions"
            description="The monthly reports the Field Offices submitted to PSED. Open one to download it, accept it, or return it for correction."
            :breadcrumbs="[{ label: 'Reports' }, { label: 'Report Submissions' }]"
        >
            <template #actions>
                <Button variant="outline" :href="downloadUrl" external :disabled="summary.received === 0"><Icon name="download" size="sm" />Download all (zip)</Button>
            </template>
        </PageHeader>

        <Card class="mb-4">
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-44">
                    <FormGroup id="m-month" label="Month">
                        <Combobox id="m-month" v-model="month" :options="months" :allow-custom="false" @update:model-value="go" />
                    </FormGroup>
                </div>
                <div class="w-44">
                    <FormGroup id="m-year" label="Year">
                        <Combobox id="m-year" v-model="year" :options="yearOptions" :allow-custom="false" @update:model-value="go" />
                    </FormGroup>
                </div>
                <p class="pb-2 text-sm text-text-muted">Each report's deadline is under its name in the table.</p>
            </div>
        </Card>

        <dl class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-border p-3">
                <dt class="text-xs text-text-muted">Received</dt>
                <dd class="text-2xl font-bold">{{ summary.received }}<span class="text-sm font-normal text-text-muted"> of {{ summary.expected }}</span></dd>
            </div>
            <div class="rounded-lg border border-info-border bg-info-bg p-3">
                <dt class="text-xs text-info">Waiting for review</dt>
                <dd class="text-2xl font-bold text-info">{{ summary.waiting }}</dd>
            </div>
            <div class="rounded-lg border border-success-border bg-success-bg p-3">
                <dt class="text-xs text-success">Accepted</dt>
                <dd class="text-2xl font-bold text-success">{{ summary.accepted }}</dd>
            </div>
            <div class="rounded-lg border border-danger-border bg-danger-bg p-3">
                <dt class="text-xs text-danger">Returned</dt>
                <dd class="text-2xl font-bold text-danger">{{ summary.returned }}</dd>
            </div>
        </dl>

        <Table>
            <thead>
                <tr>
                    <th scope="col">Field Office</th>
                    <th v-for="t in types" :key="t.value" scope="col">{{ t.label }}<span class="block text-xs font-normal text-text-muted">due {{ shortDate(t.due) }}</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="o in offices" :key="o.id">
                    <td class="font-medium">{{ o.name }}</td>
                    <td v-for="c in o.cells" :key="c.type">
                        <button
                            v-if="c.current"
                            type="button"
                            class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                            :aria-label="`${typeLabel(c.type)} of ${o.name}: ${c.current.status_label}`"
                            @click="review(o, c)"
                        >
                            <Badge :tone="tone[c.current.status]">{{ c.current.status_label.split(' ')[0] }}</Badge>
                        </button>
                        <Badge v-else :tone="c.overdue ? 'danger' : 'neutral'">{{ c.overdue ? 'Overdue' : 'Not yet' }}</Badge>
                    </td>
                </tr>
            </tbody>
        </Table>

        <Modal
            :open="current !== null"
            :title="current ? `${typeLabel(current.cell.type)}: ${current.office.name}` : ''"
            icon="documentText"
            size="lg"
            @close="close"
        >
            <div v-if="current && current.cell.current" class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :tone="tone[current.cell.current.status]">{{ current.cell.current.status_label }}</Badge>
                    <span class="text-sm text-text-muted">{{ monthName(period.month) }} {{ period.year }}</span>
                </div>

                <div class="rounded-lg border border-border p-3">
                    <p class="flex items-center gap-2 text-sm">
                        <Icon name="documentText" size="sm" class="text-success" />
                        <span class="truncate font-medium">{{ current.cell.current.file_name }}</span>
                        <span class="shrink-0 text-xs text-text-muted">{{ current.cell.current.file_size }}</span>
                    </p>
                    <p class="mt-1 text-xs text-text-muted">
                        Submitted {{ formatDateTime(current.cell.current.submitted_at) }}<template v-if="current.cell.current.submitted_by"> by {{ current.cell.current.submitted_by }}</template>
                    </p>
                    <Button class="mt-3" variant="outline" size="sm" :href="`/reports/submissions/${current.cell.current.id}/file`" external><Icon name="download" size="sm" />Download</Button>
                </div>

                <p v-if="current.cell.current.note" class="rounded-md bg-surface-muted p-3 text-sm"><span class="text-text-muted">Note from the office:</span> {{ current.cell.current.note }}</p>

                <Alert v-if="current.cell.current.status === 'returned'" tone="danger" title="Returned for correction">
                    {{ current.cell.current.review_note }}
                    <span class="mt-1 block text-xs">{{ current.cell.current.reviewed_by }}, {{ formatDateTime(current.cell.current.reviewed_at) }}. Waiting for the office to submit it again.</span>
                </Alert>
                <p v-else-if="current.cell.current.status === 'accepted'" class="text-sm text-success">
                    Accepted by {{ current.cell.current.reviewed_by }}, {{ formatDateTime(current.cell.current.reviewed_at) }}.
                </p>

                <form v-if="returning" class="space-y-3 rounded-lg border border-danger-border p-3" @submit.prevent="sendBack">
                    <FormGroup id="return-reason" label="What does the office need to correct?" required :error="form.errors.reason">
                        <Textarea id="return-reason" v-model="form.reason" :rows="3" />
                    </FormGroup>
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" size="sm" @click="returning = false">Cancel</Button>
                        <Button type="submit" variant="danger" size="sm" :loading="form.processing">Return to the office</Button>
                    </div>
                </form>

                <details v-if="current.cell.earlier.length" class="text-sm">
                    <summary class="cursor-pointer text-xs font-medium text-primary">Earlier submissions ({{ current.cell.earlier.length }})</summary>
                    <ul class="mt-2 space-y-2">
                        <li v-for="e in current.cell.earlier" :key="e.id" class="rounded-md border border-border p-2">
                            <a :href="`/reports/submissions/${e.id}/file`" class="font-medium text-primary hover:underline">{{ e.file_name }}</a>
                            <span class="block text-xs text-text-muted">{{ formatDateTime(e.submitted_at) }} · {{ e.status_label }}</span>
                            <span v-if="e.review_note" class="block text-xs text-danger">{{ e.review_note }}</span>
                        </li>
                    </ul>
                </details>
            </div>

            <template #footer>
                <Button variant="secondary" @click="close">Close</Button>
                <template v-if="current && current.cell.current && !returning">
                    <Button v-if="current.cell.current.status !== 'returned'" variant="outline" @click="returning = true">Return</Button>
                    <Button v-if="current.cell.current.status === 'submitted'" variant="success" :loading="acting" @click="accept"><Icon name="check" size="sm" />Accept</Button>
                </template>
            </template>
        </Modal>
    </StaffLayout>
</template>
