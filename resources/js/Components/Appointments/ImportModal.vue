<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Feedback/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import { request, errorMessage } from '@/Utils/http';

/**
 * Bring appointments in from the MOA form, in two steps so nothing is saved by
 * surprise: the file is read and checked first (nothing is stored), the result
 * is shown, and only then are the rows that passed imported.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    /** PSED only: the offices to choose from. Empty for a Field Office, which imports into its own. */
    offices: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const file = ref(null);
const officeId = ref('file');
const checking = ref(false);
const importing = ref(false);
const error = ref(null);
const result = ref(null); // the check's answer
const fileKey = ref(0); // re-creates the file input so a chosen file is cleared

const choosesOffice = computed(() => props.offices.length > 0);
const officeOptions = computed(() => [
    { value: 'file', label: 'The office named in column A of each row' },
    ...props.offices.map((o) => ({ value: String(o.id), label: `All into ${o.name}` })),
]);

const reset = () => {
    file.value = null;
    result.value = null;
    error.value = null;
    checking.value = false;
    importing.value = false;
    officeId.value = 'file';
    fileKey.value++;
};

// Every time the dialog opens it starts clean.
watch(() => props.open, (open) => open && reset());

const check = async () => {
    if (!file.value) {
        error.value = 'Choose the MOA form (.xlsx) to import.';

        return;
    }

    const body = new FormData();
    body.append('file', file.value);
    if (choosesOffice.value) body.append('field_office_id', officeId.value);

    checking.value = true;
    error.value = null;
    const response = await request('POST', '/appointments/import/preview', body);
    checking.value = false;

    if (response.ok) {
        result.value = response.data;
    } else {
        error.value = errorMessage(response);
    }
};

const confirmImport = () => {
    importing.value = true;
    router.post('/appointments/import', { token: result.value.token }, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onFinish: () => (importing.value = false),
    });
};

const chooseAnother = () => {
    result.value = null;
    error.value = null;
    file.value = null;
    fileKey.value++;
};

const kindTone = { error: 'danger', duplicate: 'warning', warning: 'warning' };
const kindLabel = { error: 'Not imported', duplicate: 'Duplicate', warning: 'Imported with a note' };
const formatDate = (value) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
</script>

<template>
    <Modal :open="open" :title="result ? 'Review the import' : 'Import appointments'" icon="upload" size="xl" tone="primary" @close="emit('close')">
        <!-- Step 1: choose the file -->
        <div v-if="!result" class="space-y-5">
            <Alert tone="info" title="Use the MOA form">
                The same layout as the Monitoring of Appointments report: appointments on the <strong>Plantilla</strong> and <strong>CasualCon</strong> sheets,
                starting at row 10. A report downloaded from CARES can be imported back as it is.
                <a href="/appointments/import/template" class="mt-1 inline-flex items-center gap-1 font-medium text-primary underline">
                    <Icon name="download" size="sm" />Download the blank form
                </a>
            </Alert>

            <FormGroup v-if="choosesOffice" id="import-office" label="Which Field Office are these for?" required>
                <Combobox :allow-custom="false" id="import-office" v-model="officeId" :options="officeOptions" required />
            </FormGroup>
            <p v-else class="text-sm text-text-muted">The appointments are imported into your own office.</p>

            <FormGroup id="import-file" label="MOA form (.xlsx)" helper-text="Up to 2,000 appointments. Nothing is saved until you have reviewed it." required>
                <FileInput id="import-file" :key="fileKey" accept=".xlsx" :max-size-mb="10" :invalid="!!error" @update:model-value="file = $event" />
            </FormGroup>

            <Alert v-if="error" tone="danger">{{ error }}</Alert>
        </div>

        <!-- Step 2: what was found -->
        <div v-else class="space-y-5">
            <p class="text-sm text-text-muted">
                <Icon name="documentText" size="sm" class="mr-1 inline" />{{ result.file_name }}: nothing has been saved yet.
            </p>

            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-xs text-text-muted">Appointments in the file</dt>
                    <dd class="text-2xl font-bold">{{ result.counts.rows }}</dd>
                </div>
                <div class="rounded-lg border border-success-border bg-success-bg p-3">
                    <dt class="text-xs text-success">Ready to import</dt>
                    <dd class="text-2xl font-bold text-success">{{ result.counts.ready }}</dd>
                </div>
                <div class="rounded-lg border border-warning-border bg-warning-bg p-3">
                    <dt class="text-xs text-warning">Duplicates (left out)</dt>
                    <dd class="text-2xl font-bold text-warning">{{ result.counts.duplicates }}</dd>
                </div>
                <div class="rounded-lg border border-danger-border bg-danger-bg p-3">
                    <dt class="text-xs text-danger">With errors (left out)</dt>
                    <dd class="text-2xl font-bold text-danger">{{ result.counts.invalid }}</dd>
                </div>
            </dl>

            <Alert v-if="result.counts.ready === 0" tone="warning">
                Nothing in this file can be imported. Correct the rows below in the spreadsheet and choose it again.
            </Alert>

            <section v-if="result.sample.length" aria-label="Appointments that will be imported">
                <h3 class="mb-2 text-xs font-semibold tracking-wide text-text-muted uppercase">
                    Will be imported<span v-if="result.counts.ready > result.sample.length"> (the first {{ result.sample.length }} of {{ result.counts.ready }})</span>
                </h3>
                <div class="overflow-x-auto rounded-md border border-border">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-surface-muted text-xs text-text-muted">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-semibold">Appointee</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Position</th>
                                <th scope="col" class="px-3 py-2 font-semibold">SG</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Received</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Processed by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(s, i) in result.sample" :key="i" class="border-t border-border/60">
                                <td class="px-3 py-2 font-medium">{{ s.name }}</td>
                                <td class="px-3 py-2">{{ s.position }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ s.salary_grade }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(s.date_received) }}</td>
                                <td class="px-3 py-2">{{ s.processed_by }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="result.problems.length" aria-label="Rows that need attention">
                <h3 class="mb-2 text-xs font-semibold tracking-wide text-text-muted uppercase">Rows that need attention</h3>
                <ul class="max-h-72 divide-y divide-border overflow-y-auto rounded-md border border-border">
                    <li v-for="p in result.problems" :key="`${p.sheet}-${p.row}`" class="p-3 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :tone="kindTone[p.kind]">{{ kindLabel[p.kind] }}</Badge>
                            <span class="font-medium">{{ p.name }}</span>
                            <span class="text-xs text-text-muted">{{ p.sheet }} sheet, row {{ p.row }}</span>
                        </div>
                        <ul class="mt-1.5 list-disc space-y-0.5 pl-6 text-text-muted">
                            <li v-for="(m, i) in p.messages" :key="i">{{ m }}</li>
                        </ul>
                    </li>
                </ul>
                <p v-if="result.problems.length >= 100" class="mt-2 text-xs text-text-muted">Showing the first 100 rows that need attention.</p>
            </section>
        </div>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <template v-if="!result">
                <Button variant="primary" :loading="checking" @click="check"><Icon name="search" size="sm" />Check the file</Button>
            </template>
            <template v-else>
                <Button variant="outline" :disabled="importing" @click="chooseAnother">Choose another file</Button>
                <Button variant="success" :disabled="result.counts.ready === 0" :loading="importing" @click="confirmImport">
                    <Icon name="upload" size="sm" />Import {{ result.counts.ready }} appointment{{ result.counts.ready === 1 ? '' : 's' }}
                </Button>
            </template>
        </template>
    </Modal>
</template>
