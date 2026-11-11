<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Icon from '@/Components/UI/Icon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import SearchBox from '@/Components/Forms/SearchBox.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import Checkbox from '@/Components/Forms/Checkbox.vue';
import InputError from '@/Components/Forms/InputError.vue';
import { useListFilters } from '@/Composables/useListFilters';
import { confirm } from '@/Composables/useDialog';

/**
 * The position titles suggested on the appointment form. An appointment keeps the title it was
 * encoded with, so removing a title only takes it off the suggestions; a rename can carry the
 * appointments saved under it along.
 */
const props = defineProps({
    positions: { type: Object, required: true },
    filters: { type: Object, required: true },
    overall: { type: Number, required: true },
});

const filters = useListFilters('/settings/positions', { ...props.filters });
const rows = computed(() => props.positions.data);
const searching = computed(() => filters.search !== '' && filters.search != null);

// --- Add / rename ----------------------------------------------------------
const editing = ref(null); // the position being renamed, or null when adding
const open = ref(false);
const form = useForm({ name: '', update_appointments: true });

const openForm = (position = null) => {
    editing.value = position;
    form.reset();
    form.clearErrors();
    form.name = position?.name ?? '';
    open.value = true;
};
const close = () => (open.value = false);

const save = () => {
    const options = { preserveScroll: true, onSuccess: close };

    if (editing.value) form.put(`/settings/positions/${editing.value.id}`, options);
    else form.post('/settings/positions', options);
};

// --- Remove ----------------------------------------------------------------
const remove = async (position) => {
    const ok = await confirm({
        title: 'Remove this position?',
        text: position.appointments
            ? `"${position.name}" will no longer be suggested. The ${position.appointments} saved appointment${position.appointments === 1 ? '' : 's'} with this title keep it.`
            : `"${position.name}" will no longer be suggested.`,
        confirmText: 'Remove',
        tone: 'danger',
    });

    if (ok) router.delete(`/settings/positions/${position.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Positions" />

    <StaffLayout>
        <PageHeader
            title="Positions"
            description="The position titles suggested when an appointment is encoded. A title that is not on the list can still be typed."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Positions' }]"
        >
            <template #actions>
                <Button variant="success" @click="openForm()"><Icon name="plus" size="sm" />Add position</Button>
            </template>
        </PageHeader>

        <Card class="mb-4">
            <div class="max-w-md">
                <SearchBox v-model="filters.search" placeholder="Search position titles" label="Search positions" />
            </div>
        </Card>

        <p class="mb-2 text-sm text-text-muted" aria-live="polite">
            <template v-if="searching">{{ positions.total }} of {{ overall }} positions</template>
            <template v-else>{{ overall }} positions</template>
        </p>

        <Table :empty="!rows.length">
            <template #empty>
                <EmptyState
                    icon="clipboard"
                    :title="searching ? 'No position matches this search' : 'No positions yet'"
                    :description="searching ? 'Check the spelling, or add it as a new position.' : 'Add the first position title.'"
                />
            </template>

            <thead>
                <tr>
                    <th scope="col">Position title</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in rows" :key="p.id">
                    <td>{{ p.name }}</td>
                    <td class="whitespace-nowrap text-right">
                        <Button variant="ghost" size="sm" icon-only :aria-label="`Rename ${p.name}`" @click="openForm(p)"><Icon name="pencil" size="sm" /></Button>
                        <Button variant="ghost" size="sm" icon-only :aria-label="`Remove ${p.name}`" @click="remove(p)"><Icon name="trash" size="sm" /></Button>
                    </td>
                </tr>
            </tbody>
        </Table>

        <Pagination
            :prev-url="positions.prev_page_url"
            :next-url="positions.next_page_url"
            :current-page="positions.current_page"
            :last-page="positions.last_page"
        />

        <Modal :open="open" :title="editing ? 'Rename position' : 'Add position'" @close="close">
            <form class="space-y-4" @submit.prevent="save">
                <div>
                    <TextInput id="position-name" v-model="form.name" label="Position title" required :invalid="!!form.errors.name" />
                    <InputError :message="form.errors.name" />
                </div>
                <template v-if="editing && editing.appointments > 0">
                    <Checkbox
                        id="update_appointments"
                        v-model="form.update_appointments"
                        :label="`Also rename the ${editing.appointments} saved appointment${editing.appointments === 1 ? '' : 's'} with this title`"
                    />
                </template>
                <Alert v-else-if="editing" tone="info">No saved appointment has this title, so only the list changes.</Alert>
                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="secondary" @click="close">Cancel</Button>
                    <Button type="submit" variant="success" :loading="form.processing">{{ editing ? 'Rename' : 'Add' }}</Button>
                </div>
            </form>
        </Modal>
    </StaffLayout>
</template>
