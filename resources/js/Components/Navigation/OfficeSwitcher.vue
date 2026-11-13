<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';

/**
 * For someone who works as more than one Field Office (a Director II heading
 * several): shows the one they are acting as and lets them move to another. The
 * offices come from the server, which refuses any other (OfficeController);
 * nothing renders for everyone else.
 */
const page = usePage();
const offices = computed(() => page.props.auth.user?.offices ?? []);
const current = computed(() => offices.value.find((o) => o.id === page.props.auth.user?.office_id));

const open = ref(false);
const rootRef = ref(null);
const buttonRef = ref(null);

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) open.value = false;
};

const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    open.value = false;
    buttonRef.value?.focus();
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

const choose = (office) => {
    open.value = false;
    if (office.id !== current.value?.id) router.post('/office', { office_id: office.id });
};
</script>

<template>
    <div v-if="offices.length > 1" ref="rootRef" class="relative">
        <button
            ref="buttonRef"
            type="button"
            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :class="open ? 'bg-surface-muted' : ''"
            aria-label="Switch Field Office"
            title="Switch Field Office"
            aria-haspopup="true"
            :aria-expanded="open"
            aria-controls="office-switcher-panel"
            @click="open = !open"
        >
            <Icon name="building" size="sm" class="text-primary" />
            <span class="hidden flex-col text-left leading-tight sm:flex">
                <span class="text-[0.65rem] tracking-wide text-text-muted uppercase">Working as</span>
                <span class="font-semibold text-text">{{ current?.name ?? 'Choose office' }}</span>
            </span>
            <span class="max-w-28 truncate font-semibold text-text sm:hidden">{{ current?.name }}</span>
            <Icon name="chevronDown" size="sm" class="shrink-0 text-text-muted" />
        </button>

        <div
            v-if="open"
            id="office-switcher-panel"
            class="absolute right-0 z-50 mt-2 w-72 overflow-hidden rounded-xl border border-border bg-surface shadow-lg"
        >
            <h2 class="border-b border-border bg-surface-muted px-4 py-3 text-xs font-semibold tracking-wide text-text-muted uppercase">
                Switch Field Office
            </h2>
            <ul class="py-1">
                <li v-for="office in offices" :key="office.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-surface-muted focus:bg-surface-muted focus:outline-none"
                        :aria-current="office.id === current?.id ? 'true' : undefined"
                        @click="choose(office)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-text">{{ office.name }}</span>
                            <span v-if="office.code" class="block text-xs text-text-muted">{{ office.code }}</span>
                        </span>
                        <span v-if="office.id === current?.id" class="text-xs font-semibold text-primary">Current</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
