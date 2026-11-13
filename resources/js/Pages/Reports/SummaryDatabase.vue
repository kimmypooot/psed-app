<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import PeriodPicker from '@/Components/Forms/PeriodPicker.vue';
import { defaultPeriod, periodParams } from '@/Utils/period';
import { request, errorMessage } from '@/Utils/http';

/**
 * The Integrated CSE Applicants and Absorption Database Summary: the CSC's four-sheet workbook, filled in
 * from the appointments approved in a period (a year, quarter, month or any range of dates). What the
 * appointments cannot say (who applied, who was granted an eligibility, Category III / IV, Job Order and
 * Contract of Service) is left in the workbook for PSED to type.
 */
const props = defineProps({
    years: { type: Array, required: true },
    today: { type: String, required: true },
});

const period = reactive(defaultPeriod(props.today));

const query = computed(() => periodParams(period).toString());
const downloadUrl = computed(() => `/reports/summary-database/download?${query.value}`);

// What a download for this period would hold; cleared when the period changes.
const checking = ref(false);
const result = ref(null);
const error = ref(null);
watch(query, () => {
    result.value = null;
    error.value = null;
});

const check = async () => {
    checking.value = true;
    error.value = null;
    const response = await request('GET', `/reports/summary-database/check?${query.value}`);
    checking.value = false;

    if (response.ok) result.value = response.data;
    else error.value = errorMessage(response);
};

const labels = {
    k12_cse_pr: 'Absorbed via CSE-PR',
    k12_skoe: 'Absorbed via SKOE',
    k12_professional: 'Absorbed via CSE Professional',
    k12_subprofessional: 'Absorbed via CSE Sub-Professional',
    k12_other: 'Other means',
    k12_non_permanent: 'Non-permanent positions',
    k12_permanent: 'Regular plantilla',
    abs_cse_pr: 'Absorbed via CSE-PR',
    abs_cs_exam: 'Absorbed via CS Exam',
    abs_other_spel: 'Absorbed via other SPELs',
    skoe_official: 'SK Official',
    skoe_secretary: 'SK Secretary',
    skoe_treasurer: 'SK Treasurer',
    skoe_permanent: 'Absorbed as permanent',
    pwd_absorbed: 'PWD absorbed',
    pwd_non_permanent: 'PWD in non-permanent positions',
    ip_absorbed: 'IP absorbed',
    ip_non_permanent: 'IP in non-permanent positions',
    solo_absorbed: 'Solo parents absorbed',
    solo_non_permanent: 'Solo parents in non-permanent positions',
};
const sheets = computed(() => Object.entries(result.value?.sheets ?? {}).map(([title, totals]) => ({
    title,
    rows: Object.entries(totals).map(([metric, count]) => ({ label: labels[metric] ?? metric, count })),
})));
const unmatchedOffices = computed(() => Object.entries(result.value?.unmatched ?? {}));
</script>

<template>
    <Head title="Summary Database" />

    <StaffLayout>
        <PageHeader
            title="Summary Database"
            description="The Integrated CSE Applicants and Absorption Database Summary for the region, filled in from the appointments approved in the period."
            :breadcrumbs="[{ label: 'Reports' }, { label: 'Summary Database' }]"
        />

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Period" icon="calendar" description="An appointment counts in the period in which it was approved or validated." class="lg:col-span-2">
                <PeriodPicker v-model="period" :years="years" id-prefix="sd" />

                <div class="mt-5 flex flex-wrap gap-3">
                    <Button :href="downloadUrl" external variant="success"><Icon name="download" size="sm" />Download the workbook (.xlsx)</Button>
                    <Button variant="outline" :loading="checking" @click="check"><Icon name="search" size="sm" />Check the data first</Button>
                </div>

                <Alert v-if="error" tone="danger" class="mt-4">{{ error }}</Alert>
            </Card>

            <Card title="What you fill in" icon="info">
                <p class="text-sm text-text-muted">CARES counts what appointments show. The workbook also asks for what they cannot, so these stay blank for PSED to type, and the template works out the rates:</p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-text-muted">
                    <li>Applicants and grantees of each eligibility</li>
                    <li>Job Order and Contract of Service</li>
                    <li>Category III and Category IV</li>
                    <li>The category columns of the JOCOSC6 sheet</li>
                </ul>
            </Card>
        </div>

        <!-- What the download would hold -->
        <div v-if="result" class="mt-4 space-y-4">
            <Alert :tone="result.appointments ? 'info' : 'warning'" :title="`${result.period}: ${result.appointments} appointment${result.appointments === 1 ? '' : 's'} approved`">
                <template v-if="result.appointments">These are what the four sheets are counted from.</template>
                <template v-else>Nothing was approved or validated in this period, so the workbook would be blank.</template>
            </Alert>

            <Alert v-if="unmatchedOffices.length" tone="warning" title="Agencies that are not on the workbook's list">
                They are counted in their Field Office's own row, with a note, so nothing is lost. <Link href="/settings/summary-agencies" class="font-medium text-primary underline">Pair them with a row</Link>, or say they have none.
                <ul class="mt-2 space-y-1">
                    <li v-for="[office, agencies] in unmatchedOffices" :key="office">
                        <strong>{{ office }}:</strong>
                        {{ agencies.map((a) => `${a.agency} (${a.appointments})`).join('; ') }}
                    </li>
                </ul>
            </Alert>

            <div class="grid gap-4 md:grid-cols-2">
                <Card v-for="sheet in sheets" :key="sheet.title" :title="sheet.title">
                    <dl class="divide-y divide-border text-sm">
                        <div v-for="row in sheet.rows" :key="row.label" class="flex items-baseline justify-between gap-3 py-1.5">
                            <dt class="text-text-muted">{{ row.label }}</dt>
                            <dd class="font-semibold tabular-nums">{{ row.count }}</dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </StaffLayout>
</template>
