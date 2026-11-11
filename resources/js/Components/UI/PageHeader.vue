<script setup>
import Breadcrumbs from '../Navigation/Breadcrumbs.vue';

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: null,
    },
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <!-- Legacy IMIS ".pagetitle": bold navy title, breadcrumb underneath. -->
    <div class="mb-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="flex items-center gap-3 text-xl font-semibold text-primary sm:text-2xl">
                    <span class="h-6 w-1 shrink-0 rounded-full bg-accent sm:h-7" aria-hidden="true" />
                    {{ title }}
                </h1>
                <Breadcrumbs v-if="breadcrumbs.length" :items="breadcrumbs" class="mt-1 ml-4" />
                <p v-if="description" class="mt-2 ml-4 max-w-3xl text-sm text-text-muted">{{ description }}</p>
            </div>

            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.default" class="mt-3">
            <slot />
        </div>
    </div>
</template>
