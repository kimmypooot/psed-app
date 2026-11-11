<script setup>
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppointmentForm from '@/Components/Appointments/AppointmentForm.vue';

defineProps({
    options: { type: Object, required: true },
    actionOfficers: { type: Object, required: true },
    offices: { type: Array, required: true },
    defaults: { type: Object, required: true },
});
</script>

<template>
    <Head title="Encode appointment" />

    <StaffLayout>
        <PageHeader
            title="Encode appointment"
            description="Enter the details from the appointment documents submitted by the client agency. It is routed to the Field Office or to PSED as soon as it is saved."
            :breadcrumbs="[{ label: 'Appointments', href: '/appointments' }, { label: 'Encode' }]"
        />

        <AppointmentForm :options="options" :action-officers="actionOfficers" :offices="offices" :defaults="defaults" />
    </StaffLayout>
</template>
