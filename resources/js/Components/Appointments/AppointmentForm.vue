<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import Textarea from '@/Components/Forms/Textarea.vue';
import Checkbox from '@/Components/Forms/Checkbox.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import InputError from '@/Components/Forms/InputError.vue';
import { request } from '@/Utils/http';

/**
 * Encode / edit one appointment. The "Routing" banner applies the Appointments
 * Processing Flow Chart (step 4) live: who processes it depends on the Field
 * Office's head (Director II, Caretaker/OIC SG 22, or the default) and the SG.
 * The CSC action fields are only open to whoever the flow chart routes the
 * appointment to; the server enforces this too.
 */
const props = defineProps({
    appointment: { type: Object, default: null },
    defaults: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
    offices: { type: Array, required: true },
    canRecordAction: { type: Boolean, default: true },
    /** CSC RO VIII employees from IMIS, grouped by office, to choose the action officer from. */
    actionOfficers: { type: Object, default: () => ({ groups: [], error: null }) },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const editing = computed(() => props.appointment !== null);

const v = (value) => value ?? '';

const initial = props.appointment ?? {};

// An officer saved before the list came from IMIS (typed, or no longer there) can be kept as it is.
const initialOfficer = initial.action_officer_imis_id ? String(initial.action_officer_imis_id) : initial.action_officer ? 'keep' : '';

// Who received it is chosen the same way. A new appointment starts with the person encoding it, if IMIS lists them.
const isListed = (id) => props.actionOfficers.groups.some((g) => g.options.some((o) => o.value === String(id)));
const initialReceiver = initial.received_by_imis_id
    ? String(initial.received_by_imis_id)
    : initial.received_by
      ? 'keep'
      : !props.appointment && props.defaults.received_by_imis_id && isListed(props.defaults.received_by_imis_id)
        ? String(props.defaults.received_by_imis_id)
        : '';

const form = useForm({
    field_office_id: v(initial.field_office_id ?? props.defaults.field_office_id),
    agency: v(initial.agency),
    sector: v(initial.sector),
    date_received: v(initial.date_received ?? props.defaults.date_received),
    time_received: v(initial.time_received),
    received_by_imis_id: initialReceiver,
    date_issued: v(initial.date_issued),
    date_effective: v(initial.date_effective),
    last_name: v(initial.last_name),
    first_name: v(initial.first_name),
    name_extension: v(initial.name_extension),
    middle_name: v(initial.middle_name),
    sex: v(initial.sex),
    position: v(initial.position),
    salary_grade: v(initial.salary_grade),
    level: v(initial.level),
    employment_status: v(initial.employment_status),
    nature_of_appointment: v(initial.nature_of_appointment),
    publication_start: v(initial.publication_start),
    publication_end: v(initial.publication_end),
    publication_mode: v(initial.publication_mode),
    eligibility_type: v(initial.eligibility_type),
    skoe_position: v(initial.skoe_position),
    is_first_time_entrant: Boolean(initial.is_first_time_entrant),
    eligibility_exam_date: v(initial.eligibility_exam_date),
    eligibility_exam_place: v(initial.eligibility_exam_place),
    eligibility_issued_date: v(initial.eligibility_issued_date),
    eligibility_issued_place: v(initial.eligibility_issued_place),
    eligibility_validity: v(initial.eligibility_validity),
    is_pwd: Boolean(initial.is_pwd),
    is_indigenous: Boolean(initial.is_indigenous),
    is_solo_parent: Boolean(initial.is_solo_parent),
    is_k12_graduate: Boolean(initial.is_k12_graduate),
    csc_action: v(initial.csc_action),
    action_officer_imis_id: initialOfficer,
    action_date: v(initial.action_date),
    release_date: v(initial.release_date),
    pending_reason: v(initial.pending_reason),
    disapproval_reason: v(initial.disapproval_reason),
});

const opt = (list) => list.map((value) => ({ value, label: value }));
const gradeOptions = Array.from({ length: 33 }, (_, i) => ({ value: String(i + 1), label: `SG ${i + 1}` }));
// Only the agencies within the chosen Field Office's jurisdiction are suggested.
const agencySuggestions = computed(() => props.options.agency_by_office?.[form.field_office_id] ?? props.options.agency);
const officeOptions = computed(() => props.offices.map((o) => ({ value: String(o.id), label: o.name })));
const sexOptions = [{ value: 'M', label: 'Male' }, { value: 'F', label: 'Female' }];

// --- Action officer: an IMIS employee ----------------------------------------
const officerGroups = computed(() => {
    // Who may act depends on who processes the appointment: the regional office's Director IV and III for what
    // PSED processes, the Field Office's own people for the rest. Until the office and salary grade say which,
    // both are offered: the regional office first, then the Field Offices.
    const groups = [
        ...(toPsed.value !== false ? props.actionOfficers.directors : []),
        ...(toPsed.value !== true ? props.actionOfficers.groups : []),
    ];
    const listed = groups.some((g) => g.options.some((o) => o.value === String(initial.action_officer_imis_id)));

    if (initial.action_officer_imis_id && !listed) {
        groups.unshift({ label: 'Saved earlier', options: [{ value: String(initial.action_officer_imis_id), label: `${initial.action_officer} (not in this list)` }] });
    } else if (!initial.action_officer_imis_id && initial.action_officer) {
        groups.unshift({ label: 'Saved earlier', options: [{ value: 'keep', label: `${initial.action_officer} (not linked to IMIS)` }] });
    }

    return groups;
});
// "Received by": the same people as the action officer, except the Office of the Regional Director.
const receiverGroups = computed(() => {
    const groups = [...props.actionOfficers.groups];

    if (initial.received_by_imis_id && !isListed(initial.received_by_imis_id)) {
        groups.unshift({ label: 'Saved earlier', options: [{ value: String(initial.received_by_imis_id), label: `${initial.received_by} (not in this list)` }] });
    } else if (!initial.received_by_imis_id && initial.received_by) {
        groups.unshift({ label: 'Saved earlier', options: [{ value: 'keep', label: `${initial.received_by} (not linked to IMIS)` }] });
    }

    return groups;
});

// --- Routing preview (flow chart, step 4) ------------------------------------
const office = computed(() => props.offices.find((o) => String(o.id) === String(form.field_office_id)) ?? null);
const grade = computed(() => Number(form.salary_grade) || null);
const toPsed = computed(() => office.value && grade.value ? grade.value >= office.value.psed_from_sg : null);

// A different processor means a different list of action officers, so a choice from the old one is cleared.
watch(toPsed, (now, before) => {
    if (before !== null && now !== null && now !== before && form.action_officer_imis_id !== 'keep') form.action_officer_imis_id = '';
});

// Who may record the action for the appointment as it is now being edited.
const actionOpen = computed(() => {
    if (user.value.sees_all_offices) return true;
    if (toPsed.value === null) return true;

    return !toPsed.value;
});

// --- Position search (about 2,000 titles) ------------------------------------
const positionSuggestions = ref([]);
const positionLoading = ref(false);
let positionTimer;
let positionRequest = 0;
watch(
    () => form.position,
    (term) => {
        clearTimeout(positionTimer);
        positionLoading.value = true;
        const mine = ++positionRequest; // any search already on its way is now out of date
        positionTimer = setTimeout(async () => {
            const result = await request('GET', `/lookups/positions?q=${encodeURIComponent(term ?? '')}`);
            if (mine !== positionRequest) return; // a newer search is on its way
            if (result.ok && Array.isArray(result.data)) positionSuggestions.value = result.data;
            positionLoading.value = false;
        }, 250);
    },
    { immediate: true },
);

// Eligibility details only make sense when there is an eligibility.
const hasEligibility = computed(() => form.eligibility_type !== '' && form.eligibility_type !== 'N/A');

// The Sangguniang Kabataan Official Eligibility asks which SK position it was granted for.
const isSkoe = computed(() => form.eligibility_type === props.options.skoe_eligibility);
watch(isSkoe, (now) => {
    if (!now) form.skoe_position = '';
});

const submit = () => {
    if (editing.value) {
        form.put(`/appointments/${props.appointment.id}`, { preserveScroll: true });
    } else {
        form.post('/appointments', { preserveScroll: true });
    }
};

// Fills "Time received" with the current time (24-hour HH:MM, as the time field expects).
const recordTimeReceived = () => {
    const now = new Date();
    form.time_received = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
};

const cancel = () => router.visit('/appointments');
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="submit">
        <Alert v-if="Object.keys(form.errors).length" tone="danger" title="Please check the form">
            {{ Object.keys(form.errors).length }} field(s) need attention.
        </Alert>

        <!-- Routing banner -->
        <div
            class="flex items-start gap-3 rounded-lg border p-4"
            :class="toPsed === null ? 'border-border bg-surface' : toPsed ? 'border-info-border bg-info-bg' : 'border-success-border bg-success-bg'"
            role="status"
        >
            <Icon :name="toPsed ? 'building' : 'clipboard'" class="mt-0.5 shrink-0 text-primary" />
            <div class="text-sm">
                <p class="font-semibold text-text">
                    <template v-if="toPsed === null">Routing: choose the office and the salary grade</template>
                    <template v-else-if="toPsed">This appointment is processed by PSED</template>
                    <template v-else>This appointment is processed by the Field Office</template>
                </p>
                <p class="mt-0.5 text-text-muted">
                    <template v-if="office">
                        {{ office.name }} ({{ office.head_label }}): appointments of SG {{ office.psed_from_sg }} and above go to PSED.
                    </template>
                    <template v-else>Where an appointment is processed depends on who heads the Field Office and on its salary grade.</template>
                </p>
            </div>
        </div>

        <Card title="Receipt" icon="download" description="From the documents submitted by the client agency.">
            <div class="grid gap-4 md:grid-cols-12">
                <div v-if="user.sees_all_offices" class="md:col-span-4">
                    <Combobox id="field_office_id" v-model="form.field_office_id" label="Field Office" required :options="officeOptions" :allow-custom="false" placeholder="Search for an office" :invalid="!!form.errors.field_office_id" />
                    <InputError :message="form.errors.field_office_id" />
                </div>
                <div class="md:col-span-8">
                    <Combobox id="agency" v-model="form.agency" label="Agency / Office" required :options="agencySuggestions" placeholder="Search or type an agency" :invalid="!!form.errors.agency" />
                    <InputError :message="form.errors.agency" />
                </div>
                <div class="md:col-span-4">
                    <Combobox id="sector" v-model="form.sector" label="Sector" :options="options.sector" :allow-custom="false" placeholder="Search for a sector" :invalid="!!form.errors.sector" />
                    <InputError :message="form.errors.sector" />
                </div>
                <div class="md:col-span-4">
                    <TextInput id="date_received" v-model="form.date_received" type="date" label="Date received" required :invalid="!!form.errors.date_received" />
                    <InputError :message="form.errors.date_received" />
                </div>
                <div class="md:col-span-4">
                    <div class="flex items-end gap-2">
                        <div class="w-36">
                            <TextInput id="time_received" v-model="form.time_received" type="time" label="Time received" :invalid="!!form.errors.time_received" />
                        </div>
                        <Button variant="outline" @click="recordTimeReceived">
                            <Icon name="clock" size="sm" />
                            Now
                        </Button>
                    </div>
                    <InputError :message="form.errors.time_received" />
                </div>
                <div class="md:col-span-3">
                    <TextInput id="date_issued" v-model="form.date_issued" type="date" label="Date of issuance" />
                    <InputError :message="form.errors.date_issued" />
                </div>
                <div class="md:col-span-3">
                    <TextInput id="date_effective" v-model="form.date_effective" type="date" label="Date of effectivity / assumption" />
                    <InputError :message="form.errors.date_effective" />
                </div>
                <div class="md:col-span-6">
                    <FormGroup id="received_by_imis_id" label="Received by" :helper-text="user.sees_all_offices ? 'An employee of any Field Office, as IMIS lists them.' : 'An employee of your office, as IMIS lists them.'" :error="form.errors.received_by_imis_id">
                        <Combobox :allow-custom="false" id="received_by_imis_id" v-model="form.received_by_imis_id" :options="receiverGroups" placeholder="Not recorded" :disabled="!!actionOfficers.error && !receiverGroups.length" />
                    </FormGroup>
                </div>
            </div>
        </Card>

        <Card title="Appointee" icon="user">
            <div class="grid gap-4 md:grid-cols-4">
                <div>
                    <TextInput id="last_name" v-model="form.last_name" label="Last name" required :invalid="!!form.errors.last_name" />
                    <InputError :message="form.errors.last_name" />
                </div>
                <div>
                    <TextInput id="first_name" v-model="form.first_name" label="First name" required :invalid="!!form.errors.first_name" />
                    <InputError :message="form.errors.first_name" />
                </div>
                <div>
                    <TextInput id="middle_name" v-model="form.middle_name" label="Middle name" />
                    <InputError :message="form.errors.middle_name" />
                </div>
                <div>
                    <TextInput id="name_extension" v-model="form.name_extension" label="Extension (Jr., III)" />
                    <InputError :message="form.errors.name_extension" />
                </div>
                <FormGroup id="sex" label="Sex" required :error="form.errors.sex">
                    <Combobox :allow-custom="false" id="sex" v-model="form.sex" :options="sexOptions" placeholder="Select" required />
                </FormGroup>
            </div>
            <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2">
                <Checkbox id="is_pwd" v-model="form.is_pwd" label="Person with disability" />
                <Checkbox id="is_indigenous" v-model="form.is_indigenous" label="Member of an indigenous group" />
                <Checkbox id="is_solo_parent" v-model="form.is_solo_parent" label="Solo parent" />
                <Checkbox id="is_k12_graduate" v-model="form.is_k12_graduate" label="K-12 graduate" />
            </div>
        </Card>

        <Card title="Position and appointment" icon="clipboard">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <Combobox id="position" v-model="form.position" label="Position title" required :options="positionSuggestions" :loading="positionLoading" placeholder="Search for a position title" :invalid="!!form.errors.position" />
                    <InputError :message="form.errors.position" />
                </div>
                <FormGroup id="salary_grade" label="Salary grade" required :error="form.errors.salary_grade">
                    <Combobox :allow-custom="false" id="salary_grade" v-model="form.salary_grade" :options="gradeOptions" placeholder="Select" required />
                </FormGroup>
                <FormGroup id="level" label="Level" :error="form.errors.level">
                    <Combobox :allow-custom="false" id="level" v-model="form.level" :options="opt(options.level)" placeholder="Select" />
                </FormGroup>
                <FormGroup id="employment_status" label="Employment status" required :error="form.errors.employment_status">
                    <Combobox :allow-custom="false" id="employment_status" v-model="form.employment_status" :options="opt(options.employment_status)" placeholder="Select" required />
                </FormGroup>
                <FormGroup id="nature_of_appointment" label="Nature of appointment" required :error="form.errors.nature_of_appointment">
                    <Combobox :allow-custom="false" id="nature_of_appointment" v-model="form.nature_of_appointment" :options="opt(options.nature_of_appointment)" placeholder="Select" required />
                </FormGroup>
            </div>
        </Card>

        <Card title="Publication" icon="megaphone" description="Of the vacant position (not required for Casual and Contractual).">
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <TextInput id="publication_start" v-model="form.publication_start" type="date" label="Published from" />
                    <InputError :message="form.errors.publication_start" />
                </div>
                <div>
                    <TextInput id="publication_end" v-model="form.publication_end" type="date" label="Published until" :invalid="!!form.errors.publication_end" />
                    <InputError :message="form.errors.publication_end" />
                </div>
                <FormGroup id="publication_mode" label="Mode of publication" :error="form.errors.publication_mode">
                    <Combobox :allow-custom="false" id="publication_mode" v-model="form.publication_mode" :options="opt(options.publication_mode)" placeholder="Select" />
                </FormGroup>
            </div>
        </Card>

        <Card title="Eligibility" icon="shield">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <Combobox id="eligibility_type" v-model="form.eligibility_type" label="Type of eligibility" :options="options.eligibility_type" placeholder="Search for a type of eligibility" :invalid="!!form.errors.eligibility_type" />
                    <InputError :message="form.errors.eligibility_type" />
                </div>
                <div class="flex items-end pb-2">
                    <Checkbox id="is_first_time_entrant" v-model="form.is_first_time_entrant" label="First-time entrant: eligibility used for the first time" />
                </div>
                <!-- The SKOE is granted for one of three SK positions; the Absorption Database counts them apart. -->
                <div v-if="isSkoe" class="md:col-span-2">
                    <FormGroup id="skoe_position" label="SK position" required helper-text="The position held when the Sangguniang Kabataan Official Eligibility was granted." :error="form.errors.skoe_position">
                        <Combobox :allow-custom="false" id="skoe_position" v-model="form.skoe_position" :options="opt(options.skoe_position)" placeholder="SK Official, SK Secretary or SK Treasurer" required />
                    </FormGroup>
                </div>
                <template v-if="hasEligibility">
                    <div>
                        <TextInput id="eligibility_exam_date" v-model="form.eligibility_exam_date" label="Date of exam (mm/dd/yyyy)" />
                        <InputError :message="form.errors.eligibility_exam_date" />
                    </div>
                    <div>
                        <TextInput id="eligibility_exam_place" v-model="form.eligibility_exam_place" label="Place of exam" />
                    </div>
                    <div>
                        <TextInput id="eligibility_issued_date" v-model="form.eligibility_issued_date" type="date" label="Date of issuance" />
                        <InputError :message="form.errors.eligibility_issued_date" />
                    </div>
                    <div>
                        <TextInput id="eligibility_issued_place" v-model="form.eligibility_issued_place" label="Place of issuance" />
                    </div>
                    <div>
                        <TextInput id="eligibility_validity" v-model="form.eligibility_validity" type="date" label="Validity of license" />
                        <InputError :message="form.errors.eligibility_validity" />
                    </div>
                </template>
            </div>
        </Card>

        <Card title="CSC action" icon="checkCircle" :description="actionOpen ? 'Recorded by the office that processes this appointment.' : null">
            <Alert v-if="!actionOpen" tone="info">
                This appointment is processed by PSED, so the CSC action is recorded there. You can still encode and correct its details.
            </Alert>
            <Alert v-if="actionOpen && actionOfficers.error" tone="warning" class="mb-4">{{ actionOfficers.error }} The action officer already saved is kept.</Alert>
            <fieldset v-if="actionOpen" :disabled="editing && !canRecordAction" class="grid gap-4 md:grid-cols-4">
                <FormGroup id="csc_action" label="Action" :error="form.errors.csc_action">
                    <Combobox :allow-custom="false" id="csc_action" v-model="form.csc_action" :options="opt(options.csc_action)" placeholder="Not yet acted" />
                </FormGroup>
                <FormGroup id="action_officer_imis_id" label="Action officer" :helper-text="toPsed === null ? null : toPsed ? 'The Director IV or Director III of the Office of the Regional Director, who act on what PSED processes.' : 'An employee from IMIS: any Field Office for PSED, your own office for a Field Office.'" :error="form.errors.action_officer_imis_id">
                    <Combobox :allow-custom="false" id="action_officer_imis_id" v-model="form.action_officer_imis_id" :options="officerGroups" placeholder="No action officer yet" :disabled="!!actionOfficers.error && !officerGroups.length" />
                </FormGroup>
                <div>
                    <TextInput id="action_date" v-model="form.action_date" type="date" label="Date of action" :invalid="!!form.errors.action_date" />
                    <InputError :message="form.errors.action_date" />
                </div>
                <div>
                    <TextInput id="release_date" v-model="form.release_date" type="date" label="Date of release" :invalid="!!form.errors.release_date" />
                    <InputError :message="form.errors.release_date" />
                </div>
                <div class="md:col-span-2">
                    <FormGroup id="pending_reason" label="If pending, the reason" :error="form.errors.pending_reason">
                        <Textarea id="pending_reason" v-model="form.pending_reason" :rows="2" />
                    </FormGroup>
                </div>
                <div class="md:col-span-2">
                    <FormGroup id="disapproval_reason" label="If disapproved or invalidated, the reason" :error="form.errors.disapproval_reason">
                        <Textarea id="disapproval_reason" v-model="form.disapproval_reason" :rows="2" />
                    </FormGroup>
                </div>
            </fieldset>
        </Card>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <Button variant="secondary" @click="cancel">Cancel</Button>
            <Button type="submit" variant="success" :loading="form.processing">{{ editing ? 'Save changes' : 'Encode appointment' }}</Button>
        </div>
    </form>
</template>
