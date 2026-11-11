<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '../UI/Icon.vue';

/**
 * A file field with a drop zone. Click it (or tab to it and press Space or
 * Enter) to browse, or drag a file onto it. A chosen file shows as a card
 * with a preview (a thumbnail for images, a typed icon otherwise), its
 * name and size, and Replace / Remove. A file the field doesn't accept is
 * refused on the spot with a reason, so the form never holds a bad file.
 *
 * `v-model` is the chosen `File` (or `null`). It still works with just
 * `@update:model-value`. `accept` is the usual input value (".xlsx",
 * "image/png,image/jpeg"); `maxSizeMb` is optional. Put an `InputLabel` or
 * `FormGroup` with the same `id` around it for the visible label.
 */
const props = defineProps({
    id: { type: String, required: true },
    accept: { type: String, default: null },
    required: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    modelValue: { type: Object, default: undefined },
    maxSizeMb: { type: Number, default: null },
    /** Overrides the generated "XLSX · up to 5 MB" line. */
    hint: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const inputRef = ref(null);
const file = ref(props.modelValue ?? null);
const dragging = ref(false);
const rejection = ref(null);
const previewUrl = ref(null);

watch(
    () => props.modelValue,
    (value) => {
        if (value === undefined) return;
        file.value = value;
        if (!value && inputRef.value) inputRef.value.value = '';
    },
);

const clearPreview = () => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
};

watch(
    file,
    (value) => {
        clearPreview();
        if (value?.type?.startsWith('image/')) previewUrl.value = URL.createObjectURL(value);
    },
    { immediate: true },
);

onBeforeUnmount(clearPreview);

const tokens = computed(() => (props.accept ?? '').split(',').map((token) => token.trim().toLowerCase()).filter(Boolean));

const matchesAccept = (candidate) => {
    if (!tokens.value.length) return true;

    const name = candidate.name.toLowerCase();
    const type = (candidate.type || '').toLowerCase();

    return tokens.value.some((token) => {
        if (token.startsWith('.')) return name.endsWith(token);
        if (token.endsWith('/*')) return type.startsWith(token.slice(0, -1));

        return type === token;
    });
};

const typeLabels = computed(() => {
    const labels = tokens.value.map((token) => {
        if (token.startsWith('.')) return token.slice(1).toUpperCase();
        if (token.endsWith('/*')) return `${token.slice(0, -2)}s`.replace(/^./, (c) => c.toUpperCase());

        const sub = token.split('/')[1] ?? token;

        return (sub === 'jpeg' ? 'jpg' : sub).toUpperCase();
    });

    return [...new Set(labels)];
});

const hintText = computed(() => {
    if (props.hint) return props.hint;

    const parts = [];
    if (typeLabels.value.length) parts.push(typeLabels.value.join(', '));
    if (props.maxSizeMb) parts.push(`up to ${props.maxSizeMb} MB`);

    return parts.join(' · ');
});

const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

const extension = computed(() => (file.value ? (file.value.name.split('.').pop() ?? '').toUpperCase() : ''));

// The icon chip is tinted by kind: spreadsheets green, PDFs red, the rest blue.
const chipClass = computed(() => {
    const ext = extension.value.toLowerCase();
    if (['xlsx', 'xls', 'csv'].includes(ext)) return 'bg-success-bg text-success';
    if (ext === 'pdf') return 'bg-danger-bg text-danger';

    return 'bg-info-bg text-primary';
});

const clearInput = () => {
    if (inputRef.value) inputRef.value.value = '';
};

const choose = (candidate) => {
    rejection.value = null;

    if (!candidate) return;

    if (!matchesAccept(candidate)) {
        rejection.value = `${candidate.name} isn't an accepted type${typeLabels.value.length ? ` (use ${typeLabels.value.join(', ')})` : ''}.`;
        clearInput();

        return;
    }

    if (props.maxSizeMb && candidate.size > props.maxSizeMb * 1024 * 1024) {
        rejection.value = `${candidate.name} is ${formatSize(candidate.size)}; the limit is ${props.maxSizeMb} MB.`;
        clearInput();

        return;
    }

    file.value = candidate;
    emit('update:modelValue', candidate);
};

const onChange = (event) => choose(event.target.files?.[0] ?? null);

const remove = () => {
    rejection.value = null;
    file.value = null;
    clearInput();
    emit('update:modelValue', null);
    inputRef.value?.focus();
};

const onDragOver = (event) => {
    if (props.disabled) return;
    event.preventDefault();
    dragging.value = true;
};

const onDragLeave = (event) => {
    if (!event.currentTarget.contains(event.relatedTarget)) dragging.value = false;
};

const onDrop = (event) => {
    event.preventDefault();
    dragging.value = false;
    if (props.disabled) return;

    const dropped = event.dataTransfer?.files?.[0];
    if (!dropped) return;

    // Hand the file to the real input too, so native `required` checks see it.
    try {
        inputRef.value.files = event.dataTransfer.files;
    } catch {
        // Some browsers refuse; the model value is still set below.
    }

    choose(dropped);
};

const hasError = computed(() => props.invalid || !!rejection.value);
const describedBy = computed(() => `${props.id}-hint`);

const zoneClass = computed(() => {
    if (props.disabled) return 'cursor-not-allowed border-border bg-surface-muted text-text-muted';
    if (dragging.value) return 'cursor-copy border-primary bg-info-bg';
    if (hasError.value) return 'cursor-pointer border-danger bg-danger-bg/40 hover:bg-danger-bg';

    return 'cursor-pointer border-border-strong bg-surface hover:border-primary hover:bg-surface-muted';
});
</script>

<template>
    <div @dragover="onDragOver" @dragenter="onDragOver" @dragleave="onDragLeave" @drop="onDrop">
        <input
            :id="id"
            ref="inputRef"
            type="file"
            class="peer sr-only"
            :accept="accept"
            :required="required && !file"
            :disabled="disabled"
            :aria-invalid="hasError"
            :aria-describedby="describedBy"
            @change="onChange"
        />

        <label
            v-if="!file"
            :for="id"
            class="flex flex-col items-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors duration-150 peer-focus-visible:ring-2 peer-focus-visible:ring-focus-ring peer-focus-visible:ring-offset-2 motion-reduce:transition-none"
            :class="zoneClass"
        >
            <span
                class="flex h-12 w-12 items-center justify-center rounded-full transition duration-150 motion-reduce:transition-none"
                :class="dragging ? 'scale-110 bg-primary text-white' : hasError ? 'bg-danger-bg text-danger' : 'bg-info-bg text-primary'"
                aria-hidden="true"
            >
                <Icon :name="dragging ? 'download' : 'upload'" size="lg" />
            </span>
            <span class="text-sm text-text">
                <template v-if="dragging"><span class="font-semibold text-primary">Drop to attach</span></template>
                <template v-else><span class="font-semibold text-primary">Choose a file</span> or drag and drop it here</template>
            </span>
            <span v-if="hintText" :id="describedBy" class="text-xs text-text-muted">{{ hintText }}</span>
            <span v-else :id="describedBy" class="sr-only">No file chosen</span>
        </label>

        <div v-else class="flex items-center gap-3 rounded-lg border bg-surface p-3" :class="dragging ? 'border-primary bg-info-bg' : 'border-border'">
            <img v-if="previewUrl" :src="previewUrl" alt="" class="h-12 w-12 shrink-0 rounded-md border border-border object-cover" />
            <span v-else class="flex h-12 w-12 shrink-0 flex-col items-center justify-center gap-0.5 rounded-md" :class="chipClass" aria-hidden="true">
                <Icon name="documentText" size="md" />
                <span v-if="extension" class="text-[10px] leading-none font-bold">{{ extension.slice(0, 4) }}</span>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-text" :title="file.name">{{ file.name }}</p>
                <p :id="describedBy" class="flex flex-wrap items-center gap-x-1.5 text-xs text-text-muted">
                    <span>{{ formatSize(file.size) }}</span>
                    <span aria-hidden="true">·</span>
                    <span class="inline-flex items-center gap-1 font-medium text-success"><Icon name="checkCircle" size="xs" />Ready to upload</span>
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <label
                    v-if="!disabled"
                    :for="id"
                    class="cursor-pointer rounded-md border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-text transition-colors hover:bg-surface-muted max-sm:min-h-10 max-sm:content-center"
                >
                    Replace
                </label>
                <button
                    v-if="!disabled"
                    type="button"
                    class="rounded-md p-1.5 text-text-muted transition-colors hover:bg-danger-bg hover:text-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring max-sm:min-h-10 max-sm:min-w-10"
                    :aria-label="`Remove ${file.name}`"
                    @click="remove"
                >
                    <Icon name="close" size="sm" />
                </button>
            </div>
        </div>

        <p v-if="rejection" role="alert" class="mt-2 flex items-start gap-1.5 text-xs text-danger">
            <Icon name="exclamation" size="xs" class="mt-0.5 shrink-0" />
            {{ rejection }}
        </p>
        <p class="sr-only" aria-live="polite">{{ file ? `Selected ${file.name}, ${formatSize(file.size)}` : '' }}</p>
    </div>
</template>
