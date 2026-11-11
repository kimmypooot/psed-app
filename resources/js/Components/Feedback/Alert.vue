<script setup>
import { computed, ref } from 'vue';
import Icon from '../UI/Icon.vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info',
        validator: (value) => ['success', 'warning', 'danger', 'info'].includes(value),
    },
    title: {
        type: String,
        default: null,
    },
    // Overrides the tone's default icon.
    icon: {
        type: String,
        default: null,
    },
    dismissible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['dismiss']);

const visible = ref(true);

const dismiss = () => {
    visible.value = false;
    emit('dismiss');
};

const toneMeta = {
    success: { icon: 'check', classes: 'bg-success-bg text-success ring-success-border' },
    warning: { icon: 'exclamation', classes: 'bg-warning-bg text-warning ring-warning-border' },
    danger: { icon: 'exclamation', classes: 'bg-danger-bg text-danger ring-danger-border' },
    info: { icon: 'info', classes: 'bg-info-bg text-info ring-info-border' },
};

const meta = computed(() => toneMeta[props.tone]);
</script>

<template>
    <div
        v-if="visible"
        role="alert"
        class="flex items-start gap-3 rounded-md p-4 text-sm ring-1 ring-inset"
        :class="meta.classes"
    >
        <Icon :name="icon ?? meta.icon" size="sm" class="mt-0.5 shrink-0" />

        <div class="flex-1">
            <p v-if="title" class="font-medium">{{ title }}</p>
            <div class="text-text"><slot /></div>
        </div>

        <button
            v-if="dismissible"
            type="button"
            class="shrink-0 rounded-md p-1 hover:bg-black/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            aria-label="Dismiss"
            @click="dismiss"
        >
            <Icon name="close" size="sm" />
        </button>
    </div>
</template>
