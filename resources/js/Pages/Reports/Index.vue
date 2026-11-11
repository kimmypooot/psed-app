<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import Badge from '@/Components/UI/Badge.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import { request, errorMessage } from '@/Utils/http';

const props = defineProps({
    types: { type: Array, required: true },
    offices: { type: Array, default: () => [] },
    office: { type: Object, default: null },
    defaults: { type: Object, required: true },
    years: { type: Array, required: true },
});

const page = usePage();
const seesAllOffices = computed(() => page.props.auth.user.sees_all_offices);

const type = ref(props.types[0].value);
const month = ref(String(props.defaults.month));
const year = ref(String(props.defaults.year));
const officeId = ref(props.defaults.field_office_id ? String(props.defaults.field_office_id) : '');
const consolidated = computed(() => seesAllOffices.value && officeId.value === 'all');

const months = Array.from({ length: 12 }, (_, i) => ({
    value: String(i + 1),
    label: new Date(2000, i, 1).toLocaleString('en', { month: 'long' }),
}));
const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));
// PSED can prepare each office's report, or the consolidated one of every Field Office.
const officeOptions = computed(() => [
    { value: 'all', label: 'All Field Offices (consolidated)' },
    ...props.offices.map((o) => ({ value: String(o.id), label: o.name })),
]);

const selected = computed(() => props.types.find((t) => t.value === type.value));
const ready = computed(() => !seesAllOffices.value || officeId.value !== '');

// The file is a plain download, so the browser handles it (no Inertia visit).
// A missing template comes back as a redirect to this page with the reason.
const downloading = ref(false);
const download = () => {
    const query = new URLSearchParams({ type: type.value, month: month.value, year: year.value });
    if (seesAllOffices.value) query.set('field_office_id', officeId.value);

    downloading.value = true;
    window.location.assign(`/reports/download?${query}`);
    window.setTimeout(() => (downloading.value = false), 4000);
};
// --- Check the data before generating --------------------------------------------------
// The four reports read the same appointments, so one check covers them: what would print as
// "N/A" or throw a figure off. It changes nothing; each finding links to the appointment.
const checking = ref(false);
const result = ref(null);
const checkError = ref(null);

const monthLabel = computed(() => months.find((m) => m.value === month.value)?.label ?? '');

const runCheck = async () => {
    const query = new URLSearchParams({ month: month.value, year: year.value });
    if (seesAllOffices.value) query.set('field_office_id', officeId.value);

    checking.value = true;
    checkError.value = null;
    const response = await request('GET', `/reports/check?${query}`);
    checking.value = false;

    if (response.ok) {
        result.value = { ...response.data, monthLabel: monthLabel.value, year: year.value };
    } else {
        result.value = null;
        checkError.value = errorMessage(response);
    }
};

// A new choice makes the old findings stale.
watch([month, year, officeId], () => {
    result.value = null;
    checkError.value = null;
});
</script>

<template>
    <Head title="Generate Reports" />

    <StaffLayout>
        <PageHeader
            title="Generate Reports"
            description="Each report is filled into the CSC's official Excel template, so the headers, logos, merged cells and formulas stay exactly as the office uses them."
        />

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="1. Choose the report" icon="documentText" class="lg:col-span-2">
                <fieldset>
                    <legend class="sr-only">Report type</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="t in types"
                            :key="t.value"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-focus-ring"
                            :class="type === t.value ? 'border-primary bg-info-bg' : 'border-border hover:border-primary/40'"
                        >
                            <input v-model="type" type="radio" name="report-type" :value="t.value" class="mt-1 size-4 accent-primary focus:outline-none" />
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-text">{{ t.label }}</span>
                                <span class="block text-xs text-text-muted">{{ t.full_name }}</span>
                            </span>
                        </label>
                    </div>
                </fieldset>
                <p v-if="selected" class="mt-4 rounded-md bg-surface-muted p-3 text-sm text-text-muted">{{ selected.description }}</p>
            </Card>

            <Card title="2. Month and office" icon="calendar">
                <div class="space-y-4">
                    <FormGroup v-if="seesAllOffices" id="r-office" label="Field Office" required>
                        <Combobox :allow-custom="false" id="r-office" v-model="officeId" :options="officeOptions" required />
                    </FormGroup>
                    <Alert v-if="consolidated" tone="info">
                        Counts every Field Office. The IPMR and MRAP are computed for each month from January to the one chosen, so months before appointments were encoded here show zero. Signature lines are left blank.
                    </Alert>
                    <p v-else-if="office" class="text-sm"><span class="text-text-muted">Field Office:</span> <strong>{{ office.name }}</strong></p>

                    <div class="grid grid-cols-2 gap-3">
                        <FormGroup id="r-month" label="Month" required>
                            <Combobox :allow-custom="false" id="r-month" v-model="month" :options="months" required />
                        </FormGroup>
                        <FormGroup id="r-year" label="Year" required>
                            <Combobox :allow-custom="false" id="r-year" v-model="year" :options="yearOptions" required />
                        </FormGroup>
                    </div>

                    <Button variant="success" class="w-full" :disabled="!ready" :loading="downloading" @click="download">
                        <Icon name="download" size="sm" />
                        Download {{ selected?.label }} (.xlsx)
                    </Button>

                    <Button variant="secondary" class="w-full" :disabled="!ready" :loading="checking" @click="runCheck">
                        <Icon name="checkCircle" size="sm" />
                        Check the data first
                    </Button>                </div>
            </Card>
        </div>

        <Alert v-if="checkError" tone="danger" class="mt-4">{{ checkError }}</Alert>

        <Card v-if="result" class="mt-4" :title="`Data check: ${result.monthLabel} ${result.year}`" icon="checkCircle" :description="`${result.checked} appointment${result.checked === 1 ? '' : 's'} go into this month's reports.`">
            <Alert v-if="!result.issues.length" tone="success">
                Nothing to fix. Every appointment has the details the four reports ask for.
            </Alert>

            <template v-else>
                <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                    <Badge v-if="result.with_errors" tone="danger">{{ result.with_errors }} with errors</Badge>
                    <Badge v-if="result.with_warnings" tone="warning">{{ result.with_warnings }} with warnings</Badge>
                    <span class="text-text-muted">Errors make a figure wrong; warnings leave an "N/A" in a report. You can still download.</span>
                </div>

                <ul class="divide-y divide-border rounded-md border border-border">
                    <li v-for="issue in result.issues" :key="issue.id" class="flex flex-wrap items-start justify-between gap-3 p-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-text">
                                {{ issue.name }}
                                <span v-if="seesAllOffices && issue.office" class="font-normal text-text-muted"> · {{ issue.office }}</span>
                            </p>
                            <ul class="mt-1.5 space-y-1">
                                <li v-for="(problem, i) in issue.problems" :key="i" class="flex flex-wrap items-start gap-x-2 gap-y-1 text-sm">
                                    <Badge :tone="problem.severity === 'error' ? 'danger' : 'warning'">{{ problem.severity === 'error' ? 'Error' : 'Warning' }}</Badge>
                                    <span class="text-text">{{ problem.message }}</span>
                                    <span class="text-xs text-text-muted">({{ problem.affects.join(', ') }})</span>
                                </li>
                            </ul>
                        </div>
                        <Link :href="`/appointments/${issue.id}/edit`" class="shrink-0 text-sm font-medium text-primary hover:underline">Fix it</Link>
                    </li>
                </ul>

                <p v-if="result.shown < result.with_errors + result.with_warnings" class="mt-3 text-xs text-text-muted">
                    Showing the first {{ result.shown }} of {{ result.with_errors + result.with_warnings }} appointments.
                </p>
            </template>
        </Card>
        <Alert tone="info" class="mt-4" title="What goes into the reports">
            Figures come from the appointments encoded here. Those acted upon are counted in the month of their date of action, so an appointment received in September and approved in October appears in October's reports.
        </Alert>
    </StaffLayout>
</template>
