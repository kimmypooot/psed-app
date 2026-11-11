<script setup>
defineProps({
    message: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <p v-if="message" class="mt-1 flex items-center gap-1 text-sm text-danger" role="alert">
        <span aria-hidden="true">&#9888;</span>
        <span>{{ message }}</span>
    </p>
</template>
