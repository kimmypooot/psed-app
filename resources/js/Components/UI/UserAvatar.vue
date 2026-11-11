<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Spinner from './Spinner.vue';

/**
 * A user's profile photo, or their initials on navy when there is none.
 * `highlight` adds the legacy IMIS header ring: a light border that turns
 * brand blue when the surrounding `group` is hovered. A spinner shows
 * while the photo is still loading.
 */
const props = defineProps({
    name: { type: String, required: true },
    photoUrl: { type: String, default: null },
    size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) },
    highlight: { type: Boolean, default: false },
});

// A photo that no longer loads (removed, or a stale saved URL) falls back to initials.
const photoFailed = ref(false);
const photoLoaded = ref(false);
const photoRef = ref(null);
watch(
    () => props.photoUrl,
    () => {
        photoFailed.value = false;
        photoLoaded.value = false;
    },
);
// A cached photo can finish before the load listener is attached.
onMounted(() => {
    if (photoRef.value?.complete && photoRef.value.naturalWidth > 0) photoLoaded.value = true;
});

const ring = computed(() => (props.highlight ? 'border-2 border-border transition-colors group-hover:border-primary' : ''));

const sizes = { sm: 'h-7 w-7 text-[0.65rem]', md: 'h-9 w-9 text-xs', lg: 'h-14 w-14 text-base', xl: 'h-24 w-24 text-2xl' };

const spinnerSizes = { sm: 'sm', md: 'sm', lg: 'md', xl: 'lg' };

const initials = computed(() =>
    props.name
        .split(/\s+/)
        .filter((part) => part && part.length > 1 && !part.endsWith('.'))
        .map((part) => part[0])
        .filter((_, index, all) => index === 0 || index === all.length - 1)
        .join('')
        .toUpperCase(),
);
</script>

<template>
    <span v-if="photoUrl && !photoFailed" class="relative inline-block shrink-0 rounded-full" :class="sizes[size]">
        <img
            ref="photoRef"
            :src="photoUrl"
            alt=""
            class="h-full w-full rounded-full object-cover"
            :class="ring || 'ring-2 ring-surface'"
            @load="photoLoaded = true"
            @error="photoFailed = true"
        />
        <span v-if="!photoLoaded" class="absolute inset-0 flex items-center justify-center rounded-full bg-surface-muted text-text-muted">
            <Spinner :size="spinnerSizes[size]" label="Loading photo…" />
        </span>
    </span>
    <span v-else class="flex shrink-0 items-center justify-center rounded-full bg-linear-to-br from-csc-blue-light to-csc-blue-dark font-semibold text-white" :class="[sizes[size], ring]" aria-hidden="true">
        {{ initials || '?' }}
    </span>
</template>
