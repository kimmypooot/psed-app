<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import SearchBox from '@/Components/Forms/SearchBox.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import { useListFilters } from '@/Composables/useListFilters';
import { formatDateTime } from '@/Utils/datetime';

/**
 * The audit trail: who did what and when, for PSED administrators. Read-only.
 * "Details" shows what each field was before and after; "History" narrows the
 * list to everything that happened to one appointment, document or office.
 */
const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    groups: { type: Array, required: true },
});

const filters = useListFilters('/audit-trail', { ...props.filters });
const hasFilters = computed(() => ['group', 'search', 'date_from', 'date_to', 'subject'].some((k) => filters[k] !== '' && filters[k] != null));
const reset = () => {
    for (const key of ['group', 'search', 'date_from', 'date_to', 'subject']) filters[key] = '';
};

// "What" as a row of choices, the way the Qualification Standards page shows its categories.
const categories = computed(() => [{ value: '', label: 'Everything' }, ...props.groups]);

// Dim the list while a new one loads, so a filter visibly "took".
const loading = ref(false);
const listeners = [];
onMounted(() => {
    listeners.push(
        router.on('start', (event) => (loading.value = event.detail.visit.method === 'get' && event.detail.visit.preserveState === true)),
        router.on('finish', () => (loading.value = false)),
    );
});
onBeforeUnmount(() => listeners.forEach((stop) => stop()));

// The left edge of a row, and the colour it takes under the pointer, are those of what was done, as the badge is
// (written out in full so Tailwind finds each class).
const rails = {
    success: 'shadow-[inset_3px_0_0_var(--color-success)] [--row-hover:var(--color-success-bg)]',
    info: 'shadow-[inset_3px_0_0_var(--color-info)] [--row-hover:var(--color-info-bg)]',
    warning: 'shadow-[inset_3px_0_0_var(--color-warning)] [--row-hover:var(--color-warning-bg)]',
    danger: 'shadow-[inset_3px_0_0_var(--color-danger)] [--row-hover:var(--color-danger-bg)]',
    neutral: 'shadow-[inset_3px_0_0_var(--color-border-strong)] [--row-hover:var(--color-surface-muted)]',
};

const viewing = ref(null);

// A click anywhere on a row opens what changed, except on the buttons inside it, which do their own thing.
const openRow = (event, entry) => {
    if (!entry.changes.length || event.target.closest('a, button, input, select, label')) return;
    viewing.value = entry;
};
</script>

<template>
    <Head title="Audit Trail" />

    <StaffLayout>
        <PageHeader
            title="Audit Trail"
            description="Who did what and when. Every change to an appointment, Qualification Standards document or Field Office is kept, with what it was before."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Audit Trail' }]"
        />

        <!-- What kind of thing, as a row of choices -->
        <nav class="mb-4 flex flex-wrap gap-2" aria-label="What happened">
            <button
                v-for="c in categories"
                :key="c.value"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="(filters.group || '') === c.value ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text hover:border-primary/40 hover:text-primary'"
                :aria-pressed="(filters.group || '') === c.value"
                @click="filters.group = c.value"
            >
                {{ c.label }}
            </button>
        </nav>

        <!-- Who, and when -->
        <div class="mb-3 flex flex-wrap items-end gap-3">
            <div class="flex min-w-64 max-w-xl flex-1">
                <SearchBox v-model="filters.search" placeholder="Search by person's name or username" label="Search by person" />
            </div>
            <div class="w-40">
                <TextInput id="f-from" v-model="filters.date_from" type="date" label="From" />
            </div>
            <div class="w-40">
                <TextInput id="f-to" v-model="filters.date_to" type="date" label="Until" />
            </div>
            <Button v-if="hasFilters" variant="ghost" size="sm" @click="reset"><Icon name="close" size="sm" />Clear all</Button>
        </div>

        <p v-if="filters.subject" class="mb-3 flex items-center gap-2 rounded-md bg-info-bg px-3 py-2 text-sm text-info">
            <Icon name="clock" size="sm" />
            Showing the history of one record only.
            <button type="button" class="font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="filters.subject = ''">Show everything</button>
        </p>

        <p class="mb-2 text-sm text-text-muted" aria-live="polite">{{ entries.total }} entr{{ entries.total === 1 ? 'y' : 'ies' }}</p>

        <div class="transition-opacity duration-150 motion-reduce:transition-none" :class="loading ? 'opacity-60' : ''" :aria-busy="loading">
        <Table :empty="!entries.data.length">
            <template #empty>
                <EmptyState icon="activity" title="Nothing recorded for these filters" description="Try widening the dates or clearing a filter." />
            </template>

            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Who</th>
                    <th scope="col">What</th>
                    <th scope="col">On</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="e in entries.data" :key="e.id" :class="[rails[e.tone] ?? rails.neutral, e.changes.length ? 'cursor-pointer' : '']" @click="openRow($event, e)">
                    <td class="whitespace-nowrap">{{ formatDateTime(e.at) }}</td>
                    <td>
                        <span v-if="e.user" class="font-medium">{{ e.user }}</span>
                        <span v-else class="text-text-muted">System</span>
                        <div v-if="e.username" class="text-xs text-text-muted">{{ e.username }}<span v-if="e.ip"> · {{ e.ip }}</span></div>
                    </td>
                    <td><Badge :tone="e.tone">{{ e.action_label }}</Badge></td>
                    <td class="max-w-80">{{ e.subject ?? '—' }}</td>
                    <td class="whitespace-nowrap text-right">
                        <Button v-if="e.changes.length" variant="outline" size="sm" @click="viewing = e"><Icon name="eye" size="sm" />Details</Button>
                        <Button v-if="e.subject_filter && e.subject_filter !== filters.subject" variant="ghost" size="sm" @click="filters.subject = e.subject_filter"><Icon name="clock" size="sm" />History</Button>
                    </td>
                </tr>
            </tbody>
        </Table>
        </div>

        <Pagination
            :prev-url="entries.prev_page_url"
            :next-url="entries.next_page_url"
            :current-page="entries.current_page"
            :last-page="entries.last_page"
        />

        <Modal :open="viewing !== null" :title="viewing?.action_label ?? ''" icon="activity" size="lg" @close="viewing = null">
            <template v-if="viewing" #summary>
                <p class="text-sm text-text-muted">
                    {{ viewing.user ?? 'System' }} · {{ formatDateTime(viewing.at) }}<span v-if="viewing.subject"> · {{ viewing.subject }}</span>
                </p>
            </template>

            <table v-if="viewing" class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border text-xs text-text-muted uppercase">
                        <th scope="col" class="py-2 pr-3 font-semibold">Field</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Before</th>
                        <th scope="col" class="py-2 font-semibold">After</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in viewing.changes" :key="c.field" class="border-b border-border/60 align-top">
                        <th scope="row" class="py-2 pr-3 font-medium text-text">{{ c.field }}</th>
                        <td class="py-2 pr-3 break-words" :class="c.from === null ? 'text-text-muted' : 'text-danger'">{{ c.from ?? '—' }}</td>
                        <td class="py-2 break-words" :class="c.to === null ? 'text-text-muted' : 'text-success'">{{ c.to ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <template #footer>
                <Button variant="secondary" @click="viewing = null">Close</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
