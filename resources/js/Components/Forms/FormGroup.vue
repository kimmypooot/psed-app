<script setup>
import InputLabel from './InputLabel.vue';
import HelperText from './HelperText.vue';
import InputError from './InputError.vue';

/**
 * Consistent label + control + helper text + error wrapper for controls
 * that don't bundle their own label (Textarea, Radio group,
 * FileInput). TextInput/PasswordInput/Checkbox render their own label
 * inline since the label sits directly beside those controls.
 */
defineProps({
    id: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    required: {
        type: Boolean,
        default: false,
    },
    helperText: {
        type: String,
        default: null,
    },
    error: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <div>
        <InputLabel :for="id" :required="required">{{ label }}</InputLabel>
        <div class="mt-1">
            <slot />
        </div>
        <HelperText v-if="helperText && !error" :id="`${id}-helper`">{{ helperText }}</HelperText>
        <InputError :message="error" />
    </div>
</template>
