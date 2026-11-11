<script setup>
defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        required: true,
    },
    rows: {
        type: Number,
        default: 3,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <textarea
        :id="id"
        :value="modelValue"
        :rows="rows"
        :required="required"
        :aria-invalid="invalid"
        class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs transition-colors hover:border-primary/60 focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
        @input="$emit('update:modelValue', $event.target.value)"
    ></textarea>
</template>
