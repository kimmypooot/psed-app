<script setup>
import { Head } from '@inertiajs/vue3';
import ErrorPage from '../../Components/UI/ErrorPage.vue';

defineProps({
    message: {
        type: String,
        default: 'You do not have permission to perform this action.',
    },
});
</script>

<template>
    <Head title="403 — Forbidden" />
    <ErrorPage code="403" title="Access denied" :message="message" />
</template>
