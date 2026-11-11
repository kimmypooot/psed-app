import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Filters (search, sort, a select) that live in the query string: a change
 * re-requests the page, a search after a short pause. Empty values are dropped
 * from the URL.
 */
export function useListFilters(url, initial) {
    const filters = reactive({ ...initial });
    let timer;

    watch(
        () => ({ ...filters }),
        (value, old) => {
            clearTimeout(timer);
            timer = setTimeout(
                () => {
                    const query = Object.fromEntries(Object.entries(value).filter(([, v]) => v !== '' && v !== null && v !== false));
                    router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
                },
                value.search !== old.search ? 300 : 0,
            );
        },
    );

    return filters;
}