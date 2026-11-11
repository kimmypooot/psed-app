<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import SearchBox from '@/Components/Forms/SearchBox.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
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

const viewing = ref(null);
</script>

<template>
    <Head title="Audit Trail" />

    <StaffLayout>
        <PageHeader
            title="Audit Trail"
            description="Who did what and when. Every change to an appointment, Qualification Standards document or Field Office is kept, with what it was before."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Audit Trail' }]"
        />

        <Card class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <SearchBox v-model="filters.search" placeholder="Search by person's name or username" label="Search by person" />
                <div class="w-56">
                    <label for="f-group" class="block text-sm font-medium text-text">What</label>
                    <Combobox :allow-custom="false" id="f-group" v-model="filters.group" :options="groups" placeholder="Everything" class="mt-1" />
                </div>
                <div class="w-40">
                    <TextInput id="f-from" v-model="filters.date_from" type="date" label="From" />
                </div>
                <div class="w-40">
                    <TextInput id="f-to" v-model="filters.date_to" type="date" label="Until" />
                </div>
                <Button v-if="hasFilters" variant="ghost" size="sm" @click="reset"><Icon name="close" size="sm" />Clear filters</Button>
            </div>
            <p v-if="filters.subject" class="mt-3 flex items-center gap-2 rounded-md bg-info-bg px-3 py-2 text-sm text-info">
                <Icon name="clock" size="sm" />
                Showing the history of one record only.
                <button type="button" class="font-medium underline" @click="filters.subject = ''">Show everything</button>
            </p>
        </Card>

        <p class="mb-2 text-sm text-text-muted" aria-live="polite">{{ entries.total }} entr{{ entries.total === 1 ? 'y' : 'ies' }}</p>

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
                <tr v-for="e in entries.data" :key="e.id">
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
