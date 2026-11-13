<script setup>
import { computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import InputError from '@/Components/Forms/InputError.vue';

/**
 * The balance each Field Office carries into a year: its appointments still unacted on 31 December of the
 * year before. PSED, as the consolidator of the reports, sets it; it feeds the MRAP's Beginning Balance and
 * section G. Left blank, the reports use what the system's own appointments show.
 */
const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    offices: { type: Array, required: true },
    hasSystemData: { type: Boolean, default: false },
});

const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));
const chooseYear = (value) => {
    if (Number(value) !== props.year) router.get('/settings/opening-balances', { year: value }, { preserveScroll: true });
};

const form = useForm({ year: props.year, balances: [] });
const load = () => {
    form.year = props.year;
    form.balances = props.offices.map((o) => ({
        field_office_id: o.id,
        unacted: o.unacted === null ? '' : String(o.unacted),
        note: o.note ?? '',
    }));
    form.clearErrors();
};
watch(() => [props.year, props.offices], load, { immediate: true });

const save = () => form.put('/settings/opening-balances', { preserveScroll: true });

// What the reports will start from: PSED's figure; else the system's own count when it has earlier appointments; else nothing yet.
const inUse = (o, i) => (form.balances[i].unacted !== '' ? Number(form.balances[i].unacted) : props.hasSystemData ? o.system : null);
const unsetCount = computed(() => form.balances.filter((b, i) => b.unacted === '' && !props.hasSystemData).length);
const total = computed(() => props.offices.reduce((sum, o, i) => sum + (form.balances[i] ? (inUse(o, i) ?? 0) : 0), 0));
const setCount = computed(() => form.balances.filter((b) => b.unacted !== '').length);
const formatDate = (iso) => new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
</script>

<template>
    <Head title="Opening Balances" />

    <StaffLayout>
        <PageHeader
            title="Opening Balances"
            description="The appointments each Field Office carries into the year: those still unacted on 31 December of the year before."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Opening Balances' }]"
        />

        <Alert tone="info" class="mb-4">
            As the consolidator of the Field Offices' reports, PSED enters each office's balance here: the appointments it had not yet acted on as of
            December 31 of the year before. It becomes the Beginning Balance of January and fills section G of the MRAP, and the consolidated report adds
            the offices together.
            <template v-if="hasSystemData">Where you leave an office blank, the reports use the count the system works out from its own appointments.</template>
            <template v-else>The system holds no earlier appointments yet, so an office left blank starts from 0: enter every office's balance before generating reports.</template>
        </Alert>
        <Alert v-if="unsetCount" tone="warning" class="mb-4">{{ unsetCount }} of {{ offices.length }} Field Offices have no balance for {{ year }} yet. Their reports will start from 0.</Alert>

        <form @submit.prevent="save">
            <Card class="mb-4" title="Balance carried into" icon="archive" :description="`Unacted appointments as of December 31, ${year - 1}.`">
                <div class="max-w-xs">
                    <Combobox id="year" label="Year" :model-value="String(year)" :options="yearOptions" :allow-custom="false" @update:model-value="chooseYear" />
                </div>
            </Card>

            <Table>
                <thead>
                    <tr>
                        <th scope="col">Field Office</th>
                        <th v-if="hasSystemData" scope="col" class="text-right">From the system</th>
                        <th scope="col">Set by PSED</th>
                        <th scope="col" class="text-right">Used in reports</th>
                        <th scope="col">Note</th>
                        <th scope="col">Last set</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(o, i) in offices" :key="o.id">
                        <td class="font-medium">{{ o.name }}</td>
                        <td v-if="hasSystemData" class="text-right text-text-muted">{{ o.system }}</td>
                        <td>
                            <label :for="`bal-${o.id}`" class="sr-only">Opening balance of {{ o.name }} for {{ year }}</label>
                            <input
                                :id="`bal-${o.id}`"
                                v-model="form.balances[i].unacted"
                                type="number"
                                min="0"
                                step="1"
                                inputmode="numeric"
                                :placeholder="hasSystemData ? String(o.system) : '0'"
                                class="w-28 rounded-md border border-border bg-surface px-2 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                :aria-invalid="!!form.errors[`balances.${i}.unacted`]"
                            />
                            <InputError :message="form.errors[`balances.${i}.unacted`]" />
                        </td>
                        <td class="text-right font-semibold">
                            <template v-if="inUse(o, i) !== null">
                                {{ inUse(o, i) }}
                                <span class="block text-xs font-normal text-text-muted">{{ form.balances[i].unacted !== '' ? 'set by PSED' : 'from the system' }}</span>
                            </template>
                            <span v-else class="text-xs font-normal text-warning">Not set (starts from 0)</span>
                        </td>
                        <td>
                            <label :for="`note-${o.id}`" class="sr-only">Note for {{ o.name }}</label>
                            <input
                                :id="`note-${o.id}`"
                                v-model="form.balances[i].note"
                                type="text"
                                maxlength="255"
                                placeholder="e.g. per the FO's December report"
                                class="w-56 rounded-md border border-border bg-surface px-2 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                            />
                        </td>
                        <td class="text-xs text-text-muted">
                            <template v-if="o.set_at">{{ formatDate(o.set_at) }}<span v-if="o.set_by"> · {{ o.set_by }}</span></template>
                            <template v-else>—</template>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">All Field Offices</th>
                        <td v-if="hasSystemData"></td>
                        <td class="text-xs font-normal text-text-muted">{{ setCount }} of {{ offices.length }} set</td>
                        <td class="text-right font-semibold">{{ total }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </Table>

            <div class="mt-4 flex justify-end">
                <Button type="submit" variant="success" :loading="form.processing"><Icon name="check" size="sm" />Save balances</Button>
            </div>
        </form>
    </StaffLayout>
</template>
