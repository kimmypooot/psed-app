<script setup>
defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    id: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <label :for="id" class="flex select-none items-center gap-2 text-sm text-text">
        <input
            :id="id"
            type="checkbox"
            :checked="modelValue"
            class="size-4 accent-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            @change="$emit('update:modelValue', $event.target.checked)"
        />
        {{ label }}
    </label>
</template>
