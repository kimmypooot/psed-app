<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';

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
    /** 'plantilla', 'casual-contractual', or null for the whole list (the MOA's two sheets). */
    type: { type: String, default: null },
    filters: { type: Object, required: true },
    agencies: { type: Array, required: true },
    offices: { type: Array, default: () => [] },
    /** { all, plantilla, casual, pending, acted }: the whole list's size, whatever the filters leave. */
    counts: { type: Object, required: true },
    pageSizes: { type: Array, default: () => [15, 25, 50, 100] },
});

// Each MOA sheet is its own list at its own address; the filters and paging stay on it.
const basePath = props.type ? `/appointments/${props.type}` : '/appointments';
const views = {
    plantilla: { title: 'Plantilla', description: "Appointments to plantilla positions (the MOA's Plantilla sheet), with who processes each." },
    'casual-contractual': { title: 'Casual / Contractual', description: "Casual and contractual appointments (the MOA's CasualCon sheet), with who processes each." },
};
const typeView = computed(() => views[props.type] ?? { title: 'Appointments', description: 'Every appointment received, with who processes it.' });
// The MOA's two sheets, with how many appointments each holds.
const tabs = computed(() => [
    { href: '/appointments', label: 'All', type: null, count: props.counts.all },
    { href: '/appointments/plantilla', label: 'Plantilla', type: 'plantilla', count: props.counts.plantilla },
    { href: '/appointments/casual-contractual', label: 'Casual / Contractual', type: 'casual-contractual', count: props.counts.casual },
]);

// Jump straight to a page of a long list.
const jumpTo = ref(null);
const jump = () => {
    const page = Math.min(Math.max(Number(jumpTo.value) || 1, 1), props.appointments.last_page);
    const query = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, v]) => v !== '' && v !== null && v !== false));
    router.get(basePath, query, { preserveState: true, preserveScroll: true });
    jumpTo.value = null;
};

// Filters live in the query string: a change re-requests the list.
const filters = useListFilters(basePath, { ...props.filters });

const gradeOptions = Array.from({ length: 33 }, (_, i) => ({ value: String(i + 1), label: `SG ${i + 1}` }));
const processorOptions = [{ value: 'field_office', label: 'Field Office' }, { value: 'psed', label: 'PSED' }];
// The Pending / Acted upon switch: the thing staff come here to do, one click instead of a dropdown.
const triage = computed(() => [
    { value: '', label: 'All', count: props.counts.pending + props.counts.acted },
    { value: 'pending', label: 'Pending', count: props.counts.pending },
    { value: 'acted', label: 'Acted upon', count: props.counts.acted },
]);

// The narrower filters sit behind one button; the ones in use show as chips that can be removed.
const panelOpen = ref(false);
const chips = computed(() => {
    const list = [];
    if (filters.sg) list.push({ key: 'sg', label: `SG ${filters.sg}`, clear: ['sg'] });
    if (filters.agency) list.push({ key: 'agency', label: filters.agency, clear: ['agency'] });
    if (filters.date_from || filters.date_to) {
        const [from, to] = [filters.date_from && formatDate(filters.date_from), filters.date_to && formatDate(filters.date_to)];
        list.push({ key: 'dates', label: from && to ? `Received ${from} to ${to}` : from ? `Received from ${from}` : `Received until ${to}`, clear: ['date_from', 'date_to'] });
    }
    if (filters.processor) list.push({ key: 'processor', label: `Processed by ${processorOptions.find((o) => o.value === filters.processor)?.label ?? filters.processor}`, clear: ['processor'] });
    if (filters.office) list.push({ key: 'office', label: props.offices.find((o) => String(o.id) === String(filters.office))?.name ?? 'Field Office', clear: ['office'] });

    return list;
});
const removeChip = (chip) => chip.clear.forEach((key) => (filters[key] = ''));

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

// The left edge of a row says where it stands, and so does the colour it takes on under the pointer:
// green when approved, red when not, blue while it waits. (Written out in full so Tailwind finds each class.)
const ok = 'shadow-[inset_3px_0_0_var(--color-success)] [--row-hover:var(--color-success-bg)]';
const no = 'shadow-[inset_3px_0_0_var(--color-danger)] [--row-hover:var(--color-danger-bg)]';
const waiting = 'shadow-[inset_3px_0_0_var(--color-info)] [--row-hover:var(--color-info-bg)]';
const rail = (a) =>
    ({
        Approved: ok,
        Validated: ok,
        Disapproved: no,
        Invalidated: no,
    })[a.csc_action] ?? waiting;

// A click anywhere on a row opens it, except on the links and buttons inside it, which do their own thing.
const openRow = (event, appointment) => {
    if (event.target.closest('a, button, input, select, label')) return;
    view(appointment);
};
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
    ({ Approved: 'success', Validated: 'success', Disapproved: 'danger', Invalidated: 'danger', Pending: 'info' })[action ?? 'Pending'] ?? 'neutral';

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
    <Head :title="typeView.title" />

    <StaffLayout>
        <PageHeader
            :title="typeView.title"
            :description="typeView.description"
            :breadcrumbs="type ? [{ label: 'Appointments', href: '/appointments' }, { label: typeView.title }] : []"
        >
            <template #actions>
                <Button variant="outline" @click="importing = true"><Icon name="upload" size="sm" />Import</Button>
                <Button href="/appointments/create" variant="success"><Icon name="plus" size="sm" />Encode appointment</Button>
            </template>
        </PageHeader>

        <!-- The MOA's two sheets, with how much each holds -->
        <nav class="mb-5 flex gap-1 border-b border-border" aria-label="Kind of appointment">
            <Link
                v-for="tab in tabs"
                :key="tab.href"
                :href="tab.href"
                class="-mb-px flex items-center gap-2 rounded-t-md border-b-2 px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="tab.type === type ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:border-border-strong hover:text-text'"
                :aria-current="tab.type === type ? 'page' : undefined"
            >
                {{ tab.label }}
                <span class="rounded-full px-2 py-0.5 text-xs tabular-nums" :class="tab.type === type ? 'bg-info-bg text-primary' : 'bg-surface-muted text-text-muted'">{{ tab.count.toLocaleString() }}</span>
            </Link>
        </nav>

        <!-- Find, then narrow -->
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <div class="flex min-w-64 max-w-xl flex-1">
                <SearchBox v-model="filters.search" placeholder="Search name, agency or position" label="Search appointments" />
            </div>

            <div class="inline-flex rounded-lg border border-border bg-surface p-0.5" role="group" aria-label="Show appointments">
                <button
                    v-for="t in triage"
                    :key="t.value"
                    type="button"
                    class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :class="(filters.status || '') === t.value ? 'bg-primary text-white shadow-xs' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
                    :aria-pressed="(filters.status || '') === t.value"
                    @click="filters.status = t.value"
                >
                    {{ t.label }}
                    <span class="text-xs tabular-nums" :class="(filters.status || '') === t.value ? 'text-white/80' : 'text-text-muted'">{{ t.count.toLocaleString() }}</span>
                </button>
            </div>

            <Button variant="outline" aria-controls="filter-panel" :aria-expanded="panelOpen" @click="panelOpen = !panelOpen">
                <Icon name="funnel" size="sm" />Filters
                <span v-if="chips.length" class="rounded-full bg-primary px-1.5 text-xs font-semibold text-white tabular-nums">{{ chips.length }}</span>
            </Button>
        </div>

        <!-- The narrower filters -->
        <div v-show="panelOpen" id="filter-panel" class="mb-3 grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="f-sg" class="block text-sm font-medium text-text">Salary grade</label>
                <Combobox :allow-custom="false" id="f-sg" v-model="filters.sg" :options="gradeOptions" placeholder="All" class="mt-1" />
            </div>
            <div>
                <Combobox id="f-agency" v-model="filters.agency" label="Agency" :options="agencies" placeholder="Any agency" />
            </div>
            <div v-if="offices.length">
                <label for="f-office" class="block text-sm font-medium text-text">Field Office</label>
                <Combobox :allow-custom="false" id="f-office" v-model="filters.office" :options="officeOptions" placeholder="All offices" class="mt-1" />
            </div>
            <div>
                <TextInput id="f-from" v-model="filters.date_from" type="date" label="Received from" />
            </div>
            <div>
                <TextInput id="f-to" v-model="filters.date_to" type="date" label="Received until" />
            </div>
            <div>
                <label for="f-processor" class="block text-sm font-medium text-text">Processed by</label>
                <Combobox :allow-custom="false" id="f-processor" v-model="filters.processor" :options="processorOptions" placeholder="Anyone" class="mt-1" />
            </div>
        </div>

        <!-- What is narrowing the list right now, each removable -->
        <ul v-if="chips.length" class="mb-3 flex flex-wrap items-center gap-2" aria-label="Filters in use">
            <li v-for="chip in chips" :key="chip.key" class="inline-flex items-center gap-1 rounded-full bg-info-bg py-1 pr-1 pl-3 text-xs font-medium text-primary">
                {{ chip.label }}
                <button type="button" class="rounded-full p-0.5 transition-colors hover:bg-primary/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" :aria-label="`Remove filter: ${chip.label}`" @click="removeChip(chip)">
                    <Icon name="close" size="sm" />
                </button>
            </li>
            <li><Button variant="ghost" size="sm" @click="reset">Clear all</Button></li>
        </ul>

        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-text-muted" aria-live="polite">{{ appointments.total.toLocaleString() }} appointment{{ appointments.total === 1 ? '' : 's' }}<span v-if="hasFilters"> match</span></p>
            <label class="flex items-center gap-2 text-sm text-text-muted">
                Rows per page
                <select v-model="filters.per_page" class="rounded-md border border-border bg-surface px-2 py-1 text-sm text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
                    <option v-for="n in pageSizes" :key="n" :value="String(n)">{{ n }}</option>
                </select>
            </label>
        </div>

        <div class="transition-opacity duration-150 motion-reduce:transition-none" :class="loading ? 'opacity-60' : ''" :aria-busy="loading">
        <Table :empty="!rows.length">
            <template #empty>
                <EmptyState
                    icon="clipboard"
                    :title="hasFilters ? 'No appointments match these filters' : type ? `No ${typeView.title} appointments yet` : 'No appointments yet'"
                    :description="hasFilters ? 'Try widening the dates or clearing a filter.' : type ? 'They appear here as they are encoded, or imported from the matching sheet of the MOA form.' : 'Encode the first appointment received from a client agency.'"
                >
                    <template #action>
                        <Button v-if="hasFilters" variant="outline" @click="reset"><Icon name="close" size="sm" />Clear all filters</Button>
                        <Button v-else href="/appointments/create" variant="success"><Icon name="plus" size="sm" />Encode appointment</Button>
                    </template>
                </EmptyState>
            </template>

            <thead>
                <tr>
                    <th v-if="offices.length" scope="col">Field Office</th>
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
                <tr v-for="a in rows" :key="a.id" class="cursor-pointer" :class="rail(a)" @click="openRow($event, a)">
                    <td v-if="offices.length" class="text-xs">{{ a.office }}</td>
                    <td>
                        <button type="button" class="text-left text-xs font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="view(a)">{{ a.full_name }}</button>
                        <div v-if="a.is_first_time_entrant" class="mt-0.5"><Badge tone="info">First-time entrant</Badge></div>
                    </td>
                    <td class="text-xs">{{ a.agency }}</td>
                    <td class="max-w-64 text-xs">{{ a.position }}</td>
                    <td class="text-xs tabular-nums">{{ a.salary_grade }}</td>
                    <td class="text-xs">{{ a.employment_status }}<div class="text-[0.7rem] text-text-muted">{{ a.nature_of_appointment }}</div></td>
                    <td class="text-xs whitespace-nowrap">{{ formatDate(a.date_received) }}</td>
                    <td><Badge :tone="a.processed_by === 'psed' ? 'info' : 'neutral'">{{ a.processed_by_label }}</Badge></td>
                    <td><Badge :tone="actionTone(a.csc_action)">{{ a.csc_action ?? 'Pending' }}</Badge></td>
                    <td class="whitespace-nowrap text-right">
                        <Button variant="ghost" size="sm" icon-only aria-label="View appointment" @click="view(a)"><Icon name="eye" size="sm" /></Button>
                        <Button v-if="a.can_update" :href="`/appointments/${a.id}/edit`" variant="ghost" size="sm" icon-only aria-label="Edit appointment"><Icon name="pencil" size="sm" /></Button>
                        <Button v-if="a.can_delete" variant="ghost" size="sm" icon-only aria-label="Delete appointment" @click="remove(a)"><Icon name="trash" size="sm" /></Button>
                    </td>
                </tr>
            </tbody>
        </Table>
        </div>

        <ImportModal :open="importing" :offices="offices" @close="importing = false" />
        <AppointmentDetailsModal :open="viewing" :appointment-id="viewingId" :ids="rows.map((r) => r.id)" @navigate="viewingId = $event" @close="viewing = false" />

        <Pagination
            :prev-url="appointments.prev_page_url"
            :next-url="appointments.next_page_url"
            :current-page="appointments.current_page"
            :last-page="appointments.last_page"
        />

        <form v-if="appointments.last_page > 2" class="mt-3 flex items-center justify-center gap-2 text-sm text-text-muted" @submit.prevent="jump">
            <label for="jump-page">Go to page</label>
            <input
                id="jump-page"
                v-model.number="jumpTo"
                type="number"
                min="1"
                :max="appointments.last_page"
                class="w-20 rounded-md border border-border bg-surface px-2 py-1 text-sm text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            />
            <Button type="submit" variant="outline" size="sm">Go</Button>
        </form>
    </StaffLayout>
</template>
