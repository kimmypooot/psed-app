<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Badge from '@/Components/UI/Badge.vue';
import AppointmentForm from '@/Components/Appointments/AppointmentForm.vue';

const props = defineProps({
    appointment: { type: Object, required: true },
    options: { type: Object, required: true },
    actionOfficers: { type: Object, required: true },
    offices: { type: Array, required: true },
    canRecordAction: { type: Boolean, required: true },
});

const name = computed(() => {
    const a = props.appointment;

    return `${a.last_name}, ${a.first_name}`;
});
</script>

<template>
    <Head :title="`Edit ${name}`" />

    <StaffLayout>
        <PageHeader :title="name" :breadcrumbs="[{ label: 'Appointments', href: '/appointments' }, { label: 'Edit' }]">
            <Badge :tone="appointment.processed_by === 'psed' ? 'info' : 'neutral'">Processed by {{ appointment.processed_by_label }}</Badge>
        </PageHeader>

        <AppointmentForm :appointment="appointment" :options="options" :action-officers="actionOfficers" :offices="offices" :can-record-action="canRecordAction" />
    </StaffLayout>
</template>
