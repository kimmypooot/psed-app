<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ErrorPage from '../../Components/UI/ErrorPage.vue';

const props = defineProps({
    status: {
        type: Number,
        required: true,
    },
});

const content = {
    404: {
        title: 'Page not found',
        message: 'The page you are looking for does not exist or has been moved.',
    },
    419: {
        title: 'Session expired',
        message: 'Your session has expired. Please refresh the page and try again.',
    },
    429: {
        title: 'Too many requests',
        message: 'You have made too many requests. Please wait a moment and try again.',
    },
    500: {
        title: 'Something went wrong',
        message: 'An unexpected error occurred on our end. Please try again shortly.',
    },
    503: {
        title: 'Service unavailable',
        message: 'The application is temporarily unavailable for maintenance. Please try again shortly.',
    },
};

const current = computed(() => content[props.status] ?? content[500]);
</script>

<template>
    <Head :title="`${status} — ${current.title}`" />
    <ErrorPage :code="status" :title="current.title" :message="current.message" />
</template>
