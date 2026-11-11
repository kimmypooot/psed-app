<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import Textarea from '@/Components/Forms/Textarea.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';

/**
 * A Field Office submits the monthly reports it generated to PSED, the consolidator. The office uploads
 * the file (the signed copy is the real one); PSED accepts it or returns it with a reason, and a returned
 * report is submitted again. Nothing is lost: every upload is kept.
 */
const props = defineProps({
    office: { type: Object, required: true },
    period: { type: Object, required: true },
    years: { type: Array, required: true },
    reports: { type: Array, required: true },
    returned: { type: Array, default: () => [] },
    readiness: { type: Object, default: null },
});

const monthName = (m) => new Date(2000, m - 1, 1).toLocaleString(undefined, { month: 'long' });
const months = Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: monthName(i + 1) }));
const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));

const month = ref(String(props.period.month));
const year = ref(String(props.period.year));
const go = () => router.get('/reports/submit', { year: year.value, month: month.value }, { preserveScroll: true, replace: true });
const open = (r) => router.get('/reports/submit', { year: r.year, month: r.month }, { preserveScroll: true });

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) : '');
const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');
const dueOf = (r) => formatDate(`${r.due}T00:00:00`);

const tone = { submitted: 'info', accepted: 'success', returned: 'danger' };

// --- Submit dialog ---------------------------------------------------------
const submitting = ref(null);
const fileKey = ref(0);
const form = useForm({ type: '', year: '', month: '', file: null, note: '' });

const openSubmit = (report) => {
    submitting.value = report;
    form.reset();
    form.clearErrors();
    form.type = report.type;
    form.year = props.period.year;
    form.month = props.period.month;
    fileKey.value++;
};
const close = () => (submitting.value = null);
const send = () => form.post('/reports/submit', { forceFormData: true, preserveScroll: true, onSuccess: close });

const waiting = computed(() => props.reports.filter((r) => r.current === null).length);
</script>

<template>
    <Head title="Submit Reports" />

    <StaffLayout>
        <PageHeader
            title="Submit Reports"
            :description="`Send the monthly reports of ${office.name} to PSED, which consolidates them. Generate each one first, then upload the file here.`"
            :breadcrumbs="[{ label: 'Reports' }, { label: 'Submit Reports' }]"
        >
            <template #actions>
                <Button variant="outline" href="/reports"><Icon name="documentText" size="sm" />Generate Reports</Button>
            </template>
        </PageHeader>

        <Alert v-if="returned.length" tone="danger" class="mb-4" title="PSED returned some reports for correction">
            <ul class="mt-1 space-y-1">
                <li v-for="r in returned" :key="r.id">
                    <button type="button" class="font-medium underline" @click="open(r)">{{ r.label }}, {{ monthName(r.month) }} {{ r.year }}</button>:
                    {{ r.review_note }}
                </li>
            </ul>
        </Alert>

        <Card class="mb-4">
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-44">
                    <FormGroup id="s-month" label="Month">
                        <Combobox id="s-month" v-model="month" :options="months" :allow-custom="false" @update:model-value="go" />
                    </FormGroup>
                </div>
                <div class="w-44">
                    <FormGroup id="s-year" label="Year">
                        <Combobox id="s-year" v-model="year" :options="yearOptions" :allow-custom="false" @update:model-value="go" />
                    </FormGroup>
                </div>
                <p class="pb-2 text-sm text-text-muted">Each report shows when it is due.</p>
            </div>
        </Card>

        <Alert v-if="readiness && (readiness.with_errors || readiness.with_warnings)" tone="warning" class="mb-4" title="Check the data before submitting">
            {{ readiness.with_errors }} of {{ readiness.checked }} appointments of {{ monthName(period.month) }} have missing details that print as "N/A" or skew a figure
            <template v-if="readiness.with_warnings">, and {{ readiness.with_warnings }} more have notes</template>.
            Correct them, generate the report again, then submit it. You can still submit as it is.
        </Alert>

        <div class="grid gap-4 md:grid-cols-2">
            <Card v-for="r in reports" :key="r.type" :title="r.label" icon="documentText" :description="r.full_name">
                <div class="space-y-3">
                    <template v-if="r.current">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :tone="tone[r.current.status]">{{ r.current.status_label }}</Badge>
                            <span class="text-xs text-text-muted">{{ formatDateTime(r.current.submitted_at) }}<template v-if="r.current.submitted_by"> · {{ r.current.submitted_by }}</template></span>
                        </div>
                        <p class="flex items-center gap-2 text-sm">
                            <Icon name="documentText" size="sm" class="text-success" />
                            <a :href="`/reports/submissions/${r.current.id}/file`" class="truncate font-medium text-primary hover:underline">{{ r.current.file_name }}</a>
                            <span class="shrink-0 text-xs text-text-muted">{{ r.current.file_size }}</span>
                        </p>
                        <p v-if="r.current.note" class="rounded-md bg-surface-muted p-2 text-sm text-text-muted">Your note: {{ r.current.note }}</p>
                        <Alert v-if="r.current.status === 'returned'" tone="danger" title="Returned for correction">
                            {{ r.current.review_note }}
                            <span class="mt-1 block text-xs">{{ r.current.reviewed_by }}, {{ formatDateTime(r.current.reviewed_at) }}</span>
                        </Alert>
                        <p v-else-if="r.current.status === 'accepted'" class="text-sm text-success">
                            Accepted by {{ r.current.reviewed_by }}, {{ formatDateTime(r.current.reviewed_at) }}.
                        </p>
                    </template>
                    <div v-else class="flex items-start gap-3 rounded-lg border border-dashed p-3" :class="r.overdue ? 'border-danger-border bg-danger-bg/40' : 'border-border-strong'">
                        <Icon :name="r.overdue ? 'exclamation' : 'clock'" size="md" class="mt-0.5" :class="r.overdue ? 'text-danger' : 'text-text-muted'" />
                        <div class="min-w-0 text-sm">
                            <p class="font-medium" :class="r.overdue ? 'text-danger' : 'text-text'">{{ r.overdue ? 'Overdue' : 'Not submitted yet' }}</p>
                            <p class="text-text-muted">
                                <template v-if="r.overdue">This was due {{ dueOf(r) }}. Submit it as soon as it is ready.</template>
                                <template v-else>Due {{ dueOf(r) }}.</template>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="r.can_submit" :variant="r.current && r.current.status !== 'returned' ? 'outline' : 'success'" size="sm" @click="openSubmit(r)">
                            <Icon name="upload" size="sm" />{{ r.current ? 'Submit again' : 'Submit' }}
                        </Button>
                        <span v-else class="text-xs text-text-muted">Accepted reports are not replaced. Ask PSED to return it first.</span>
                    </div>

                    <details v-if="r.earlier.length" class="text-sm">
                        <summary class="cursor-pointer text-xs font-medium text-primary">Earlier submissions ({{ r.earlier.length }})</summary>
                        <ul class="mt-2 space-y-2">
                            <li v-for="e in r.earlier" :key="e.id" class="rounded-md border border-border p-2">
                                <a :href="`/reports/submissions/${e.id}/file`" class="font-medium text-primary hover:underline">{{ e.file_name }}</a>
                                <span class="block text-xs text-text-muted">{{ formatDateTime(e.submitted_at) }} · {{ e.status_label }}</span>
                                <span v-if="e.review_note" class="block text-xs text-danger">{{ e.review_note }}</span>
                            </li>
                        </ul>
                    </details>
                </div>
            </Card>
        </div>

        <Modal :open="submitting !== null" :title="submitting ? `Submit ${submitting.label}` : ''" icon="upload" size="lg" @close="close">
            <form v-if="submitting" class="space-y-4" @submit.prevent="send">
                <p class="text-sm text-text-muted">
                    {{ submitting.full_name }}, <strong class="text-text">{{ monthName(period.month) }} {{ period.year }}</strong>, for {{ office.name }}.
                    Upload the file you generated from <em>Generate Reports</em>, signed if your office signs it.
                </p>

                <FormGroup id="submit-file" label="Report file (.xlsx)" required :error="form.errors.file">
                    <FileInput id="submit-file" :key="fileKey" accept=".xlsx" :max-size-mb="10" :invalid="!!form.errors.file" @update:model-value="form.file = $event" />
                </FormGroup>

                <FormGroup id="submit-note" label="Note for PSED (optional)" :error="form.errors.note">
                    <Textarea id="submit-note" v-model="form.note" :rows="3" />
                </FormGroup>

                <Alert v-if="form.errors.month || form.errors.type || form.errors.year" tone="danger">
                    {{ form.errors.month || form.errors.type || form.errors.year }}
                </Alert>
                <Alert v-if="submitting.current && submitting.can_submit" tone="info">
                    This replaces the report you submitted on {{ formatDateTime(submitting.current.submitted_at) }}. The earlier file is kept.
                </Alert>

                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="secondary" @click="close">Cancel</Button>
                    <Button type="submit" variant="success" :loading="form.processing" :disabled="!form.file"><Icon name="upload" size="sm" />Submit to PSED</Button>
                </div>
            </form>
        </Modal>
    </StaffLayout>
</template>
