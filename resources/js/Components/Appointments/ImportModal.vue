<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Feedback/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProgressBar from '@/Components/UI/ProgressBar.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import { requestJson, requestWithProgress, errorMessage } from '@/Utils/http';

/**
 * Bring appointments in from the MOA form, in two steps so nothing is saved by
 * surprise: the file is read and checked first (nothing is stored), the result
 * is shown, and only then are the rows that passed imported.
 *
 * Whatever is going on is said plainly. Uploading shows the real percentage sent; checking and saving
 * cannot say how far along they are, so they show a sweeping bar and what is being done, never an
 * invented percentage. While busy the dialog cannot be closed or double-submitted and leaving the
 * page asks first; when it is over the dialog says what was imported and what was left out.
 *
 * phase: idle -> uploading -> checking -> (review) -> importing -> done
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    /** PSED only: the offices to choose from. Empty for a Field Office, which imports into its own. */
    offices: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const file = ref(null);
const officeId = ref('file');
const phase = ref('idle');
const uploadPercent = ref(0);
const stage = ref(0);
const error = ref(null);
const result = ref(null); // the check's answer
const outcome = ref(null); // the import's answer
const fileKey = ref(0); // re-creates the file input so a chosen file is cleared

const busy = computed(() => ['uploading', 'checking', 'importing'].includes(phase.value));
const choosesOffice = computed(() => props.offices.length > 0);
const officeOptions = computed(() => [
    { value: 'file', label: 'The office named in column A of each row' },
    ...props.offices.map((o) => ({ value: String(o.id), label: `All into ${o.name}` })),
]);

// What the server does while the file is checked, in order. It cannot report which step it is on,
// so these advance on a timer and stop at the last: they describe the work, they do not measure it.
const stages = ['Reading the file…', 'Matching agencies and officers…', 'Looking for duplicates…'];
let stageTimer = null;
const stopStages = () => {
    clearInterval(stageTimer);
    stageTimer = null;
};

const readyCount = computed(() => result.value?.counts.ready ?? 0);
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

// One sentence for screen readers whenever the phase changes.
const statusText = computed(() => {
    switch (phase.value) {
        case 'uploading':
            return `Uploading the file, ${uploadPercent.value} percent.`;
        case 'checking':
            return stages[stage.value];
        case 'importing':
            return `Saving ${plural(readyCount.value, 'appointment')}. Please wait.`;
        case 'done':
            return outcome.value?.message ?? 'The import is finished.';
        default:
            return '';
    }
});

const title = computed(() => (phase.value === 'done' ? 'Import finished' : result.value ? 'Review the import' : 'Import appointments'));
const tone = computed(() => (phase.value === 'done' ? 'success' : 'primary'));

const reset = () => {
    stopStages();
    file.value = null;
    result.value = null;
    outcome.value = null;
    error.value = null;
    phase.value = 'idle';
    uploadPercent.value = 0;
    stage.value = 0;
    officeId.value = 'file';
    fileKey.value++;
};

// Every time the dialog opens it starts clean.
watch(() => props.open, (open) => open && reset());

// A half-finished import is the worst outcome: closing the tab or reloading asks first.
const warnBeforeLeaving = (event) => {
    event.preventDefault();
    event.returnValue = '';
};
watch(busy, (isBusy) => {
    if (isBusy) window.addEventListener('beforeunload', warnBeforeLeaving);
    else window.removeEventListener('beforeunload', warnBeforeLeaving);
});
onBeforeUnmount(() => {
    stopStages();
    window.removeEventListener('beforeunload', warnBeforeLeaving);
});

const check = async () => {
    if (busy.value) return;

    if (!file.value) {
        error.value = 'Choose the MOA form (.xlsx) to import.';

        return;
    }

    const body = new FormData();
    body.append('file', file.value);
    if (choosesOffice.value) body.append('field_office_id', officeId.value);

    error.value = null;
    uploadPercent.value = 0;
    stage.value = 0;
    phase.value = 'uploading';

    const response = await requestWithProgress('POST', '/appointments/import/preview', body, (percent) => {
        uploadPercent.value = percent;

        // Fully sent: now the server reads it.
        if (percent >= 100 && phase.value === 'uploading') {
            phase.value = 'checking';
            stageTimer = setInterval(() => {
                if (stage.value < stages.length - 1) stage.value++;
            }, 1800);
        }
    });

    stopStages();

    if (response.ok) {
        result.value = response.data;
    } else {
        error.value = errorMessage(response);
    }

    phase.value = 'idle';
};

const confirmImport = async () => {
    if (busy.value || readyCount.value === 0) return;

    error.value = null;
    phase.value = 'importing';

    const response = await requestJson('POST', '/appointments/import', { token: result.value.token });

    if (response.ok) {
        outcome.value = response.data;
        phase.value = 'done';
        // The list behind the dialog already shows what was imported.
        router.reload({ preserveScroll: true });

        return;
    }

    // The saved check is gone (expired): start again from the file. Anything else: stay on the review.
    if (response.status === 410) {
        const message = errorMessage(response);
        reset();
        error.value = message;
    } else {
        error.value = errorMessage(response);
        phase.value = 'idle';
    }
};

const chooseAnother = () => {
    if (busy.value) return;

    result.value = null;
    outcome.value = null;
    error.value = null;
    file.value = null;
    phase.value = 'idle';
    fileKey.value++;
};

const kindTone = { error: 'danger', duplicate: 'warning', warning: 'warning' };
const kindLabel = { error: 'Not imported', duplicate: 'Duplicate', warning: 'Imported with a note' };
const formatDate = (value) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';

// The rows that were left out, as a spreadsheet-friendly CSV to correct and import again.
const leftOut = computed(() => (result.value?.problems ?? []).filter((p) => p.kind !== 'warning'));
const downloadLeftOut = () => {
    const cell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
    const lines = [['Reason', 'Sheet', 'Row', 'Appointee', 'Details'].map(cell).join(',')];
    for (const p of leftOut.value) lines.push([kindLabel[p.kind], p.sheet, p.row, p.name, p.messages.join('; ')].map(cell).join(','));

    const blob = new Blob([`﻿${lines.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${(result.value.file_name || 'import').replace(/\.xlsx$/i, '')} - rows left out.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
};
</script>

<template>
    <Modal :open="open" :title="title" icon="upload" size="xl" :tone="tone" :dismissible="!busy" @close="emit('close')">
        <!-- Said aloud to screen readers whenever the phase changes. -->
        <p class="sr-only" role="status" aria-live="polite">{{ statusText }}</p>

        <!-- Step 1: choose the file -->
        <div v-show="phase === 'idle' && !result" class="space-y-5">
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

        <!-- Uploading / checking: what is happening, and a placeholder for what is coming -->
        <div v-if="phase === 'uploading' || phase === 'checking'" class="space-y-5" aria-busy="true">
            <div class="rounded-lg border border-border p-4">
                <p class="flex items-center gap-2 text-sm font-medium">
                    <Icon name="documentText" size="sm" class="text-primary" />
                    <span class="min-w-0 truncate">{{ file?.name }}</span>
                </p>
                <div class="mt-3">
                    <ProgressBar :value="phase === 'uploading' ? uploadPercent : null" :label="phase === 'uploading' ? 'Uploading the file' : 'Checking the file'" />
                </div>
                <p class="mt-2 flex items-center justify-between text-sm text-text-muted" aria-hidden="true">
                    <span>{{ phase === 'uploading' ? 'Uploading the file…' : stages[stage] }}</span>
                    <span v-if="phase === 'uploading'" class="tabular-nums">{{ uploadPercent }}%</span>
                </p>
                <p class="mt-1 text-xs text-text-muted">Nothing is saved yet. This can take a few seconds for a long form.</p>
            </div>

            <!-- The review table, as it will look -->
            <div class="space-y-2" aria-hidden="true">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="n in 4" :key="n" class="h-16 rounded-lg bg-surface-muted motion-safe:animate-pulse" />
                </div>
                <div v-for="(width, n) in ['w-full', 'w-11/12', 'w-full', 'w-4/5']" :key="n" class="h-9 rounded bg-surface-muted motion-safe:animate-pulse" :class="width" />
            </div>
        </div>

        <!-- Done: what happened -->
        <div v-else-if="phase === 'done'" class="space-y-5">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-success-bg text-success"><Icon name="checkCircle" size="md" /></span>
                <div>
                    <h3 class="text-lg font-semibold">{{ outcome.imported === 0 ? 'Nothing was imported' : `${plural(outcome.imported, 'appointment')} imported` }}</h3>
                    <p class="text-sm text-text-muted">{{ outcome.file_name }}</p>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg border border-success-border bg-success-bg p-3">
                    <dt class="text-xs text-success">Imported</dt>
                    <dd class="text-2xl font-bold text-success tabular-nums">{{ outcome.imported }}</dd>
                </div>
                <div class="rounded-lg border border-warning-border bg-warning-bg p-3">
                    <dt class="text-xs text-warning">Duplicates (left out)</dt>
                    <dd class="text-2xl font-bold text-warning tabular-nums">{{ outcome.duplicates }}</dd>
                </div>
                <div class="rounded-lg border border-danger-border bg-danger-bg p-3">
                    <dt class="text-xs text-danger">With errors (left out)</dt>
                    <dd class="text-2xl font-bold text-danger tabular-nums">{{ outcome.invalid }}</dd>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-xs text-text-muted">Rows in the file</dt>
                    <dd class="text-2xl font-bold tabular-nums">{{ outcome.rows }}</dd>
                </div>
            </dl>

            <div v-if="outcome.imported > 0" class="flex flex-wrap gap-2">
                <Button v-if="outcome.imported_plantilla > 0" href="/appointments/plantilla" variant="outline" size="sm">
                    View Plantilla ({{ outcome.imported_plantilla }} added)
                </Button>
                <Button v-if="outcome.imported_casual > 0" href="/appointments/casual-contractual" variant="outline" size="sm">
                    View Casual / Contractual ({{ outcome.imported_casual }} added)
                </Button>
            </div>

            <Alert v-if="leftOut.length" tone="warning" title="Some rows were left out">
                Correct them in the spreadsheet and import the file again: rows that were imported are recognised as duplicates and skipped.
                <button type="button" class="mt-1 flex items-center gap-1 font-medium text-primary underline" @click="downloadLeftOut">
                    <Icon name="download" size="sm" />Download the {{ plural(leftOut.length, 'row') }} left out (CSV)
                </button>
                <span v-if="leftOut.length >= 100" class="mt-1 block text-xs">The list holds the first 100 rows that needed attention.</span>
            </Alert>
        </div>

        <!-- Step 2: what was found (and, while saving, what is being saved) -->
        <div v-else-if="result" class="space-y-5" :aria-busy="phase === 'importing'">
            <div v-if="phase === 'importing'" class="rounded-lg border border-primary/30 bg-primary/5 p-4">
                <p class="text-sm font-medium">Saving {{ plural(readyCount, 'appointment') }}…</p>
                <div class="mt-2"><ProgressBar :value="null" label="Saving the appointments" /></div>
                <p class="mt-2 text-xs text-text-muted">Please keep this window open. It closes the import only when everything is saved.</p>
            </div>

            <Alert v-if="error" tone="danger">{{ error }}</Alert>

            <div class="space-y-5 transition-opacity motion-reduce:transition-none" :class="phase === 'importing' ? 'opacity-50' : ''" :inert="phase === 'importing'">
                <p class="text-sm text-text-muted">
                    <Icon name="documentText" size="sm" class="mr-1 inline" />{{ result.file_name }}: nothing has been saved yet.
                </p>
                <p class="flex flex-wrap gap-2 text-sm">
                    <Badge tone="info">{{ result.counts.plantilla }} on the Plantilla sheet</Badge>
                    <Badge tone="info">{{ result.counts.casual }} on the CasualCon sheet</Badge>
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
        </div>

        <template #footer>
            <!-- Busy: nothing to press. The dialog says what is happening and cannot be closed. -->
            <template v-if="phase === 'uploading' || phase === 'checking'">
                <Button variant="secondary" disabled>Checking the file…</Button>
            </template>
            <template v-else-if="phase === 'done'">
                <Button variant="outline" @click="chooseAnother">Import another file</Button>
                <Button variant="success" @click="emit('close')"><Icon name="check" size="sm" />Done</Button>
            </template>
            <template v-else>
                <Button variant="secondary" :disabled="phase === 'importing'" @click="emit('close')">Cancel</Button>
                <template v-if="!result">
                    <Button variant="primary" @click="check"><Icon name="search" size="sm" />Check the file</Button>
                </template>
                <template v-else>
                    <Button variant="outline" :disabled="phase === 'importing'" @click="chooseAnother">Choose another file</Button>
                    <Button variant="success" :disabled="readyCount === 0" :loading="phase === 'importing'" @click="confirmImport">
                        <Icon name="upload" size="sm" />{{ phase === 'importing' ? 'Saving…' : `Import ${plural(readyCount, 'appointment')}` }}
                    </Button>
                </template>
            </template>
        </template>
    </Modal>
</template>
