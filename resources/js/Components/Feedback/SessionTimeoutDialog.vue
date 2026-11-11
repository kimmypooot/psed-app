<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from './Modal.vue';
import Button from '../UI/Button.vue';
import { useSessionTimeout } from '../../Composables/useSessionTimeout';

/**
 * Warns before the session times out, as IMIS does:
 * "Stay signed in" for the idle timeout, or a heads-up for the absolute
 * limit, which can't be extended. Renders nothing for exempt sessions.
 */
const page = usePage();
const limits = computed(() => page.props.sessionLimits ?? null);
const { warning, stayActive, acknowledgeAbsolute } = useSessionTimeout(limits);

const clock = computed(() => {
    const seconds = warning.value?.seconds ?? 0;

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

const dismiss = () => (warning.value?.kind === 'absolute' ? acknowledgeAbsolute() : stayActive());
</script>

<template>
    <Modal
        :open="warning !== null"
        :title="warning?.kind === 'absolute' ? 'Session time limit' : 'Are you still there?'"
        icon="clock"
        size="sm"
        @close="dismiss"
    >
        <div class="space-y-3 text-sm text-text">
            <p v-if="warning?.kind === 'absolute'">
                For security, every sign-in ends after a set time, however active you are. Save your work: you'll need
                to sign in again in
                <strong class="tabular-nums">{{ clock }}</strong>.
            </p>
            <p v-else>
                You've been inactive for a while. For security, you'll be signed out in
                <strong class="tabular-nums">{{ clock }}</strong>.
            </p>
            <!-- Announced once a minute, not every second. -->
            <p class="sr-only" aria-live="polite">{{ warning && warning.seconds % 60 === 0 ? `${clock} remaining` : '' }}</p>
        </div>

        <template #footer>
            <Button v-if="warning?.kind === 'absolute'" data-autofocus @click="acknowledgeAbsolute">OK</Button>
            <Button v-else data-autofocus @click="stayActive">Stay signed in</Button>
        </template>
    </Modal>
</template>
