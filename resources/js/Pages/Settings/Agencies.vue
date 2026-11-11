<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import SearchBox from '@/Components/Forms/SearchBox.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import InputError from '@/Components/Forms/InputError.vue';

/**
 * The agency list. Agencies are kept in PSED (the Directory of Government Agencies);
 * this page brings them in. "Check for changes" shows what would change and changes
 * nothing; "Sync now" applies it. Each agency is suggested on the appointment form
 * to the Field Office whose jurisdiction it falls in.
 */
const props = defineProps({
    offices: { type: Array, required: true },
    total: { type: Number, required: true },
    unassigned: { type: Array, default: () => [] },
    without_office: { type: Number, default: 0 },
    last_sync: { type: Object, default: null },
    directory_url: { type: String, default: null },
});

const page = usePage();
const result = computed(() => page.props.flash?.agency_sync ?? null);

const working = ref(null); // 'check' | 'sync'

const run = (dryRun) => {
    working.value = dryRun ? 'check' : 'sync';
    router.post('/settings/agencies/sync', { dry_run: dryRun }, { preserveScroll: true, onFinish: () => (working.value = null) });
};

// Search across every jurisdiction: offices with no match are hidden, the others open.
const search = ref('');
const needle = computed(() => search.value.trim().toLowerCase());
const searching = computed(() => needle.value !== '');
const visibleOffices = computed(() =>
    props.offices
        .map((o) => ({ ...o, shown: searching.value ? o.list.filter((a) => a.name.toLowerCase().includes(needle.value)) : o.list }))
        .filter((o) => !searching.value || o.shown.length),
);
const unassignedShown = computed(() => (searching.value ? props.unassigned.filter((n) => n.toLowerCase().includes(needle.value)) : props.unassigned));

// Which accordions are open. A search opens every match, whatever was open before.
const opened = ref({});
const isOpen = (key) => searching.value || !!opened.value[key];
const onToggle = (key, e) => {
    if (!searching.value) opened.value = { ...opened.value, [key]: e.target.open };
};
const allKeys = computed(() => [...props.offices.map((o) => o.id), ...(props.unassigned.length ? ['none'] : [])]);
const allOpen = computed(() => allKeys.value.length > 0 && allKeys.value.every((k) => opened.value[k]));
const toggleAll = () => (opened.value = Object.fromEntries(allKeys.value.map((k) => [k, !allOpen.value])));

// Renaming changes the name here and in the PSED directory, so the next sync keeps it.
const renaming = ref(null);
const renameForm = useForm({ name: '' });
const openRename = (agency) => {
    renaming.value = agency;
    renameForm.reset();
    renameForm.clearErrors();
    renameForm.name = agency.name;
};
const closeRename = () => (renaming.value = null);
const saveRename = () =>
    renameForm.put(`/settings/agencies/${renaming.value.id}`, { preserveScroll: true, onSuccess: closeRename });

const when = (iso) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

const changes = computed(() => (result.value ? result.value.added + result.value.moved + result.value.removed : 0));

const groups = computed(() =>
    result.value
        ? [
              { key: 'added_list', label: 'Added', count: result.value.added, data: result.value.added_list },
              { key: 'moved_list', label: 'Moved to another Field Office', count: result.value.moved, data: result.value.moved_list },
              { key: 'removed_list', label: 'Removed', count: result.value.removed, data: result.value.removed_list },
          ].filter((g) => g.count > 0)
        : [],
);
</script>

<template>
    <Head title="Agencies" />

    <StaffLayout>
        <PageHeader
            title="Agencies"
            description="The agencies suggested on the appointment form, each within one Field Office's jurisdiction. They are kept in PSED and brought in here."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Agencies' }]"
        >
            <template #actions>
                <Button variant="outline" :loading="working === 'check'" :disabled="working !== null" @click="run(true)">
                    <Icon name="search" size="sm" />Check for changes
                </Button>
                <Button variant="success" :loading="working === 'sync'" :disabled="working !== null" @click="run(false)">
                    <Icon name="refresh" size="sm" />Sync now
                </Button>
            </template>
        </PageHeader>

        <Card class="mb-4" title="Where the list comes from" icon="info">
            <p class="text-sm text-text-muted">
                Add or remove an agency in the PSED Directory of Government Agencies<template v-if="directory_url">
                    (<a :href="directory_url" class="font-medium underline" target="_blank" rel="noopener">open it</a>)</template>, then sync here. An agency's Field Office decides whose form suggests it.
                Renaming an agency (the pencil beside it) renames it in the PSED directory as well, and on the appointments already saved under it. Other changes to an agency are made in PSED.
            </p>
            <p class="mt-2 text-sm">
                <template v-if="last_sync">
                    Last synced <strong>{{ when(last_sync.at) }}</strong><template v-if="last_sync.by"> by {{ last_sync.by }}</template><template v-if="last_sync.summary">:
                        {{ last_sync.summary.added }} added, {{ last_sync.summary.moved }} moved, {{ last_sync.summary.removed }} removed.</template>
                </template>
                <span v-else class="text-text-muted">Not synced from this page yet.</span>
            </p>
        </Card>

        <Alert v-if="without_office > 0" tone="warning" class="mb-4" title="Agencies with no Field Office">
            {{ without_office }} agenc{{ without_office === 1 ? 'y has' : 'ies have' }} a Field Office that matches none here, so no form suggests {{ without_office === 1 ? 'it' : 'them' }}. Correct it in the PSED directory and sync again.
        </Alert>

        <!-- What the last check or sync found. -->
        <Card v-if="result" class="mb-4" :title="result.dry_run ? 'Check result: nothing was changed' : 'Synced'" :icon="result.dry_run ? 'search' : 'checkCircle'">
            <p class="text-sm">
                {{ result.total }} agencies in the PSED directory:
                <strong>{{ result.added }}</strong> to add, <strong>{{ result.moved }}</strong> to move, <strong>{{ result.removed }}</strong> to remove,
                {{ result.unchanged }} unchanged.
                <template v-if="result.dry_run && changes > 0"> Choose “Sync now” to apply these.</template>
                <template v-if="result.dry_run && changes === 0"> The list is up to date.</template>
            </p>

            <div v-if="groups.length" class="mt-3 grid gap-3 sm:grid-cols-3">
                <div v-for="g in groups" :key="g.key" class="rounded-md bg-surface-muted p-3 text-sm">
                    <p class="font-semibold text-text">{{ g.label }} ({{ g.count }})</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4 text-text-muted">
                        <li v-for="n in g.data.names" :key="n">{{ n }}</li>
                    </ul>
                    <p v-if="g.data.more" class="mt-1 text-xs text-text-muted">and {{ g.data.more }} more</p>
                </div>
            </div>

            <Alert v-if="result.orphaned_total > 0" tone="warning" class="mt-3" title="Saved appointments name an agency that is not in the list">
                These appointments were encoded under a name the directory no longer has (renamed or removed). They are left as they are; rename them or correct the directory.
                <ul class="mt-1 list-disc pl-4">
                    <li v-for="o in result.orphaned" :key="o.agency">{{ o.agency }} ({{ o.total }})</li>
                </ul>
                <p v-if="result.orphaned_total > result.orphaned.length" class="mt-1 text-xs">and {{ result.orphaned_total - result.orphaned.length }} more</p>
            </Alert>
        </Card>

        <!-- The agencies of each Field Office's jurisdiction. -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="w-full sm:max-w-sm">
                <SearchBox v-model="search" placeholder="Search agencies in every jurisdiction" label="Search agencies" />
            </div>
            <div class="flex items-center gap-3">
                <p class="text-sm text-text-muted">{{ total }} agencies in {{ offices.length }} Field Offices</p>
                <Button variant="outline" size="sm" :disabled="searching" :aria-pressed="allOpen" @click="toggleAll">
                    {{ allOpen ? 'Collapse all' : 'Expand all' }}
                </Button>
            </div>
        </div>

        <div class="space-y-3">
            <details
                v-for="o in visibleOffices"
                :key="o.id"
                class="rounded-lg border border-border bg-surface"
                :open="isOpen(o.id)"
                @toggle="onToggle(o.id, $event)"
            >
                <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 font-medium text-text">
                    <span>{{ o.name }}</span>
                    <span class="text-sm font-normal text-text-muted tabular-nums">
                        {{ searching ? `${o.shown.length} of ${o.agencies}` : o.agencies }} agenc{{ o.agencies === 1 ? 'y' : 'ies' }}
                    </span>
                </summary>
                <ul class="grid gap-x-6 gap-y-1 border-t border-border px-4 py-3 text-sm sm:grid-cols-2">
                    <li v-for="a in o.shown" :key="a.id" class="flex items-center justify-between gap-3 py-0.5">
                        <span>{{ a.name }}</span>
                        <span class="flex shrink-0 items-center gap-2">
                            <span v-if="a.sector" class="text-xs text-text-muted">{{ a.sector }}</span>
                            <button type="button" class="rounded p-1 text-text-muted hover:bg-surface-muted hover:text-text" :aria-label="`Rename ${a.name}`" @click="openRename(a)">
                                <Icon name="pencil" size="sm" />
                            </button>
                        </span>
                    </li>
                    <li v-if="!o.shown.length" class="text-text-muted">No agencies.</li>
                </ul>
            </details>

            <p v-if="searching && !visibleOffices.length" class="rounded-lg border border-border p-4 text-sm text-text-muted">No agency matches “{{ search }}”.</p>

            <details v-if="unassigned.length && (!searching || unassignedShown.length)" class="rounded-lg border border-warning/40 bg-surface" :open="isOpen('none')" @toggle="onToggle('none', $event)">
                <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 font-medium text-text">
                    <span>No Field Office</span>
                    <span class="text-sm font-normal text-text-muted tabular-nums">{{ unassigned.length }}</span>
                </summary>
                <ul class="grid gap-x-6 gap-y-1 border-t border-border px-4 py-3 text-sm sm:grid-cols-2">
                    <li v-for="n in unassignedShown" :key="n" class="py-0.5">{{ n }}</li>
                </ul>
            </details>
        </div>
        <Modal :open="renaming !== null" title="Rename agency" @close="closeRename">
            <form v-if="renaming" class="space-y-4" @submit.prevent="saveRename">
                <div>
                    <TextInput id="agency-name" v-model="renameForm.name" label="Name of agency" required :invalid="!!renameForm.errors.name" />
                    <InputError :message="renameForm.errors.name" />
                </div>
                <Alert tone="info">
                    The name is changed here <strong>and in the PSED directory</strong>, so the next sync keeps it.
                    <template v-if="renaming.appointments">{{ renaming.appointments }} saved appointment{{ renaming.appointments === 1 ? '' : 's' }} will carry the new name.</template>
                    Documents in PSED that name this agency (such as IGHR reports) keep the old name there.
                </Alert>
                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="secondary" @click="closeRename">Cancel</Button>
                    <Button type="submit" variant="success" :loading="renameForm.processing">Rename</Button>
                </div>
            </form>
        </Modal>
    </StaffLayout>
</template>
