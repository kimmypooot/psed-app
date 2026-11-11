<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The page footer, after IMIS's (Components/Navigation/AppFooter.vue): the
 * agency line, the release, and the links that live outside CARES. "About
 * CARES" opens a dialog that belongs to the shell, so it is emitted.
 */
const emit = defineEmits(['about']);

const page = usePage();
const version = computed(() => page.props.app?.version);
const imisUrl = computed(() => page.props.imisUrl);
const year = new Date().getFullYear();

const linkClass =
    'rounded text-xs text-text-muted transition-colors hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <footer class="mt-auto border-t border-border bg-surface">
        <div class="flex flex-col gap-3 px-4 py-4 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
            <p class="text-xs text-text-muted">
                &copy; {{ year }} Civil Service Commission Regional Office VIII
                <span v-if="version" class="whitespace-nowrap">· CARES v{{ version }}</span>
            </p>

            <nav aria-label="Footer" class="flex flex-wrap gap-x-5 gap-y-1">
                <a v-if="imisUrl" :href="imisUrl" :class="linkClass">Back to IMIS</a>
                <button type="button" :class="linkClass" @click="emit('about')">About CARES</button>
            </nav>
        </div>
    </footer>
</template>
