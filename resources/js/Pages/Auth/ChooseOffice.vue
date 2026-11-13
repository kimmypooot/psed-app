<script setup>
import { ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import { useBranding } from '@/Composables/useBranding';

// Shown after sign-in to someone who heads more than one Field Office (a Director II).
// The offices come from IMIS; the header switcher is the way to change later.
const page = usePage();
const brand = useBranding();
const busy = ref(null);

const choose = (office) => {
    if (busy.value) return;

    busy.value = office.id;
    router.post('/office', { office_id: office.id }, { onFinish: () => (busy.value = null) });
};
</script>

<template>
    <Head title="Choose a Field Office" />

    <div class="flex min-h-dvh flex-col bg-surface-muted">
        <main class="flex flex-1 items-center justify-center px-4 py-8 sm:px-6 lg:p-10">
            <div class="w-full max-w-lg rounded-2xl border border-border bg-surface px-6 py-9 shadow-sm sm:px-10">
                <div class="mb-6 flex items-center gap-4">
                    <img :src="brand.app_logo" alt="" class="h-11 w-auto shrink-0 select-none" draggable="false" />
                    <div>
                        <p class="text-base leading-snug font-semibold text-primary">CARES</p>
                        <p class="text-xs text-text-muted">Civil Service Commission Regional Office VIII</p>
                    </div>
                </div>

                <h1 class="text-xl font-semibold text-text">Which Field Office are you working as?</h1>
                <p class="mt-2 text-sm text-text-muted">
                    You head more than one Field Office. Pick one to continue; you can switch from the header at any time.
                </p>

                <ul class="mt-6 space-y-3">
                    <li v-for="office in page.props.auth.user.offices" :key="office.id">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl border border-border px-4 py-3 text-left hover:border-primary hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:opacity-60"
                            :disabled="busy !== null"
                            @click="choose(office)"
                        >
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Icon name="building" size="md" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-text">{{ office.name }}</span>
                                <span v-if="office.code" class="block text-xs text-text-muted">{{ office.code }}</span>
                            </span>
                            <span class="text-sm text-primary">{{ busy === office.id ? 'Opening…' : 'Continue →' }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </main>
    </div>
</template>
