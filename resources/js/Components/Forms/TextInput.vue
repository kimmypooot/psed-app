<script setup>
import Icon from '../UI/Icon.vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'text',
    },
    autocomplete: {
        type: String,
        default: 'off',
    },
    required: {
        type: Boolean,
        default: false,
    },
    autofocus: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    // Optional Icon name shown inside the field, before the text.
    icon: {
        type: String,
        default: null,
    },
    // Values offered as you type (a native datalist); any other value is still allowed.
    suggestions: {
        type: Array,
        default: () => [],
    },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-text">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true"> *</span>
        </label>
        <div class="relative mt-1">
            <Icon
                v-if="icon"
                :name="icon"
                size="sm"
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted"
            />
            <input
                :id="id"
                :type="type"
                :value="modelValue"
                :autocomplete="autocomplete"
                :required="required"
                :autofocus="autofocus"
                :aria-invalid="invalid"
                :disabled="disabled"
                :list="suggestions.length ? `${id}-suggestions` : undefined"
                class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs transition-colors hover:border-primary/60 focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted"
                :class="{ 'pl-9': icon }"
                @input="$emit('update:modelValue', $event.target.value)"
            />
        </div>
        <datalist v-if="suggestions.length" :id="`${id}-suggestions`">
            <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
        </datalist>
    </div>
</template>
