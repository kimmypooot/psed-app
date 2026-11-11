<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchBox from '@/Components/Forms/SearchBox.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import AppointmentDetailsModal from '@/Components/Appointments/AppointmentDetailsModal.vue';
import ImportModal from '@/Components/Appointments/ImportModal.vue';
import { useListFilters } from '@/Composables/useListFilters';
import { confirm } from '@/Composables/useDialog';

const props = defineProps({
    appointments: { type: Object, required: true },
    filters: { type: Object, required: true },
    agencies: { type: Array, required: true },
    offices: { type: Array, default: () => [] },
});

// Filters live in the query string: a change re-requests the list.
const filters = useListFilters('/appointments', { ...props.filters });

const gradeOptions = Array.from({ length: 33 }, (_, i) => ({ value: String(i + 1), label: `SG ${i + 1}` }));
const processorOptions = [{ value: 'field_office', label: 'Field Office' }, { value: 'psed', label: 'PSED' }];
const statusOptions = [{ value: 'pending', label: 'Awaiting action' }, { value: 'acted', label: 'Acted upon' }];
const officeOptions = computed(() => props.offices.map((o) => ({ value: String(o.id), label: o.name })));

const hasFilters = computed(() =>
    ['search', 'sg', 'agency', 'date_from', 'date_to', 'processor', 'status', 'office'].some((key) => filters[key] !== '' && filters[key] != null),
);

const reset = () => {
    for (const key of ['search', 'sg', 'agency', 'date_from', 'date_to', 'processor', 'status', 'office']) filters[key] = '';
};

const sortBy = (column) => {
    if (filters.sort === column) {
        filters.dir = filters.dir === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = column;
        filters.dir = column === 'date_received' ? 'desc' : 'asc';
    }
};

const ariaSort = (column) => (filters.sort === column ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none');

const formatDate = (value) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';

const actionTone = (action) =>
    ({ Approved: 'success', Validated: 'success', Disapproved: 'danger', Invalidated: 'danger', Pending: 'warning' })[action] ?? 'neutral';

const remove = async (a) => {
    const ok = await confirm({
        title: 'Delete this appointment?',
        text: `${a.full_name} (${a.position}) will be removed from the records.`,
        confirmText: 'Delete',
        tone: 'danger',
    });

    if (ok) router.delete(`/appointments/${a.id}`, { preserveScroll: true });
};

const rows = computed(() => props.appointments.data);

// Importing from the MOA form.
const importing = ref(false);

// The read-only View dialog.
const viewingId = ref(null);
const viewing = ref(false);
const view = (appointment) => {
    viewingId.value = appointment.id;
    viewing.value = true;
};
</script>

<template>
    <Head title="Appointments" />

    <StaffLayout>
        <PageHeader title="Appointments" description="Every appointment received, with who processes it.">
            <template #actions>
                <Button variant="outline" @click="importing = true"><Icon name="upload" size="sm" />Import</Button>
                <Button href="/appointments/create" variant="success"><Icon name="plus" size="sm" />Encode appointment</Button>
            </template>
        </PageHeader>

        <Card class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <SearchBox v-model="filters.search" placeholder="Search name, agency or position" label="Search appointments" />

                <div class="w-32">
                    <label for="f-sg" class="block text-sm font-medium text-text">Salary grade</label>
                    <Combobox :allow-custom="false" id="f-sg" v-model="filters.sg" :options="gradeOptions" placeholder="All" class="mt-1" />
                </div>
                <div class="w-64">
                    <Combobox id="f-agency" v-model="filters.agency" label="Agency" :options="agencies" placeholder="Any agency" />
                </div>
                <div class="w-40">
                    <TextInput id="f-from" v-model="filters.date_from" type="date" label="Received from" />
                </div>
                <div class="w-40">
                    <TextInput id="f-to" v-model="filters.date_to" type="date" label="Received until" />
                </div>
                <div class="w-40">
                    <label for="f-processor" class="block text-sm font-medium text-text">Processed by</label>
                    <Combobox :allow-custom="false" id="f-processor" v-model="filters.processor" :options="processorOptions" placeholder="Anyone" class="mt-1" />
                </div>
                <div class="w-40">
                    <label for="f-status" class="block text-sm font-medium text-text">Status</label>
                    <Combobox :allow-custom="false" id="f-status" v-model="filters.status" :options="statusOptions" placeholder="Any" class="mt-1" />
                </div>
                <div v-if="offices.length" class="w-56">
                    <label for="f-office" class="block text-sm font-medium text-text">Field Office</label>
                    <Combobox :allow-custom="false" id="f-office" v-model="filters.office" :options="officeOptions" placeholder="All offices" class="mt-1" />
                </div>
                <Button v-if="hasFilters" variant="ghost" size="sm" @click="reset"><Icon name="close" size="sm" />Clear filters</Button>
            </div>
        </Card>

        <p class="mb-2 text-sm text-text-muted" aria-live="polite">{{ appointments.total }} appointment{{ appointments.total === 1 ? '' : 's' }}</p>

        <Table :empty="!rows.length">
            <template #empty>
                <EmptyState
                    icon="clipboard"
                    :title="hasFilters ? 'No appointments match these filters' : 'No appointments yet'"
                    :description="hasFilters ? 'Try widening the dates or clearing a filter.' : 'Encode the first appointment received from a client agency.'"
                />
            </template>

            <thead>
                <tr>
                    <th scope="col" :aria-sort="ariaSort('name')"><button type="button" class="inline-flex items-center gap-1 font-semibold" @click="sortBy('name')">Appointee <Icon name="sort" size="sm" /></button></th>
                    <th scope="col" :aria-sort="ariaSort('agency')"><button type="button" class="inline-flex items-center gap-1 font-semibold" @click="sortBy('agency')">Agency <Icon name="sort" size="sm" /></button></th>
                    <th scope="col">Position</th>
                    <th scope="col" :aria-sort="ariaSort('salary_grade')"><button type="button" class="inline-flex items-center gap-1 font-semibold" @click="sortBy('salary_grade')">SG <Icon name="sort" size="sm" /></button></th>
                    <th scope="col">Status / Nature</th>
                    <th scope="col" :aria-sort="ariaSort('date_received')"><button type="button" class="inline-flex items-center gap-1 font-semibold" @click="sortBy('date_received')">Received <Icon name="sort" size="sm" /></button></th>
                    <th scope="col">Processed by</th>
                    <th scope="col">Action</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in rows" :key="a.id">
                    <td>
                        <button type="button" class="text-left font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="view(a)">{{ a.full_name }}</button>
                        <div v-if="a.is_first_time_entrant" class="mt-0.5"><Badge tone="info">First-time entrant</Badge></div>
                        <div v-if="offices.length" class="text-xs text-text-muted">{{ a.office }}</div>
                    </td>
                    <td>{{ a.agency }}</td>
                    <td class="max-w-64">{{ a.position }}</td>
                    <td class="tabular-nums">{{ a.salary_grade }}</td>
                    <td>{{ a.employment_status }}<div class="text-xs text-text-muted">{{ a.nature_of_appointment }}</div></td>
                    <td class="whitespace-nowrap">{{ formatDate(a.date_received) }}</td>
                    <td><Badge :tone="a.processed_by === 'psed' ? 'info' : 'neutral'">{{ a.processed_by_label }}</Badge></td>
                    <td><Badge v-if="a.csc_action" :tone="actionTone(a.csc_action)">{{ a.csc_action }}</Badge><span v-else class="text-text-muted">Awaiting</span></td>
                    <td class="whitespace-nowrap text-right">
                        <Button variant="ghost" size="sm" icon-only aria-label="View appointment" @click="view(a)"><Icon name="eye" size="sm" /></Button>
                        <Button v-if="a.can_update" :href="`/appointments/${a.id}/edit`" variant="ghost" size="sm" icon-only aria-label="Edit appointment"><Icon name="pencil" size="sm" /></Button>
                        <Button v-if="a.can_delete" variant="ghost" size="sm" icon-only aria-label="Delete appointment" @click="remove(a)"><Icon name="trash" size="sm" /></Button>
                    </td>
                </tr>
            </tbody>
        </Table>

        <ImportModal :open="importing" :offices="offices" @close="importing = false" />
        <AppointmentDetailsModal :open="viewing" :appointment-id="viewingId" @close="viewing = false" />

        <Pagination
            :prev-url="appointments.prev_page_url"
            :next-url="appointments.next_page_url"
            :current-page="appointments.current_page"
            :last-page="appointments.last_page"
        />
    </StaffLayout>
</template>
