<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * `items`: [{ label, href }]. The last item is treated as the current
 * page (rendered as text, not a link, with aria-current="page").
 */
defineProps({
    items: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-text-muted">
            <li v-for="(item, index) in items" :key="item.label" class="flex items-center gap-1">
                <span v-if="index > 0" class="opacity-60" aria-hidden="true">/</span>
                <Link
                    v-if="item.href && index < items.length - 1"
                    :href="item.href"
                    class="text-primary hover:text-primary-hover"
                >
                    {{ item.label }}
                </Link>
                <span v-else class="text-text" :aria-current="index === items.length - 1 ? 'page' : undefined">
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
