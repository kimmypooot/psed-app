<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Table from '@/Components/UI/Table.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Combobox from '@/Components/Forms/Combobox.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import InputError from '@/Components/Forms/InputError.vue';
import { confirm } from '@/Composables/useDialog';

/**
 * When the Field Offices' monthly reports are due. Each report has a usual day of the following month;
 * a single month can have a date of its own (a holiday, an extension), for one report or for all of them.
 */
const props = defineProps({
    types: { type: Array, required: true },
    overrides: { type: Array, required: true },
    years: { type: Array, required: true },
});

const monthName = (m) => new Date(2000, m - 1, 1).toLocaleString(undefined, { month: 'long' });
const months = Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: monthName(i + 1) }));
const yearOptions = computed(() => props.years.map((y) => ({ value: String(y), label: String(y) })));
const typeOptions = computed(() => [{ value: 'all', label: 'All reports' }, ...props.types.map((t) => ({ value: t.value, label: t.label }))]);
const formatDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });

// --- The usual day -----------------------------------------------------------
const usual = useForm({ days: Object.fromEntries(props.types.map((t) => [t.value, String(t.day)])) });
const saveUsual = () => usual.put('/settings/report-deadlines', { preserveScroll: true });
const ordinal = (n) => {
    const v = Number(n);
    const s = ['th', 'st', 'nd', 'rd'];
    const r = v % 100;

    return `${v}${s[(r - 20) % 10] || s[r] || s[0]}`;
};

// --- One month's own date ----------------------------------------------------
const last = new Date();
last.setMonth(last.getMonth() - 1);
const month = useForm({ type: 'all', year: String(last.getFullYear()), month: String(last.getMonth() + 1), due_date: '' });
const addMonth = () => month.post('/settings/report-deadlines/months', { preserveScroll: true, onSuccess: () => month.reset('due_date') });

const remove = async (o) => {
    const ok = await confirm({
        title: 'Go back to the usual deadline?',
        text: `${o.label} for ${monthName(o.month)} ${o.year} is due ${formatDate(o.due_date)} now. It will follow the usual day again.`,
        confirmText: 'Remove',
        tone: 'danger',
    });

    if (ok) router.delete(`/settings/report-deadlines/months/${o.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Report Deadlines" />

    <StaffLayout>
        <PageHeader
            title="Report Deadlines"
            description="When the Field Offices' monthly reports are due to PSED. A report that is not submitted by then shows as overdue."
            :breadcrumbs="[{ label: 'Settings' }, { label: 'Report Deadlines' }]"
        />

        <Card class="mb-4" title="The usual deadline" icon="calendar" description="Each report is due on this day of the month after the one it covers.">
            <form class="space-y-4" @submit.prevent="saveUsual">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="t in types" :key="t.value">
                        <TextInput
                            :id="`day-${t.value}`"
                            v-model="usual.days[t.value]"
                            type="number"
                            min="1"
                            max="28"
                            :label="t.label"
                            required
                            :invalid="!!usual.errors[`days.${t.value}`]"
                        />
                        <p class="mt-1 text-xs text-text-muted">The {{ ordinal(usual.days[t.value] || 0) }} of the next month</p>
                        <InputError :message="usual.errors[`days.${t.value}`]" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <Button type="submit" variant="success" :loading="usual.processing"><Icon name="check" size="sm" />Save</Button>
                </div>
            </form>
        </Card>

        <Card class="mb-4" title="A different date for one month" icon="clock" description="For a holiday, or when the CSC extends a deadline. It applies to every Field Office.">
            <form class="space-y-4" @submit.prevent="addMonth">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormGroup id="o-type" label="Report" required :error="month.errors.type">
                        <Combobox id="o-type" v-model="month.type" :options="typeOptions" :allow-custom="false" />
                    </FormGroup>
                    <FormGroup id="o-month" label="Month covered" required :error="month.errors.month">
                        <Combobox id="o-month" v-model="month.month" :options="months" :allow-custom="false" />
                    </FormGroup>
                    <FormGroup id="o-year" label="Year" required :error="month.errors.year">
                        <Combobox id="o-year" v-model="month.year" :options="yearOptions" :allow-custom="false" />
                    </FormGroup>
                    <div>
                        <TextInput id="o-date" v-model="month.due_date" type="date" label="Due on" required :invalid="!!month.errors.due_date" />
                        <InputError :message="month.errors.due_date" />
                    </div>
                </div>
                <Alert tone="info">A new date also changes whether reports already submitted for that month count as overdue.</Alert>
                <div class="flex justify-end">
                    <Button type="submit" variant="success" :loading="month.processing"><Icon name="plus" size="sm" />Set deadline</Button>
                </div>
            </form>
        </Card>

        <Table v-if="overrides.length">
            <thead>
                <tr>
                    <th scope="col">Month covered</th>
                    <th scope="col">Report</th>
                    <th scope="col">Due on</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="o in overrides" :key="o.id">
                    <td class="font-medium">{{ monthName(o.month) }} {{ o.year }}</td>
                    <td>{{ o.label }}</td>
                    <td>{{ formatDate(o.due_date) }}</td>
                    <td class="text-right">
                        <Button variant="ghost" size="sm" icon-only :aria-label="`Remove the ${o.label} deadline for ${monthName(o.month)} ${o.year}`" @click="remove(o)"><Icon name="trash" size="sm" /></Button>
                    </td>
                </tr>
            </tbody>
        </Table>
        <p v-else class="text-sm text-text-muted">No month has a date of its own. Every month follows the usual deadline.</p>
    </StaffLayout>
</template>
