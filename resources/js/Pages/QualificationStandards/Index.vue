<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import SearchBox from '@/Components/Forms/SearchBox.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import Textarea from '@/Components/Forms/Textarea.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import Checkbox from '@/Components/Forms/Checkbox.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import InputError from '@/Components/Forms/InputError.vue';
import { useListFilters } from '@/Composables/useListFilters';
import { confirm } from '@/Composables/useDialog';

/**
 * The reference page: the issuances, memorandums and policies that guide the
 * processing of appointments. Everyone reads and opens them; the add, edit and
 * delete controls only appear for PSED (`canManage`), and the server refuses
 * the requests from anyone else.
 */
const props = defineProps({
    documents: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    years: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const filters = useListFilters('/qualification-standards', { ...props.filters });
const typeOptions = computed(() => props.types);
const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));
const hasFilters = computed(() => ['search', 'type', 'year'].some((key) => filters[key] !== '' && filters[key] != null));
const reset = () => {
    filters.search = '';
    filters.type = '';
    filters.year = '';
};

const formatDate = (value) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) : '';

// --- View dialog ---------------------------------------------------------
const viewing = ref(null);

// --- Add / edit dialog ---------------------------------------------------
const editing = ref(null); // the document being edited, or null when adding
const formOpen = ref(false);
const formKey = ref(0); // re-creates the file input so a chosen file is cleared
const form = useForm({
    title: '',
    type: 'qualification_standard',
    reference_no: '',
    issued_on: '',
    summary: '',
    url: '',
    file: null,
    remove_file: false,
});

const openForm = (document = null) => {
    editing.value = document;
    form.reset();
    form.clearErrors();
    if (document) {
        form.title = document.title;
        form.type = document.type;
        form.reference_no = document.reference_no ?? '';
        form.issued_on = document.issued_on ?? '';
        form.summary = document.summary ?? '';
        form.url = document.url ?? '';
    }
    formKey.value++;
    formOpen.value = true;
};

const closeForm = () => (formOpen.value = false);

const save = () => {
    const done = { forceFormData: true, preserveScroll: true, onSuccess: closeForm };

    if (editing.value) {
        // A file upload needs a multipart POST, so the update is sent as one with a method override.
        form.transform((data) => ({ ...data, _method: 'put' })).post(`/qualification-standards/${editing.value.id}`, done);
    } else {
        form.transform((data) => data).post('/qualification-standards', done);
    }
};

const remove = async (document) => {
    const ok = await confirm({
        title: 'Remove this document?',
        text: `"${document.title}" and its file will be removed for everyone.`,
        confirmText: 'Remove',
        tone: 'danger',
    });

    if (ok) router.delete(`/qualification-standards/${document.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Qualification Standards" />

    <StaffLayout>
        <PageHeader
            title="Qualification Standards"
            description="The qualification standards, issuances, memorandums and policies that guide the processing of appointments."
        >
            <template v-if="canManage" #actions>
                <Button variant="success" @click="openForm()"><Icon name="plus" size="sm" />Add document</Button>
            </template>
        </PageHeader>

        <Card class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <SearchBox v-model="filters.search" placeholder="Search title, reference number or summary" label="Search qualification standards" />
                <div class="w-48">
                    <label for="f-type" class="block text-sm font-medium text-text">Type</label>
                    <Combobox :allow-custom="false" id="f-type" v-model="filters.type" :options="typeOptions" placeholder="All types" class="mt-1" />
                </div>
                <div class="w-36">
                    <label for="f-year" class="block text-sm font-medium text-text">Year issued</label>
                    <Combobox :allow-custom="false" id="f-year" v-model="filters.year" :options="yearOptions" placeholder="Any year" class="mt-1" />
                </div>
                <Button v-if="hasFilters" variant="ghost" size="sm" @click="reset"><Icon name="close" size="sm" />Clear filters</Button>
            </div>
        </Card>

        <p class="mb-2 text-sm text-text-muted" aria-live="polite">{{ documents.total }} document{{ documents.total === 1 ? '' : 's' }}</p>

        <div v-if="!documents.data.length" class="rounded-lg border border-border bg-surface">
            <EmptyState
                icon="archive"
                :title="hasFilters ? 'No documents match these filters' : 'No documents yet'"
                :description="hasFilters ? 'Try a different word or clear a filter.' : canManage ? 'Add the first qualification standard, issuance, memorandum or policy that guides appointment processing.' : 'PSED has not added any documents yet.'"
            />
        </div>

        <ul v-else class="space-y-3">
            <li v-for="d in documents.data" :key="d.id" class="rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/40">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :tone="d.type_tone">{{ d.type_label }}</Badge>
                            <span v-if="d.reference_no" class="text-xs font-medium text-text-muted">{{ d.reference_no }}</span>
                            <span v-if="d.issued_on" class="text-xs text-text-muted">· {{ formatDate(d.issued_on) }}</span>
                        </div>
                        <h2 class="mt-1.5 text-base font-semibold">
                            <button type="button" class="text-left text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="viewing = d">{{ d.title }}</button>
                        </h2>
                        <p v-if="d.summary" class="mt-1 line-clamp-2 text-sm text-text-muted">{{ d.summary }}</p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-1">
                        <Button v-if="d.has_file" :href="`/qualification-standards/${d.id}/file`" external variant="outline" size="sm">
                            <Icon :name="d.is_pdf ? 'eye' : 'download'" size="sm" />{{ d.is_pdf ? 'Open' : 'Download' }}
                        </Button>
                        <Button v-if="d.url" :href="d.url" external variant="outline" size="sm"><Icon name="externalLink" size="sm" />Link</Button>
                        <Button variant="ghost" size="sm" icon-only aria-label="View details" @click="viewing = d"><Icon name="info" size="sm" /></Button>
                        <template v-if="canManage">
                            <Button variant="ghost" size="sm" icon-only aria-label="Edit document" @click="openForm(d)"><Icon name="pencil" size="sm" /></Button>
                            <Button variant="ghost" size="sm" icon-only aria-label="Remove document" @click="remove(d)"><Icon name="trash" size="sm" /></Button>
                        </template>
                    </div>
                </div>
            </li>
        </ul>

        <Pagination
            :prev-url="documents.prev_page_url"
            :next-url="documents.next_page_url"
            :current-page="documents.current_page"
            :last-page="documents.last_page"
        />

        <!-- View -->
        <Modal :open="viewing !== null" :title="viewing?.title ?? ''" icon="archive" size="lg" @close="viewing = null">
            <div v-if="viewing" class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :tone="viewing.type_tone">{{ viewing.type_label }}</Badge>
                    <span v-if="viewing.reference_no" class="text-sm font-medium text-text">{{ viewing.reference_no }}</span>
                    <span v-if="viewing.issued_on" class="text-sm text-text-muted">· Issued {{ formatDate(viewing.issued_on) }}</span>
                </div>

                <p v-if="viewing.summary" class="text-sm leading-relaxed whitespace-pre-line text-text">{{ viewing.summary }}</p>
                <p v-else class="text-sm text-text-muted">No summary was written for this document.</p>

                <dl v-if="viewing.has_file || viewing.url" class="grid gap-3 rounded-md bg-surface-muted p-3 text-sm sm:grid-cols-2">
                    <div v-if="viewing.has_file">
                        <dt class="text-xs text-text-muted">File</dt>
                        <dd class="font-medium break-words">{{ viewing.file_name }}<span v-if="viewing.file_size" class="font-normal text-text-muted"> ({{ viewing.file_size }})</span></dd>
                    </div>
                    <div v-if="viewing.url">
                        <dt class="text-xs text-text-muted">Link</dt>
                        <dd class="font-medium break-all">{{ viewing.url }}</dd>
                    </div>
                </dl>
            </div>
            <template #footer>
                <Button variant="secondary" @click="viewing = null">Close</Button>
                <Button v-if="viewing?.url" :href="viewing.url" external variant="outline"><Icon name="externalLink" size="sm" />Open link</Button>
                <Button v-if="viewing?.has_file" :href="`/qualification-standards/${viewing.id}/file`" external variant="primary">
                    <Icon :name="viewing.is_pdf ? 'eye' : 'download'" size="sm" />{{ viewing.is_pdf ? 'Open document' : 'Download' }}
                </Button>
            </template>
        </Modal>

        <!-- Add / edit (PSED only) -->
        <Modal v-if="canManage" :open="formOpen" :title="editing ? 'Edit document' : 'Add document'" :tone="editing ? 'primary' : 'success'" size="lg" @close="closeForm">
            <form id="reference-form" class="space-y-4" novalidate @submit.prevent="save">
                <div>
                    <TextInput id="ref-title" v-model="form.title" label="Title" required :invalid="!!form.errors.title" />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <FormGroup id="ref-type" label="Type" required :error="form.errors.type">
                        <Combobox :allow-custom="false" id="ref-type" v-model="form.type" :options="types" required />
                    </FormGroup>
                    <div>
                        <TextInput id="ref-no" v-model="form.reference_no" label="Reference number" :invalid="!!form.errors.reference_no" />
                        <InputError :message="form.errors.reference_no" />
                    </div>
                    <div>
                        <TextInput id="ref-date" v-model="form.issued_on" type="date" label="Date issued" :invalid="!!form.errors.issued_on" />
                        <InputError :message="form.errors.issued_on" />
                    </div>
                </div>

                <FormGroup id="ref-summary" label="Summary" helper-text="What it covers and when it applies to processing an appointment." :error="form.errors.summary">
                    <Textarea id="ref-summary" v-model="form.summary" :rows="4" />
                </FormGroup>

                <FormGroup
                    id="ref-file"
                    label="Document"
                    :helper-text="editing?.has_file ? 'Choose a file to replace the current one.' : 'PDF, Word, Excel or PowerPoint, up to 20 MB.'"
                    :error="form.errors.file"
                >
                    <FileInput :id="'ref-file'" :key="formKey" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" :max-size-mb="20" :invalid="!!form.errors.file" @update:model-value="form.file = $event" />
                </FormGroup>

                <Alert v-if="editing?.has_file && !form.file" tone="info">
                    Current file: <strong>{{ editing.file_name }}</strong>
                    <span v-if="editing.file_size"> ({{ editing.file_size }})</span>
                    <Checkbox id="ref-remove" v-model="form.remove_file" label="Remove it" class="mt-2" />
                </Alert>

                <div>
                    <TextInput id="ref-url" v-model="form.url" type="url" label="Link (optional)" :invalid="!!form.errors.url" />
                    <InputError :message="form.errors.url" />
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" @click="closeForm">Cancel</Button>
                <Button type="submit" form="reference-form" variant="success" :loading="form.processing">{{ editing ? 'Save changes' : 'Add document' }}</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
