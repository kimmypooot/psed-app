<script setup>
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Modal from '@/Components/Feedback/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Spinner from '@/Components/UI/Spinner.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import { request } from '@/Utils/http';

/**
 * Read-only view of one appointment, opened from the list. The details are
 * laid out by the server (AppointmentController::details) and fetched when
 * the dialog opens, so the list itself stays light.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    appointmentId: { type: Number, default: null },
});

defineEmits(['close']);

const data = ref(null);
const loading = ref(false);
const failed = ref(false);

watch(
    () => [props.open, props.appointmentId],
    async ([open, id]) => {
        if (!open || id === null) return;

        data.value = null;
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

const actionTone = (action) =>
    ({ Approved: 'success', Validated: 'success', Disapproved: 'danger', Invalidated: 'danger', Pending: 'warning' })[action] ?? 'neutral';
</script>

<template>
    <Modal :open="open" :title="data?.full_name ?? 'Appointment'" icon="eye" size="xl" @close="$emit('close')">
        <template v-if="data" #summary>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-text-muted">{{ data.subtitle }}</span>
                <Badge :tone="data.processed_by === 'psed' ? 'info' : 'neutral'">Processed by {{ data.processed_by_label }}</Badge>
                <Badge v-if="data.csc_action" :tone="actionTone(data.csc_action)">{{ data.csc_action }}</Badge>
                <Badge v-else tone="neutral">Awaiting action</Badge>
                <Badge v-if="data.is_first_time_entrant" tone="info">First-time entrant</Badge>
            </div>
        </template>

        <div v-if="loading" class="flex items-center justify-center gap-2 py-16 text-sm text-text-muted" role="status">
            <Spinner size="sm" label="" />
            Loading the appointment…
        </div>

        <Alert v-else-if="failed" tone="danger">This appointment could not be loaded. Close this window and try again.</Alert>

        <div v-else-if="data" class="space-y-6">
            <p v-if="data.routing_note" class="flex items-start gap-2 rounded-md bg-info-bg p-3 text-xs leading-relaxed text-info">
                <Icon name="info" size="sm" class="mt-0.5 shrink-0" />
                {{ data.routing_note }}
            </p>

            <section v-for="section in data.sections" :key="section.title" :aria-label="section.title">
                <h3 class="mb-2 border-b border-border pb-1 text-xs font-semibold tracking-wide text-text-muted uppercase">{{ section.title }}</h3>
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div v-for="item in section.items" :key="item.label">
                        <dt class="text-xs text-text-muted">{{ item.label }}</dt>
                        <dd class="text-sm font-medium break-words text-text">{{ item.value }}</dd>
                    </div>
                </dl>
            </section>
        </div>

        <template #footer>
            <Button v-if="data?.history_url" :href="data.history_url" variant="ghost"><Icon name="clock" size="sm" />History</Button>
            <Button variant="secondary" @click="$emit('close')">Close</Button>
            <Button v-if="data?.can_update" :href="`/appointments/${data.id}/edit`" variant="primary"><Icon name="pencil" size="sm" />Edit</Button>
        </template>
    </Modal>
</template>
