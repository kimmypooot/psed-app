<script setup>
defineProps({
    modelValue: {
        type: [String, Number, Boolean],
        default: null,
    },
    value: {
        type: [String, Number, Boolean],
        required: true,
    },
    id: {
        type: String,
        required: true,
    },
    name: {
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
            type="radio"
            :name="name"
            :value="value"
            :checked="modelValue === value"
            class="h-4 w-4 border-border-strong text-primary focus:ring-2 focus:ring-focus-ring"
            @change="$emit('update:modelValue', value)"
        />
        {{ label }}
    </label>
</template>
