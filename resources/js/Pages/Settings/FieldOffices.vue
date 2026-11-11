<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import InputError from '@/Components/Forms/InputError.vue';

/**
 * Field Office settings. The people come from IMIS: "Prepared by" is chosen
 * from the office's staff as IMIS lists them, and "Approved / noted by" is the
 * office's head in IMIS (the Director II, or the OIC / Caretaker while one is
 * designated), shown here and never typed.
 */
const props = defineProps({
    offices: { type: Array, required: true },
    headTypes: { type: Array, required: true },
    directory: { type: Object, required: true },
});

const editing = ref(null);
const form = useForm({
    head_type: 'none',
    location: '',
    prepared_by_imis_id: '',
});

const open = (office) => {
    editing.value = office;
    form.reset();
    form.clearErrors();
    form.head_type = office.head_type;
    form.location = office.location ?? '';
    form.prepared_by_imis_id = office.prepared_by_imis_id ? String(office.prepared_by_imis_id) : '';
};

const close = () => (editing.value = null);

const save = () =>
    form.put(`/settings/field-offices/${editing.value.id}`, { preserveScroll: true, onSuccess: close });

const headTypeLabel = (value) => props.headTypes.find((h) => h.value === value)?.label;

// The office's own staff, as IMIS lists them.
const staff = computed(() =>
    editing.value
        ? props.directory.staff
              .filter((s) => s.office_code === editing.value.imis_code)
              .map((s) => ({ value: String(s.id), label: `${s.name} · ${s.position ?? ''}`.replace(/ · $/, '') }))
        : [],
);
const preparer = computed(() => props.directory.staff.find((s) => String(s.id) === String(form.prepared_by_imis_id)) ?? null);

// Does the routing rule agree with who IMIS says heads the office?
const disagrees = (office) => office.suggested_head_type !== null && office.suggested_head_type !== office.head_type;
const formDisagrees = computed(() => editing.value?.suggested_head_type && editing.value.suggested_head_type !== form.head_type);
const headLine = (head) => (head ? `${head.name} · ${head.title}` : null);
// "Director II", or "Director II, concurrent" when they head this office as well as another one.
const headDescription = (head) => (head.capacity_label ? `${head.title}, ${head.capacity_label.toLowerCase()}` : head.title);
</script>

<template>
    <Head title="Field Offices" />

    <StaffLayout>
        <PageHeader
            title="Field Offices"
            description="Who heads each office decides which appointments PSED processes. The people who sign the reports come from IMIS."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Field Offices' }]"
        />

        <Alert v-if="!directory.available" tone="warning" class="mb-4" title="IMIS cannot be reached right now">
            {{ directory.message }} The signatories saved earlier are shown and used until it is back, and they cannot be changed meanwhile.
        </Alert>

        <Card class="mb-4" title="How appointments are routed" icon="info">
            <ul class="grid gap-3 text-sm sm:grid-cols-3">
                <li v-for="h in headTypes" :key="h.value" class="rounded-md bg-surface-muted p-3">
                    <p class="font-semibold text-text">{{ h.label }}</p>
                    <p class="mt-1 text-text-muted">SG {{ h.psed_from_sg }} and above: PSED. Below SG {{ h.psed_from_sg }}: the Field Office.</p>
                </li>
            </ul>
        </Card>

        <Table>
            <thead>
                <tr>
                    <th scope="col">Office</th>
                    <th scope="col">Routing rule</th>
                    <th scope="col">Head in IMIS</th>
                    <th scope="col">Prepared by</th>
                    <th scope="col">Approved / noted by</th>
                    <th scope="col" class="text-right">Appointments</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="o in offices" :key="o.id">
                    <td>
                        <span class="font-medium">{{ o.name }}</span>
                    </td>
                    <td>
                        {{ headTypeLabel(o.head_type) }}
                        <div class="text-xs text-text-muted">PSED from SG {{ o.psed_from_sg }}</div>
                    </td>
                    <td>
                        <template v-if="o.head">
                            {{ o.head.name }}
                            <div class="text-xs text-text-muted">{{ o.head.title }}<span v-if="o.head.capacity_label"> · {{ o.head.capacity_label }}</span></div>
                            <Badge v-if="disagrees(o)" tone="warning" class="mt-1">Rule differs from IMIS</Badge>
                        </template>
                        <span v-else class="text-text-muted">{{ directory.available ? 'No head in IMIS' : '—' }}</span>
                    </td>
                    <td>{{ o.prepared_by_name ?? '—' }}<div class="text-xs text-text-muted">{{ o.prepared_by_title }}</div></td>
                    <td>{{ o.approved_by_name ?? '—' }}<div class="text-xs text-text-muted">{{ o.approved_by_title }}</div></td>
                    <td class="text-right tabular-nums">{{ o.appointments_count }}</td>
                    <td class="text-right">
                        <Button variant="outline" size="sm" @click="open(o)"><Icon name="pencil" size="sm" />Edit</Button>
                    </td>
                </tr>
            </tbody>
        </Table>

        <Modal :open="editing !== null" :title="editing ? `Edit ${editing.name}` : ''" size="lg" @close="close">
            <form v-if="editing" class="space-y-5" @submit.prevent="save">
                <section class="space-y-3">
                    <FormGroup id="head_type" label="Routing rule: the office is headed by" required :error="form.errors.head_type">
                        <Combobox :allow-custom="false" id="head_type" v-model="form.head_type" :options="headTypes.map((h) => ({ value: h.value, label: h.label }))" required />
                    </FormGroup>

                    <Alert v-if="formDisagrees" tone="warning">
                        IMIS shows this office headed by <strong>{{ editing.head.name }}</strong> ({{ headDescription(editing.head) }}), which goes with the rule
                        “{{ headTypeLabel(editing.suggested_head_type) }}”.
                        <button type="button" class="font-medium underline" @click="form.head_type = editing.suggested_head_type">Use what IMIS shows</button>
                    </Alert>
                    <Alert v-if="form.head_type !== editing.head_type" tone="info">
                        Saving re-routes this office's {{ editing.appointments_count }} existing appointment(s) under the new rule.
                    </Alert>

                    <div>
                        <TextInput id="location" v-model="form.location" label="Location (printed on the MRAP)" />
                        <InputError :message="form.errors.location" />
                    </div>
                </section>

                <section class="space-y-3 rounded-lg border border-border p-4">
                    <h3 class="text-sm font-semibold text-text">Who signs the reports</h3>

                    <FormGroup id="prepared_by_imis_id" label="Prepared by: name" helper-text="A staff member of this office, as IMIS lists them." :error="form.errors.prepared_by_imis_id">
                        <Combobox :allow-custom="false" id="prepared_by_imis_id" v-model="form.prepared_by_imis_id" :options="staff" placeholder="No one chosen" :disabled="!directory.available" />
                    </FormGroup>
                    <p v-if="!form.prepared_by_imis_id && editing.prepared_by_name" class="rounded-md bg-warning-bg px-3 py-2 text-xs text-warning">
                        Printed now: <strong>{{ editing.prepared_by_name }}</strong><span v-if="editing.prepared_by_title"> ({{ editing.prepared_by_title }})</span>,
                        saved earlier and not linked to IMIS. Choose the person above to link it.
                    </p>
                    <p class="text-sm">
                        <span class="text-text-muted">Prepared by: position</span>
                        <span class="ml-2 font-medium">{{ preparer?.position ?? (form.prepared_by_imis_id === '' ? '—' : editing.prepared_by_title ?? '—') }}</span>
                    </p>

                    <div class="rounded-md bg-surface-muted p-3 text-sm">
                        <p class="font-medium text-text">Approved / noted by</p>
                        <template v-if="editing.head">
                            <p class="mt-1"><span class="text-text-muted">Name</span> <span class="ml-2 font-medium">{{ editing.head.name }}</span></p>
                            <p><span class="text-text-muted">Position</span> <span class="ml-2 font-medium">{{ editing.head.title }}</span></p>
                        </template>
                        <p v-else class="mt-1 text-text-muted">
                            {{ directory.available ? 'IMIS shows no head for this office yet.' : 'Saved earlier: ' + (headLine({ name: editing.approved_by_name, title: editing.approved_by_title }) ?? 'none') }}
                        </p>
                        <p class="mt-2 text-xs text-text-muted">
                            This is the office's head in IMIS: the Director II, or the Officer-in-Charge or Caretaker while one is designated. It follows IMIS, so it is not typed here.
                        </p>
                    </div>
                </section>

                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="secondary" @click="close">Cancel</Button>
                    <Button type="submit" variant="success" :loading="form.processing">Save</Button>
                </div>
            </form>
        </Modal>
    </StaffLayout>
</template>
