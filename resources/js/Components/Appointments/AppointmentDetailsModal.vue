<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Spinner from '@/Components/UI/Spinner.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import { request } from '@/Utils/http';

/**
 * Read-only view of one appointment, opened from the list. The details are laid out by the server
 * (AppointmentController::details) and fetched when the dialog opens, so the list itself stays light.
 *
 * It leads with where the appointment stands (status, who processes it, how long it has waited or took),
 * then what has happened (received, acted, released), and the reason when there is one. The record follows,
 * with the yes/no rows shown as marks only when true and the fields nobody filled in folded away.
 * `ids` are the appointments of the list behind it, so the arrow keys step through them without closing it.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    appointmentId: { type: Number, default: null },
    /** The appointments of the list, in order, to move between. */
    ids: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'navigate']);

const data = ref(null);
const loading = ref(false);
const failed = ref(false);
const showEmpty = ref(false);

watch(
    () => [props.open, props.appointmentId],
    async ([open, id]) => {
        if (!open) {
            data.value = null; // the next opening starts from the spinner, not the last appointment
            return;
        }
        if (id === null) return;

        failed.value = false;
        loading.value = true;

        const result = await request('GET', `/appointments/${id}/details`);

        // Ignore an answer for an appointment the dialog has since moved on from.
        if (props.appointmentId === id) {
            data.value = result.ok ? result.data : null;
            failed.value = !result.ok;
            loading.value = false;
        }
    },
    { immediate: true },
);

// --- Moving through the list ---------------------------------------------------------------
const position = computed(() => props.ids.indexOf(props.appointmentId));
const previousId = computed(() => (position.value > 0 ? props.ids[position.value - 1] : null));
const nextId = computed(() => (position.value >= 0 && position.value < props.ids.length - 1 ? props.ids[position.value + 1] : null));

const onKeydown = (event) => {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;

    if (event.key === 'ArrowLeft' && previousId.value !== null) emit('navigate', previousId.value);
    if (event.key === 'ArrowRight' && nextId.value !== null) emit('navigate', nextId.value);
};

watch(
    () => props.open,
    (open) => (open ? document.addEventListener('keydown', onKeydown) : document.removeEventListener('keydown', onKeydown)),
    { immediate: true },
);
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

// --- What is shown ------------------------------------------------------------------------------
const actionTone = (action) =>
    ({ Approved: 'success', Validated: 'success', Disapproved: 'danger', Invalidated: 'danger', Pending: 'info' })[action ?? 'Pending'] ?? 'neutral';

const tone = computed(() => actionTone(data.value?.csc_action));
const decided = computed(() => data.value?.glance.overdue_days === null);

// The same colour the row has in the list: green, red, or blue while it waits.
const accent = computed(() => ({ success: 'border-success', danger: 'border-danger', info: 'border-info' })[tone.value] ?? 'border-border');

const dot = (step) => {
    if (step.state === 'waiting') return step.tone === 'info' ? 'border-2 border-info bg-surface text-info' : 'border-2 border-border bg-surface text-text-muted';

    return { success: 'bg-success text-white', danger: 'bg-danger text-white' }[step.tone] ?? 'bg-primary text-white';
};
const connector = (step) => (step.state === 'done' ? ({ success: 'bg-success', danger: 'bg-danger' }[step.tone] ?? 'bg-primary') : 'bg-border');

const emptyCount = computed(() => (data.value?.sections ?? []).reduce((sum, s) => sum + s.items.filter((i) => i.empty).length, 0));
const shown = (section) => section.items.filter((item) => showEmpty.value || !item.empty);
const sections = computed(() => (data.value?.sections ?? []).filter((s) => shown(s).length));
</script>

<template>
    <Modal :open="open" :title="data?.full_name ?? 'Appointment'" icon="eye" size="xl" @close="$emit('close')">
        <!-- Where it stands -->
        <template v-if="data" #summary>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-l-4 pl-3" :class="accent">
                <Badge :tone="tone">{{ data.glance.status }}</Badge>
                <span class="text-sm font-medium text-text">{{ data.subtitle }}</span>
                <Badge tone="neutral">{{ data.glance.kind }}</Badge>
                <Badge :tone="data.processed_by === 'psed' ? 'info' : 'neutral'">Processed by {{ data.processed_by_label }}</Badge>
                <span v-if="data.glance.waited" class="flex items-center gap-1.5 text-sm font-semibold" :class="decided ? 'text-text-muted' : 'text-primary'">
                    <Icon :name="decided ? 'checkCircle' : 'clock'" size="sm" />{{ data.glance.waited }}
                </span>
            </div>
            <p class="mt-1 pl-4 text-xs text-text-muted">{{ data.glance.agency }}<span v-if="data.glance.office"> · {{ data.glance.office }}</span> · Received {{ data.glance.received }}</p>
        </template>

        <div v-if="loading && !data" class="flex items-center justify-center gap-2 py-16 text-sm text-text-muted" role="status">
            <Spinner size="sm" label="" />
            Loading the appointment…
        </div>

        <Alert v-else-if="failed" tone="danger">This appointment could not be loaded. Close this window and try again.</Alert>

        <div v-else-if="data" class="space-y-6 transition-opacity motion-reduce:transition-none" :class="loading ? 'opacity-50' : ''" :aria-busy="loading">
            <!-- What has happened -->
            <ol class="flex items-start px-4 pt-1" aria-label="Progress of this appointment">
                <li v-for="(step, i) in data.timeline" :key="step.label" class="relative flex min-w-0 flex-1 flex-col items-center text-center">
                    <span v-if="i > 0" class="absolute top-3.5 right-1/2 h-0.5 w-full -translate-y-1/2" :class="connector(step)" aria-hidden="true" />
                    <span class="relative z-10 flex size-7 items-center justify-center rounded-full ring-4 ring-surface" :class="dot(step)">
                        <Icon v-if="step.state === 'done'" name="check" size="sm" />
                        <span v-else class="size-2 rounded-full bg-current" />
                    </span>
                    <span class="mt-1.5 text-sm font-semibold text-text">{{ step.label }}</span>
                    <span class="text-xs text-text-muted">{{ step.date }}</span>
                </li>
            </ol>

            <!-- Why it is pending, or why it was not approved -->
            <Alert v-if="data.callout" :tone="data.callout.tone === 'danger' ? 'danger' : 'info'" :title="data.callout.title">{{ data.callout.text }}</Alert>

            <!-- Only what is true -->
            <ul v-if="data.flags.length" class="flex flex-wrap gap-2" aria-label="Marks on this appointment">
                <li v-for="flag in data.flags" :key="flag"><Badge tone="info">{{ flag }}</Badge></li>
            </ul>

            <section v-for="section in sections" :key="section.title" :aria-label="section.title">
                <h3 class="mb-2 border-b border-border pb-1 text-sm font-semibold text-text">{{ section.title }}</h3>
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div v-for="item in shown(section)" :key="item.label">
                        <dt class="text-xs text-text-muted">{{ item.label }}</dt>
                        <dd class="text-sm break-words" :class="item.empty ? 'text-text-muted' : 'font-medium text-text'">{{ item.value }}</dd>
                    </div>
                </dl>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3 text-sm text-text-muted">
                <p v-if="data.glance.office" class="max-w-xl text-xs leading-relaxed">{{ data.routing_note }}</p>
                <button
                    v-if="emptyCount"
                    type="button"
                    class="ml-auto rounded-md px-2 py-1 font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :aria-expanded="showEmpty"
                    @click="showEmpty = !showEmpty"
                >
                    {{ showEmpty ? 'Hide' : 'Show' }} {{ emptyCount }} empty field{{ emptyCount === 1 ? '' : 's' }}
                </button>
            </div>
        </div>

        <template #footer>
            <!-- Through the list, without closing -->
            <div v-if="ids.length > 1 && position >= 0" class="mr-auto flex items-center gap-1">
                <Button variant="ghost" size="sm" icon-only aria-label="Previous appointment" title="Previous (left arrow)" :disabled="previousId === null" @click="$emit('navigate', previousId)">
                    <Icon name="chevronLeft" size="sm" />
                </Button>
                <span class="px-1 text-xs text-text-muted tabular-nums" aria-live="polite">{{ position + 1 }} of {{ ids.length }}</span>
                <Button variant="ghost" size="sm" icon-only aria-label="Next appointment" title="Next (right arrow)" :disabled="nextId === null" @click="$emit('navigate', nextId)">
                    <Icon name="chevronRight" size="sm" />
                </Button>
            </div>
            <Button v-if="data?.history_url" :href="data.history_url" variant="ghost"><Icon name="clock" size="sm" />History</Button>
            <Button variant="secondary" @click="$emit('close')">Close</Button>
            <Button v-if="data?.can_update" :href="`/appointments/${data.id}/edit`" variant="primary"><Icon name="pencil" size="sm" />Edit</Button>
        </template>
    </Modal>
</template>
