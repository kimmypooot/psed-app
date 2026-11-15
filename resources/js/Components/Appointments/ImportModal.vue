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

// --- The review table: every row of the file, filtered and paged here (the file is capped at 2,000 rows) ---
const PAGE_SIZE = 50;
const filter = ref('all');
const search = ref('');
const page = ref(1);

// "#" counts only the rows that will be imported, so the last number is how many will be saved.
const numbered = computed(() => {
    let n = 0;

    return (result.value?.rows ?? []).map((r) => ({ ...r, no: r.will_import ? ++n : null }));
});
const filters = computed(() => [
    { id: 'all', label: 'All', count: numbered.value.length },
    { id: 'import', label: 'Will be imported', count: numbered.value.filter((r) => r.will_import).length },
    { id: 'left', label: 'Left out', count: numbered.value.filter((r) => !r.will_import).length },
    { id: 'notes', label: 'With notes', count: numbered.value.filter((r) => r.notes.length).length },
]);
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    return numbered.value.filter((r) => {
        if (filter.value === 'import' && !r.will_import) return false;
        if (filter.value === 'left' && r.will_import) return false;
        if (filter.value === 'notes' && !r.notes.length) return false;

        return !q || [r.name, r.agency, r.position, r.office].some((v) => String(v ?? '').toLowerCase().includes(q));
    });
});
const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / PAGE_SIZE)));
const pageRows = computed(() => filtered.value.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE));
watch([filter, search], () => (page.value = 1));
watch(result, () => {
    filter.value = 'all';
    search.value = '';
    page.value = 1;
});
const statusTone = { ready: 'success', warning: 'warning', duplicate: 'warning', error: 'danger' };
const statusLabel = { ready: 'Ready', warning: 'Imported with a note', duplicate: 'Duplicate', error: 'Error' };
// Status and Sheet / row are one toggle away; the row's left edge and hover colour say the same thing meanwhile.
// (Written out in full so Tailwind finds each class.)
const showStatus = ref(false);
const colCount = computed(() => (showStatus.value ? 13 : 11));
const rowRail = {
    ready: 'shadow-[inset_3px_0_0_var(--color-success)] [--row-hover:var(--color-success-bg)]',
    warning: 'shadow-[inset_3px_0_0_var(--color-info)] [--row-hover:var(--color-info-bg)]',
    duplicate: 'shadow-[inset_3px_0_0_var(--color-warning)] [--row-hover:var(--color-warning-bg)]',
    error: 'shadow-[inset_3px_0_0_var(--color-danger)] [--row-hover:var(--color-danger-bg)]',
};
const cell = 'border border-border px-2.5 py-1.5 align-top';
const head = 'border border-border px-2.5 py-1.5 font-semibold whitespace-nowrap';

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
    <Modal :open="open" :title="title" icon="upload" size="2xl" :tone="tone" :dismissible="!busy" @close="emit('close')">
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

                <section aria-label="Every row of the file">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Show">
                            <button
                                v-for="f in filters"
                                :key="f.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                :class="filter === f.id ? 'border-primary bg-primary text-white' : 'border-border text-text-muted hover:bg-surface-muted'"
                                :aria-pressed="filter === f.id"
                                @click="filter = f.id"
                            >
                                {{ f.label }} <span class="tabular-nums">({{ f.count }})</span>
                            </button>
                        </div>
                        <label for="import-show-status" class="flex select-none items-center gap-2 text-xs text-text"><input id="import-show-status" v-model="showStatus" type="checkbox" class="size-3.5 accent-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" />Show status and sheet / row</label>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search name, agency, position"
                            aria-label="Search the rows"
                            class="w-full rounded-md border border-border bg-surface px-3 py-1.5 text-sm sm:w-72"
                        />
                    </div>

                    <div class="max-h-[28rem] overflow-auto rounded-md border border-border">
                        <table class="w-full border-collapse text-left text-xs">
                            <caption class="sr-only">Every appointment in the file. The number counts the rows that will be imported.</caption>
                            <thead class="sticky top-0 z-10 bg-surface-muted text-[0.6875rem] text-text-muted">
                                <tr>
                                    <th scope="col" :class="head" class="text-right">#</th>
                                    <th v-if="showStatus" scope="col" :class="head">Status</th>
                                    <th v-if="showStatus" scope="col" :class="head">Sheet / row</th>
                                    <th scope="col" :class="head">Appointee</th>
                                    <th scope="col" :class="head">Agency / Office</th>
                                    <th scope="col" :class="head">Position</th>
                                    <th scope="col" :class="head" class="text-right">SG</th>
                                    <th scope="col" :class="head">Status of employment</th>
                                    <th scope="col" :class="head">Received</th>
                                    <th scope="col" :class="head">Received by</th>
                                    <th scope="col" :class="head">CSC action</th>
                                    <th scope="col" :class="head">Action officer</th>
                                    <th scope="col" :class="head">Processed by</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!pageRows.length">
                                    <td :class="cell" :colspan="colCount" class="text-center text-text-muted">No rows match.</td>
                                </tr>
                                <tr v-for="r in pageRows" :key="`${r.sheet}-${r.row}`" class="transition-colors hover:bg-[var(--row-hover)]" :class="[rowRail[r.status], r.will_import ? '' : 'bg-surface-muted/60']">
                                    <td :class="cell" class="text-right font-semibold tabular-nums">{{ r.no ?? '' }}</td>
                                    <td v-if="showStatus" :class="cell">
                                        <Badge :tone="statusTone[r.status]">{{ statusLabel[r.status] }}</Badge>
                                    </td>
                                    <td v-if="showStatus" :class="cell" class="whitespace-nowrap text-text-muted">{{ r.sheet }}, {{ r.row }}</td>
                                    <td :class="cell">
                                        <span class="font-medium">{{ r.name }}</span>
                                        <ul v-if="r.notes.length" class="mt-1 max-w-64 list-disc space-y-0.5 pl-4 text-text-muted">
                                            <li v-for="(m, i) in r.notes" :key="i">{{ m }}</li>
                                        </ul>
                                    </td>
                                    <td :class="cell">{{ r.agency }}</td>
                                    <td :class="cell">{{ r.position }}</td>
                                    <td :class="cell" class="text-right tabular-nums">{{ r.salary_grade }}</td>
                                    <td :class="cell">{{ r.employment_status }}</td>
                                    <td :class="cell" class="whitespace-nowrap">{{ formatDate(r.date_received) }}</td>
                                    <td :class="cell">{{ r.received_by }}</td>
                                    <td :class="cell">{{ r.csc_action }}</td>
                                    <td :class="cell">{{ r.action_officer }}</td>
                                    <td :class="cell">{{ r.processed_by }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-text-muted">
                        <p>
                            Showing {{ filtered.length ? (page - 1) * PAGE_SIZE + 1 : 0 }} to {{ Math.min(page * PAGE_SIZE, filtered.length) }} of {{ filtered.length }}.
                            <strong class="text-text">{{ result.counts.ready }}</strong> will be imported.
                        </p>
                        <div v-if="pageCount > 1" class="flex items-center gap-2">
                            <Button variant="outline" size="sm" :disabled="page <= 1" @click="page--">Previous</Button>
                            <span class="tabular-nums">Page {{ page }} of {{ pageCount }}</span>
                            <Button variant="outline" size="sm" :disabled="page >= pageCount" @click="page++">Next</Button>
                        </div>
                    </div>
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
