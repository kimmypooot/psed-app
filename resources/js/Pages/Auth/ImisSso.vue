<script setup>
import { onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { firstName, useSplash } from '@/Composables/useSplash';

/*
 * Where IMIS sends staff. The "Opening <system>" splash (TransitionSplash,
 * beside the page in app.js) is raised during setup, so it paints in the
 * same frame as the server-drawn copy in app.blade.php, which then goes.
 * The one-time token is posted back while it stays up: the server asks
 * IMIS who it was issued for and either signs the person in ("Login
 * Successful" plays over the dashboard, as in IMIS) or sends them to
 * /login with the reason.
 */
const props = defineProps({
    token: { type: String, required: true },
});

const { show, hide } = useSplash();

show({ instant: true, lead: 'Opening', title: 'Centralized Appointment Attestation and Reporting via Electronic System', subtitle: 'Signing you in from IMIS' });

onMounted(() => {
    router.post('/sso', { sso_token: props.token }, {
        replace: true,
        onSuccess: (page) => {
            if (page.component === 'Auth/SignIn') {
                hide();
                return;
            }

            show({
                instant: true,
                title: 'Login Successful',
                subtitle: 'Welcome back, {name}',
                name: firstName(page.props.auth?.user?.name),
                note: 'Redirecting to your dashboard…',
            });
            hide(1400);
        },
        onError: () => hide(),
    });
});
</script>

<template>
    <Head title="Signing you in" />
</template>
