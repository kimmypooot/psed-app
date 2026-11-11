<script setup>
defineProps({
    id: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <p :id="id" class="mt-1 text-sm text-text-muted">
        <slot />
    </p>
</template>
