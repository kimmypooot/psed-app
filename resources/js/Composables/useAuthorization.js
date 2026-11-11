import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Frontend-only convenience for conditionally rendering UI (hide a button,
 * skip a nav item). This is NOT security — every sensitive action is
 * re-checked server-side (policies/gates/middleware), so a user can never
 * gain access simply by editing the client or calling a route directly.
 */
export function useAuthorization() {
    const page = usePage();

    const roles = computed(() => page.props.auth?.roles ?? []);
    const permissions = computed(() => page.props.auth?.permissions ?? []);

    const hasRole = (role) => roles.value.includes(role);
    const hasAnyRole = (checkRoles) => checkRoles.some(hasRole);
    const hasAllRoles = (checkRoles) => checkRoles.every(hasRole);

    const can = (permission) => permissions.value.includes(permission);
    const canAny = (checkPermissions) => checkPermissions.some(can);
    const canAll = (checkPermissions) => checkPermissions.every(can);

    return { roles, permissions, hasRole, hasAnyRole, hasAllRoles, can, canAny, canAll };
}
