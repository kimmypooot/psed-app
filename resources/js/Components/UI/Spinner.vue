<script setup>
defineProps({
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    label: {
        type: String,
        default: 'Loading…',
    },
});

const sizeClasses = { sm: 'h-4 w-4', md: 'h-5 w-5', lg: 'h-8 w-8' };
</script>

<template>
    <span role="status" class="inline-flex items-center">
        <svg
            :class="sizeClasses[size]"
            class="animate-spin text-current"
            viewBox="0 0 24 24"
            fill="none"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"
            />
        </svg>
        <span class="sr-only">{{ label }}</span>
    </span>
</template>
