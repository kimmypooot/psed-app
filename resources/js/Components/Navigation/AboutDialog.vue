<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import Icon from '../UI/Icon.vue';
import { useBranding } from '../../Composables/useBranding';

/**
 * "About CARES", after IMIS's About pop-up: what the system is, the modules
 * this person can open (from their sidebar), the stack, and the release.
 * The version comes from config, so it never claims a number the system
 * can't back up.
 */
defineProps({
    open: { type: Boolean, required: true },
});

defineEmits(['close']);

const page = usePage();
const version = computed(() => page.props.app?.version);
const year = new Date().getFullYear();
const brand = useBranding();

// The pages in this person's sidebar, grouped as the sidebar groups them.
const modules = computed(() =>
    (page.props.navigation ?? [])
        .filter((entry) => entry.group || entry.href)
        .map((entry) => ({ title: entry.group ?? entry.label, icon: entry.icon ?? 'grid' }))
        .filter((entry) => entry.title !== 'Dashboard' && entry.title !== 'Admin Dashboard'),
);

const stack = ['Laravel', 'Vue 3', 'Inertia.js', 'Tailwind CSS', 'MySQL'];
</script>

<template>
    <Modal :open="open" title="About CARES" size="xl" @close="$emit('close')">
        <div class="space-y-8">
            <div class="flex flex-col items-center gap-4 rounded-lg bg-primary px-6 py-8 text-center text-white sm:flex-row sm:text-left">
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-white" aria-hidden="true">
                    <img :src="brand.logo" alt="" class="h-11 w-auto" />
                </span>
                <div>
                    <p class="text-lg leading-snug font-semibold">CSC RO VIII Centralized Appointment Attestation and Reporting via Electronic System</p>
                    <p class="mt-1 text-sm text-white/75">Civil Service Commission Regional Office VIII · Eastern Visayas</p>
                    <span v-if="version" class="mt-3 inline-block rounded-full bg-white/15 px-3 py-0.5 text-xs font-medium">Version {{ version }}</span>
                </div>
            </div>

            <section>
                <h3 class="text-sm font-semibold text-text">What CARES is</h3>
                <p class="mt-2 text-sm leading-relaxed text-text-muted">
                    CARES, the Centralized Appointment Attestation and Reporting via Electronic System, records the appointments that client agencies submit to the CSC, routes each one to the Field Office or the Policies and Systems Evaluation Division (PSED) for processing, and generates the monthly IPMR, MRAP, MOA and Absorption reports in the official Excel formats. It opens from IMIS with your IMIS account. Your role follows your IMIS unit: PSED employees are administrators, and Field Office employees work on their own office.
                </p>
            </section>

            <section v-if="modules.length">
                <h3 class="text-sm font-semibold text-text">Your modules</h3>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="module in modules" :key="module.title" class="flex items-center gap-3 rounded-lg border border-border p-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                            <Icon :name="module.icon" size="sm" />
                        </span>
                        <span class="text-sm font-medium text-text">{{ module.title }}</span>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg bg-surface-muted p-4">
                <h3 class="text-sm font-semibold text-text">Built on</h3>
                <ul class="mt-3 flex flex-wrap gap-2" aria-label="Technologies">
                    <li v-for="item in stack" :key="item" class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-text">{{ item }}</li>
                </ul>
            </section>

            <dl class="grid gap-4 border-t border-border pt-5 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-text-muted">Agency</dt>
                    <dd class="font-medium text-text">Civil Service Commission – Regional Office VIII</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Location</dt>
                    <dd class="font-medium text-text">Government Center, Palo, Leyte</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Release</dt>
                    <dd class="font-medium text-text">CARES v{{ version }} · {{ year }}</dd>
                </div>
            </dl>
        </div>

        <template #footer>
            <Button @click="$emit('close')">Got it</Button>
        </template>
    </Modal>
</template>