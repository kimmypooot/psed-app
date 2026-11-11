<script setup>
defineProps({
    for: {
        type: String,
        required: true,
    },
    required: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <label :for="$props.for" class="block text-sm font-medium text-text">
        <slot />
        <span v-if="required" class="text-danger" aria-hidden="true"> *</span>
    </label>
</template>
