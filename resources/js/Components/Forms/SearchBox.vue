<script setup>
import Icon from '../UI/Icon.vue';

defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: 'search' },
    label: { type: String, default: 'Search' },
    placeholder: { type: String, default: 'Search' },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="relative min-w-64 flex-1">
        <label :for="id" class="sr-only">{{ label }}</label>
        <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
        <input
            :id="id"
            :value="modelValue"
            type="search"
            :placeholder="placeholder"
            class="block w-full rounded-md border border-border-strong bg-surface py-2 pr-3 pl-9 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
            @input="$emit('update:modelValue', $event.target.value)"
        />
    </div>
</template>