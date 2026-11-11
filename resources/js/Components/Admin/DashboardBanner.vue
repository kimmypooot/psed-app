<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import OrbitCircles from '../UI/OrbitCircles.vue';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The welcome banner atop the management dashboards (system administration
 * and Human Resources), with the portal banner's orbiting circles
 * (OrbitCircles): an area badge, greeting, date and clock, and quick
 * actions — `actions` is already filtered to what the viewer may open.
 * With `generatedAt`, it also shows when the figures below were computed
 * and a Refresh button (emits `refresh`; pass `refreshing` meanwhile).
 * The `actions` slot adds further buttons after the quick actions.
 */
defineEmits(['refresh']);

defineProps({
    badge: { type: String, required: true },
    badgeIcon: { type: String, default: 'shield' },
    /** [{ label, href, icon }] */
    actions: { type: Array, default: () => [] },
    generatedAt: { type: String, default: null },
    refreshing: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const now = ref(new Date());
let clock;
onMounted(() => (clock = window.setInterval(() => (now.value = new Date()), 30000)));
onBeforeUnmount(() => window.clearInterval(clock));
</script>

<template>
    <section class="relative mb-4 overflow-hidden rounded-lg bg-primary px-6 py-6 text-white">
        <OrbitCircles />

        <div class="relative z-10 flex flex-wrap items-center justify-between gap-4">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold">
                    <Icon :name="badgeIcon" size="sm" />
                    {{ badge }}
                </span>
                <h1 class="mt-2 text-xl font-semibold sm:text-2xl">Welcome back, {{ user?.name }}!</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-white/80">
                    <Icon name="calendar" size="sm" />
                    {{ now.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) }}
                    <span aria-hidden="true">·</span>
                    <Icon name="clock" size="sm" />
                    {{ now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) }}
                </p>
                <p v-if="generatedAt" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-white/80">
                    Data as of {{ formatDateTime(generatedAt) }}
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md border border-white/30 px-2 py-0.5 font-medium text-white transition-colors hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="refreshing"
                        @click="$emit('refresh')"
                    >
                        <Icon name="refresh" size="sm" :class="{ 'motion-safe:animate-spin': refreshing }" />
                        Refresh
                    </button>
                </p>
            </div>
            <div v-if="actions.length || $slots.actions" class="flex flex-wrap gap-2">
                <Link
                    v-for="action in actions"
                    :key="action.href"
                    :href="action.href"
                    class="inline-flex items-center gap-1.5 rounded-md bg-white/15 px-3 py-2 text-sm font-medium transition-colors hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                >
                    <Icon :name="action.icon" size="sm" />
                    {{ action.label }}
                </Link>
                <slot name="actions" />
            </div>
        </div>
    </section>
</template>
