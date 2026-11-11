import { computed } from 'vue';

/**
 * The images the IMIS components ask for, as in IMIS's useBranding: `logo` is
 * the CSC seal (header, sign-in/out splash) and `app_logo` the integrated IMIS
 * logo (boot preloader, "Opening" screen, sign-in card). IMIS lets the office
 * replace them (Admin > Branding); CARES serves the built-in ones from
 * public/images/brand, copied from IMIS.
 */
const builtIn = {
    logo: '/images/brand/csc-logo.png',
    app_logo: '/images/brand/CSC-IMIS.png',
    login_background: '/images/brand/cscbg_facade.jpeg',
};

export function useBranding() {
    return computed(() => builtIn);
}