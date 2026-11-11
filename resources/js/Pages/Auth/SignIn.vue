<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Feedback/Alert.vue';
import Button from '@/Components/UI/Button.vue';
import { useBranding } from '@/Composables/useBranding';

// CARES has no sign-in form: staff open it from IMIS. Refused sign-ins and
// sign-outs land here with the reason. Laid out like IMIS's sign-in page
// (AuthLayout): a navy brand panel beside the card on desktop.
defineProps({
    imisUrl: { type: String, default: null },
});

const page = usePage();
const brand = useBranding();
const agencyLogos = computed(() => [brand.value.logo, '/images/brand/bagong_pilipinas.png', '/images/brand/lingkod_bayani.png']);
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-dvh flex-col bg-surface lg:grid lg:grid-cols-2">
        <div
            class="relative hidden overflow-hidden bg-csc-blue bg-cover bg-center px-8 py-10 text-center text-white lg:flex lg:flex-col lg:items-center lg:justify-center"
            :style="{ backgroundImage: `url('${brand.login_background}')` }"
            aria-hidden="true"
        >
            <div class="absolute inset-0 bg-csc-blue/90" />

            <div class="relative flex max-w-sm flex-col items-center">
                <div class="flex items-center justify-center gap-6">
                    <img v-for="agencyLogo in agencyLogos" :key="agencyLogo" :src="agencyLogo" alt="" class="h-20 w-auto select-none" draggable="false" />
                </div>
                <div class="my-6 h-0.5 w-10 rounded bg-white/20" />
                <p class="text-[1.65rem] leading-snug font-bold tracking-tight">CARES<br />Centralized Appointment Attestation and Reporting via Electronic System</p>
                <p class="mt-5 text-sm leading-relaxed text-white/70">
                    Appointment encoding, routing and the monthly IPMR, MRAP, MOA and Absorption reports of the Civil Service Commission in Eastern Visayas.
                </p>
            </div>
            <p class="absolute inset-x-0 bottom-8 text-[0.66rem] tracking-widest text-white/45 uppercase">
                Civil Service Commission Regional Office VIII
            </p>
        </div>

        <main class="flex flex-1 items-center justify-center bg-surface-muted px-4 py-8 sm:px-6 lg:p-10">
            <div class="w-full max-w-md rounded-2xl border border-border bg-surface px-6 py-9 shadow-sm sm:px-10">
                <div class="mb-5 flex items-center justify-center gap-5 border-b border-border pb-5 lg:hidden">
                    <img v-for="agencyLogo in agencyLogos" :key="agencyLogo" :src="agencyLogo" alt="" class="pointer-events-none h-9 w-auto select-none" draggable="false" />
                </div>

                <div class="mb-6 flex items-center gap-4">
                    <img :src="brand.app_logo" alt="" class="h-11 w-auto shrink-0 select-none" draggable="false" />
                    <div>
                        <p class="text-base leading-snug font-semibold text-primary">CARES</p>
                        <p class="text-xs text-text-muted">Civil Service Commission Regional Office VIII</p>
                    </div>
                </div>

                <Alert v-if="page.props.flash.error" tone="danger" class="mb-5">{{ page.props.flash.error }}</Alert>
                <Alert v-else-if="page.props.flash.status" tone="success" class="mb-5">{{ page.props.flash.status }}</Alert>

                <h1 class="text-xl font-semibold text-text">Sign in through IMIS</h1>
                <p class="mt-2 text-sm text-text-muted">
                    CARES has no separate password. Open it from your IMIS portal, under the systems you have access to.
                </p>

                <Button v-if="imisUrl" :href="imisUrl" external class="mt-6 w-full justify-center">Go to IMIS</Button>

                <p class="mt-8 text-center text-xs text-text-muted">CSC RO VIII &copy; {{ new Date().getFullYear() }}</p>
            </div>
        </main>
    </div>
</template>
