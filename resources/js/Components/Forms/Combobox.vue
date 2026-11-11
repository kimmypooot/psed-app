<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import Icon from '../UI/Icon.vue';
import Spinner from '../UI/Spinner.vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    id: { type: String, required: true },
    // Left out when a FormGroup or a label of the page's own names the field.
    label: { type: String, default: null },
    // Plain strings, { value, label }, or groups { label, options: [{ value, label }] }.
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Search or type…' },
    required: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    // The options are being fetched (a search on the server): shows a spinner while they load.
    loading: { type: Boolean, default: false },
    // false = the value must be one of the options; true = any typed text is kept.
    allowCustom: { type: Boolean, default: true },
    // Rendering is capped so very long lists stay fast; search narrows them.
    maxVisible: { type: Number, default: 50 },
});

const emit = defineEmits(['update:modelValue']);

const root = ref(null);
const input = ref(null);
const list = ref(null);
const open = ref(false);
const active = ref(0);

const items = computed(() =>
    props.options.flatMap((o) => {
        if (typeof o === 'string') return [{ value: o, label: o }];
        if (o.options) return o.options.map((c) => ({ ...c, group: o.label }));

        return [o];
    }),
);

// The text of the field when the value is free text (always a string).
const current = computed(() => String(props.modelValue ?? ''));

// A group's heading shows above its first visible option.
const heading = (i) => (visible.value[i].group && visible.value[i].group !== visible.value[i - 1]?.group ? visible.value[i].group : null);

// What the input shows. Free-text mode mirrors the value; pick-only mode shows the
// selected option's label (the value may be an id) and keeps typing separate.
const typed = ref('');
const filtering = ref(false); // false = show the full list (just opened / just chosen)
const selectedLabel = computed(() => items.value.find((o) => String(o.value) === String(props.modelValue))?.label ?? '');
const text = computed(() => (props.allowCustom ? current.value : typed.value));
watch(
    () => [props.modelValue, items.value],
    () => { if (!props.allowCustom) typed.value = selectedLabel.value; },
    { immediate: true },
);

const query = computed(() => (filtering.value ? text.value.trim().toLowerCase() : ''));

const matches = computed(() => {
    const q = query.value;
    if (!q) return items.value;
    const words = q.split(/\s+/);
    return items.value.filter((o) => {
        const hay = o.label.toLowerCase();
        return words.every((w) => hay.includes(w));
    });
});

const visible = computed(() => matches.value.slice(0, props.maxVisible));
const hidden = computed(() => matches.value.length - visible.value.length);
const listId = computed(() => `${props.id}-listbox`);

watch(visible, () => (active.value = 0));

function openList() {
    filtering.value = false;
    open.value = true;
}

function highlight(text) {
    const q = query.value;
    if (!q) return [{ text, hit: false }];
    const idx = text.toLowerCase().indexOf(q);
    if (idx < 0) return [{ text, hit: false }];
    return [
        { text: text.slice(0, idx), hit: false },
        { text: text.slice(idx, idx + q.length), hit: true },
        { text: text.slice(idx + q.length), hit: false },
    ].filter((p) => p.text);
}

function choose(option) {
    emit('update:modelValue', String(option.value));
    if (!props.allowCustom) typed.value = option.label;
    filtering.value = false;
    open.value = false;
}

function clear() {
    emit('update:modelValue', '');
    typed.value = '';
    openList();
    input.value?.focus();
}

function onInput(e) {
    if (props.allowCustom) emit('update:modelValue', e.target.value);
    else typed.value = e.target.value;
    filtering.value = true;
    open.value = true;
}

function scrollActive() {
    nextTick(() => list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }));
}

function onKeydown(e) {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!open.value) open.value = true;
        else active.value = Math.min(active.value + 1, visible.value.length - 1);
        scrollActive();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
        scrollActive();
    } else if (e.key === 'Enter' && open.value && visible.value[active.value]) {
        e.preventDefault();
        choose(visible.value[active.value]);
    } else if (e.key === 'Escape' && open.value) {
        e.stopPropagation();
        open.value = false;
    }
}

function onFocusOut(e) {
    if (root.value && !root.value.contains(e.relatedTarget)) {
        open.value = false;
        // Pick-only: discard half-typed text and fall back to the current selection.
        if (!props.allowCustom) typed.value = selectedLabel.value;
    }
}
</script>

<template>
    <div ref="root" class="relative" @focusout="onFocusOut">
        <label v-if="label" :for="id" class="block text-sm font-medium text-text">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true"> *</span>
        </label>
        <div class="relative" :class="{ 'mt-1': label }">
            <Icon name="search" size="sm" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input
                :id="id"
                ref="input"
                type="text"
                role="combobox"
                autocomplete="off"
                :value="text"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :aria-invalid="invalid"
                :aria-expanded="open"
                :aria-controls="listId"
                aria-autocomplete="list"
                class="block w-full rounded-md border border-border-strong bg-surface py-2 pl-9 pr-16 text-sm text-text shadow-xs transition-colors hover:border-primary/60 focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted"
                @input="onInput"
                @focus="openList"
                @keydown="onKeydown"
            />
            <div class="absolute inset-y-0 right-0 flex items-center gap-0.5 pr-2">
                <Spinner v-if="loading" size="sm" label="Searching…" class="mr-1 text-primary" />
                <button
                    v-if="text && !disabled"
                    type="button"
                    class="rounded p-1 text-text-muted hover:bg-surface-muted hover:text-text"
                    aria-label="Clear"
                    @click="clear"
                >
                    <Icon name="close" size="sm" />
                </button>
                <button
                    type="button"
                    tabindex="-1"
                    class="rounded p-1 text-text-muted hover:bg-surface-muted hover:text-text"
                    aria-label="Toggle options"
                    :disabled="disabled"
                    @click="open ? (open = false) : openList(); input?.focus()"
                >
                    <Icon :name="open ? 'chevronUp' : 'chevronDown'" size="sm" />
                </button>
            </div>
        </div>

        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-75 ease-in"
            leave-to-class="opacity-0"
        >
            <ul
                v-if="open && !disabled"
                :id="listId"
                ref="list"
                role="listbox"
                class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-surface py-1 text-sm shadow-lg"
            >
                <template v-for="(option, i) in visible" :key="option.value">
                <li v-if="heading(i)" role="presentation" class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ heading(i) }}</li>
                <li
                    role="option"
                    :aria-selected="String(option.value) === String(modelValue)"
                    :data-active="i === active"
                    class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-text"
                    :class="i === active ? 'bg-primary/10' : 'hover:bg-surface-muted'"
                    @mousedown.prevent="choose(option)"
                    @mousemove="active = i"
                >
                    <span class="min-w-0 truncate">
                        <template v-for="(part, p) in highlight(option.label)" :key="p">
                            <mark v-if="part.hit" class="rounded-sm bg-primary/20 px-0 text-inherit">{{ part.text }}</mark>
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </span>
                    <Icon v-if="String(option.value) === String(modelValue)" name="check" size="sm" class="shrink-0 text-primary" />
                </li>
                </template>

                <li v-if="!visible.length && loading" class="flex items-center justify-center gap-2 px-3 py-3 text-text-muted" role="status">
                    <Spinner size="sm" label="Searching…" />Searching…
                </li>
                <li v-else-if="!visible.length" class="px-3 py-3 text-center text-text-muted">
                    {{ allowCustom && current ? `No match. “${current}” will be used as typed.` : 'No results found.' }}
                </li>
                <li v-else-if="hidden > 0" class="border-t border-border px-3 py-2 text-xs text-text-muted">
                    {{ hidden }} more. Keep typing to narrow the list.
                </li>
            </ul>
        </Transition>
    </div>
</template>
