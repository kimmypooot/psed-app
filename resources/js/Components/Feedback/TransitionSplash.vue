<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useSplash } from '../../Composables/useSplash';
import { useBranding } from '../../Composables/useBranding';

/**
 * Renders the splash driven by useSplash(): a port of the legacy
 * IMISTransition (old-imis js/imis_transition.js), flattened: a plain
 * white card on the muted page background, the CSC seal inside two
 * counter-rotating rings (navy outer, red inner), the message, greeting
 * and note, and three dots that take turns lighting up red. Styles are
 * the .imis-tr* classes in app.css. Announced through a polite live region.
 */
const { splash } = useSplash();
const brand = useBranding();

// "{name}" in the subtitle becomes the highlighted first name; without a
// name, the placeholder (and its leading comma) is dropped.
const subtitleParts = computed(() => {
    if (!splash.name) {
        return { before: splash.subtitle.replace(/,?\s*\{name\}/, ''), after: '', hasName: false };
    }

    const [before, ...rest] = splash.subtitle.split('{name}');

    return { before, after: rest.join('{name}'), hasName: true };
});

// The legacy dots: one lights up at a time, moving every 400ms.
const activeDot = ref(0);
let dotsTimer = null;

const stopDots = () => window.clearInterval(dotsTimer);

watch(
    () => splash.visible,
    (visible) => {
        stopDots();
        if (!visible) return;
        activeDot.value = 0;
        dotsTimer = window.setInterval(() => (activeDot.value = (activeDot.value + 1) % 3), 400);
    },
    { immediate: true },
);

onBeforeUnmount(stopDots);

// Ring geometry from the legacy SVGs (24×24 viewBox): a grey track plus a
// rotating arc with a dash gap.
const rings = [
    { cls: 'imis-tr__ring-outer', arc: 'imis-tr__arc--navy', r: 10, dash: '62.832', offset: '20', width: '2.5' },
    { cls: 'imis-tr__ring-inner', arc: 'imis-tr__arc--red', r: 8, dash: '50.265', offset: '15', width: '1.5' },
];
</script>

<template>
    <Transition :name="splash.instant ? 'imis-tr-instant' : 'imis-tr'" :duration="{ enter: 450, leave: 300 }">
        <div v-if="splash.visible" class="imis-tr" role="status" aria-live="polite" aria-busy="true">
            <div class="imis-tr__card">
                <div class="imis-tr__rings" aria-hidden="true">
                    <svg v-for="ring in rings" :key="ring.cls" :class="ring.cls" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" :r="ring.r" class="imis-tr__track" :stroke-width="ring.width" />
                        <circle
                            cx="12"
                            cy="12"
                            :r="ring.r"
                            :class="ring.arc"
                            :stroke-width="ring.width"
                            stroke-linecap="round"
                            :stroke-dasharray="ring.dash"
                            :stroke-dashoffset="ring.offset"
                        />
                    </svg>
                    <img :src="brand.logo" alt="" class="imis-tr__logo" />
                </div>

                <div class="imis-tr__msg"><span v-if="splash.lead" class="imis-tr__lead">{{ splash.lead }}</span> {{ splash.title || 'Please wait' }}</div>
                <!-- prettier-ignore -->
                <div v-if="splash.subtitle" class="imis-tr__sub">{{ subtitleParts.before }}<span v-if="subtitleParts.hasName" class="imis-tr__name">{{ splash.name }}</span>{{ subtitleParts.after }}</div>
                <div v-if="splash.note" class="imis-tr__note">{{ splash.note }}</div>

                <div class="imis-tr__dots" aria-hidden="true">
                    <span v-for="index in 3" :key="index" :class="{ 'is-active': activeDot === index - 1 }" />
                </div>
            </div>
        </div>
    </Transition>
</template>
