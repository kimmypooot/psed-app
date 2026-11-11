<script setup>
import { computed, ref, useId, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';

/**
 * The header search, after IMIS's "Search the portal": here it finds the
 * pages in the sidebar. Type to filter, Up/Down to choose, Enter to open,
 * Esc to clear. The `/` and Ctrl/Cmd+K shortcuts that focus it are handled
 * once by StaffLayout, which finds the box by `data-module-search`.
 */
const props = defineProps({
    id: { type: String, required: true },
});

const page = usePage();
const listId = useId();
const inputRef = ref(null);
const query = ref('');
const active = ref(0);
const open = ref(false);

// Every page in the sidebar, with the group it sits in.
const pages = computed(() =>
    (page.props.navigation ?? []).flatMap((entry) => {
        if (entry.group) {
            return entry.items.map((item) => ({ ...item, context: entry.group }));
        }

        return entry.href ? [{ ...entry, context: null }] : [];
    }),
);

const results = computed(() => {
    const term = query.value.trim().toLowerCase();

    if (term === '') return [];

    return pages.value
        .filter((item) => `${item.label} ${item.context ?? ''}`.toLowerCase().includes(term))
        .slice(0, 8);
});

watch(results, () => (active.value = 0));

const go = (item) => {
    query.value = '';
    open.value = false;
    inputRef.value?.blur();

    item.external ? window.open(item.href, '_blank', 'noopener') : router.visit(item.href);
};

const onKeydown = (event) => {
    if (event.key === 'ArrowDown' && results.value.length) {
        event.preventDefault();
        active.value = (active.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp' && results.value.length) {
        event.preventDefault();
        active.value = (active.value - 1 + results.value.length) % results.value.length;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (results.value[active.value]) go(results.value[active.value]);
    } else if (event.key === 'Escape') {
        if (query.value !== '') {
            query.value = '';
        } else {
            inputRef.value?.blur();
        }
    }
};

// A click on a result lands before the list closes.
const closeSoon = () => window.setTimeout(() => (open.value = false), 120);

const showList = computed(() => open.value && query.value.trim() !== '');
</script>

<template>
    <div class="relative w-full max-w-sm" role="search">
        <label :for="props.id" class="sr-only">Search the pages of CARES</label>
        <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
        <input
            :id="props.id"
            ref="inputRef"
            v-model="query"
            data-module-search
            type="search"
            role="combobox"
            autocomplete="off"
            maxlength="100"
            aria-keyshortcuts="/ Control+K Meta+K"
            :aria-expanded="showList"
            :aria-controls="listId"
            :aria-activedescendant="showList && results.length ? `${listId}-${active}` : undefined"
            placeholder="Search CARES…"
            class="block w-full rounded-md border border-border-strong bg-surface-muted py-2 pr-9 pl-9 text-sm text-text placeholder:text-text-muted focus:border-primary focus:bg-surface focus:ring-2 focus:ring-focus-ring focus:outline-none [&::-webkit-search-cancel-button]:hidden"
            @focus="open = true"
            @blur="closeSoon"
            @keydown="onKeydown"
        />
        <kbd
            v-if="!query"
            class="pointer-events-none absolute top-1/2 right-2 hidden -translate-y-1/2 rounded border border-border bg-surface px-1.5 font-sans text-xs text-text-muted md:block"
            aria-hidden="true"
            title="Press / to search"
        >/</kbd>

        <ul
            v-if="showList"
            :id="listId"
            role="listbox"
            aria-label="Pages"
            class="absolute inset-x-0 top-full z-50 mt-1 max-h-80 overflow-y-auto rounded-md border border-border bg-surface py-1 shadow-lg"
        >
            <li v-if="results.length === 0" class="px-3 py-2 text-sm text-text-muted">No pages match "{{ query.trim() }}".</li>
            <li
                v-for="(item, index) in results"
                :id="`${listId}-${index}`"
                :key="item.href"
                role="option"
                :aria-selected="index === active"
                class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm"
                :class="index === active ? 'bg-info-bg text-primary' : 'text-text hover:bg-surface-muted'"
                @mousedown.prevent="go(item)"
                @mousemove="active = index"
            >
                <Icon :name="item.icon" size="sm" class="shrink-0" />
                <span class="min-w-0">
                    <span class="block truncate font-medium">{{ item.label }}</span>
                    <span v-if="item.context" class="block truncate text-xs text-text-muted">{{ item.context }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>