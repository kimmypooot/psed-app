<script setup>
import { computed, reactive } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import { useAuthorization } from '../../Composables/useAuthorization';

/**
 * `items`: a mix of leaf entries ({ label, href, icon, permission? }) and
 * group entries ({ group: label, icon?, items: [leaf, ...] }) — see
 * Utils/navigation.js. `permission` may be a single string (must have it)
 * or an array (must have at least one). Leaf entries whose `permission`
 * the user lacks are hidden; a group with no visible children is hidden
 * entirely. This is UX only — every route is independently authorized
 * server-side regardless of what this component renders.
 *
 * `variant`:
 * - `portal`: navy sidebar, groups as plain headings.
 * - `admin`: the legacy NiceAdmin sidebar — white, with collapsible groups
 *   ("User Management", "System Administration") whose children are
 *   marked with a dot, expanded automatically when they hold the current
 *   page. A group may also hold sections ({ section: label, icon?, items })
 *   — collapsible categories one level down (Human Resources → Leave),
 *   opened the same way. The portal variant keeps its groups open under
 *   plain headings and collapses only the sections.
 */
const props = defineProps({
    items: {
        type: Array,
        required: true,
    },
    variant: {
        type: String,
        default: 'portal',
        validator: (value) => ['portal', 'admin'].includes(value),
    },
});

defineEmits(['navigate']);

const { can, canAny } = useAuthorization();
const page = usePage();

const isPermitted = (item) => {
    // `flag`: shown only when the server says so (e.g. leave approvers).
    if (item.flag && !page.props.flags?.[item.flag]) {
        return false;
    }

    if (!item.permission) {
        return true;
    }

    return Array.isArray(item.permission) ? canAny(item.permission) : can(item.permission);
};

/**
 * Normalizes `items` into a flat list of render entries — either
 * `{ type: 'link', ...leaf }` or `{ type: 'group', label, items }` — so
 * the template doesn't need to branch on shape, and groups with nothing
 * visible inside them are dropped rather than rendered as an empty header.
 * A `localOnly` group (Developer) shows only when the app runs locally.
 */
const groupChildren = (group) =>
    group.items.reduce((list, child) => {
        if (!child.section) {
            if (isPermitted(child)) list.push({ type: 'link', ...child });

            return list;
        }

        const visible = child.items.filter(isPermitted).map((link) => ({ type: 'link', ...link }));
        if (!visible.length) return list;

        list.push({ type: 'section', label: child.section, icon: child.icon, key: `${group.group}/${child.section}`, items: visible });

        return list;
    }, []);

const entries = computed(() =>
    props.items.reduce((acc, item) => {
        // A small caption between groups of links (the legacy PSED sidebar's section headings).
        if (item.heading) {
            acc.push({ type: 'heading', label: item.heading });

            return acc;
        }

        if (item.group) {
            const visible = item.localOnly && !page.props.app?.local ? [] : groupChildren(item);

            if (visible.length) {
                acc.push({ type: 'group', label: item.group, icon: item.icon, items: visible });
            }

            return acc;
        }

        if (isPermitted(item)) {
            acc.push({ type: 'link', ...item });
        }

        return acc;
    }, []),
);

// A count on an entry (`badge` names a key of the shared `badges` prop);
// a group or section adds up its children, so it shows while collapsed.
const badgeCount = (entry) =>
    entry.items ? entry.items.reduce((sum, item) => sum + badgeCount(item), 0) : Number(page.props.badges?.[entry.badge] ?? 0);
const badgeText = (count) => (count > 99 ? '99+' : String(count));
const badgeClass = 'ml-auto min-w-[1.25rem] shrink-0 rounded-full bg-danger px-1.5 ring-2 ring-white/40 text-center text-[0.7rem] font-bold leading-5 text-white';

// Stable id for a portal group heading, so the group is labelled by it.
const groupId = (group) => `nav-group-${group.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

const currentPath = computed(() => page.url.split('?')[0]);

// Exact match for area roots (dashboards), prefix match otherwise — and
// only the most specific match is active, so /admin/hr/leave/types
// lights up Leave Types, not Leave Credits (/admin/hr/leave) as well.
const ROOTS = ['/dashboard', '/admin', '/admin/hr'];
const matches = (href) => currentPath.value === href || (!ROOTS.includes(href) && currentPath.value.startsWith(`${href}/`));
const hrefsIn = (items) => items.flatMap((item) => (item.items ? hrefsIn(item.items) : item.href ? [item.href] : []));
const activeHref = computed(() =>
    hrefsIn(props.items)
        .filter(matches)
        .reduce((best, href) => (href.length > (best?.length ?? 0) ? href : best), null),
);
const isActive = (href) => href === activeHref.value;

// A group or section opens by itself when it holds the current page; a
// click overrides that until the next full page load.
const holdsActive = (entry) => entry.items.some((item) => (item.type === 'section' ? holdsActive(item) : isActive(item.href)));
// Groups the person left open are remembered per browser (only "open" is
// stored, so the group holding the current page still opens by itself).
const OPEN_KEY = 'psed.nav-open';
const readOpen = () => {
    try {
        const saved = JSON.parse(window.localStorage.getItem(OPEN_KEY) ?? '[]');

        return Array.isArray(saved) ? Object.fromEntries(saved.map((key) => [key, true])) : {};
    } catch {
        return {};
    }
};
const expanded = reactive(readOpen());
const keyOf = (entry) => entry.key ?? entry.label;
const isExpanded = (entry) => expanded[keyOf(entry)] ?? holdsActive(entry);
const toggle = (entry) => {
    expanded[keyOf(entry)] = !isExpanded(entry);

    try {
        window.localStorage.setItem(OPEN_KEY, JSON.stringify(Object.keys(expanded).filter((key) => expanded[key])));
    } catch {
        // Storage unavailable — the toggle still works for this page.
    }
};
const sectionId = (section) => `nav-section-${section.key.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

// A navy focus ring would vanish on the navy portal sidebar, so it gets a white one.
const linkClass = (href) => {
    return [
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-white',
        isActive(href) ? 'bg-white/15 text-white' : 'text-white/80 hover:bg-white/10 hover:text-white',
    ];
};
</script>

<template>
    <nav class="flex flex-col gap-1 px-3" aria-label="Primary">
        <template v-for="entry in entries" :key="entry.type === 'link' ? entry.href : entry.label">
            <p
                v-if="entry.type === 'heading'"
                class="mt-4 px-3 pt-1 pb-1 text-[0.68rem] font-semibold tracking-widest text-white/50 uppercase first:mt-0"
            >
                {{ entry.label }}
            </p>

            <Link
                v-else-if="entry.type === 'link'"
                :href="entry.href"
                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                :class="linkClass(entry.href)"
                :aria-current="isActive(entry.href) ? 'page' : undefined"
                @click="$emit('navigate')"
            >
                <Icon :name="entry.icon" size="sm" />
                {{ entry.label }}
                <span v-if="badgeCount(entry)" :class="badgeClass">{{ badgeText(badgeCount(entry)) }}<span class="sr-only"> new</span></span>
            </Link>

            <!-- Admin: collapsible group -->
            <div v-else-if="variant === 'admin'">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                    :aria-expanded="isExpanded(entry)"
                    @click="toggle(entry)"
                >
                    <Icon :name="entry.icon || 'grid'" size="sm" />
                    <span class="flex-1">{{ entry.label }}</span>
                    <span v-if="!isExpanded(entry) && badgeCount(entry)" :class="badgeClass">{{ badgeText(badgeCount(entry)) }}<span class="sr-only"> new inside</span></span>
                    <Icon name="chevronDown" size="sm" class="transition-transform" :class="{ 'rotate-180': isExpanded(entry) }" />
                </button>
                <ul v-show="isExpanded(entry)" class="mt-0.5 space-y-0.5">
                    <li v-for="item in entry.items" :key="item.type === 'section' ? item.key : item.href">
                        <component
                            :is="item.external ? 'a' : Link"
                            v-if="item.type === 'link'"
                            :href="item.href"
                            :target="item.external ? '_blank' : undefined"
                            :rel="item.external ? 'noopener' : undefined"
                            class="flex items-center gap-3 rounded-md py-1.5 pr-3 pl-9 text-sm transition-colors"
                            :class="linkClass(item.href)"
                            :aria-current="isActive(item.href) ? 'page' : undefined"
                            @click="$emit('navigate')"
                        >
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full border"
                                :class="isActive(item.href) ? 'border-white bg-white' : 'border-current'"
                                aria-hidden="true"
                            />
                            {{ item.label }}
                            <span v-if="badgeCount(item)" :class="badgeClass">{{ badgeText(badgeCount(item)) }}<span class="sr-only"> new</span></span>
                        </component>

                        <!-- A category inside the group (e.g. Human Resources → Leave) -->
                        <template v-else>
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md py-1.5 pr-3 pl-9 text-left text-xs font-semibold tracking-wide uppercase transition-colors hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                :class="holdsActive(item) ? 'text-white' : 'text-white/70'"
                                :aria-expanded="isExpanded(item)"
                                :aria-controls="sectionId(item)"
                                @click="toggle(item)"
                            >
                                <Icon :name="item.icon || 'grid'" size="sm" />
                                <span class="flex-1">{{ item.label }}</span>
                                <Icon name="chevronDown" size="sm" class="transition-transform" :class="{ 'rotate-180': isExpanded(item) }" />
                            </button>
                            <ul v-show="isExpanded(item)" :id="sectionId(item)" class="mt-0.5 mb-1 space-y-0.5">
                                <li v-for="link in item.items" :key="link.href">
                                    <Link
                                        :href="link.href"
                                        class="flex items-center gap-2.5 rounded-md py-1.5 pr-3 pl-12 text-sm transition-colors"
                                        :class="linkClass(link.href)"
                                        :aria-current="isActive(link.href) ? 'page' : undefined"
                                        @click="$emit('navigate')"
                                    >
                                        <span
                                            class="h-1.5 w-1.5 shrink-0 rounded-full border"
                                            :class="isActive(link.href) ? 'border-white bg-white' : 'border-current'"
                                            aria-hidden="true"
                                        />
                                        {{ link.label }}
                                    </Link>
                                </li>
                            </ul>
                        </template>
                    </li>
                </ul>
            </div>

            <!-- Portal: group as a heading; only its sections collapse -->
            <div v-else role="group" :aria-labelledby="groupId(entry)" class="mt-4 first:mt-0">
                <p :id="groupId(entry)" class="px-3 pb-1 text-xs font-semibold tracking-wide text-white/70 uppercase">
                    {{ entry.label }}
                </p>
                <template v-for="item in entry.items" :key="item.type === 'section' ? item.key : item.href">
                    <Link
                        v-if="item.type === 'link'"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                        :class="linkClass(item.href)"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                        @click="$emit('navigate')"
                    >
                        <Icon :name="item.icon" size="sm" />
                        {{ item.label }}
                        <span v-if="badgeCount(item)" :class="badgeClass">{{ badgeText(badgeCount(item)) }}<span class="sr-only"> new</span></span>
                    </Link>

                    <div v-else>
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium transition-colors hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                            :class="holdsActive(item) ? 'text-white' : 'text-white/80'"
                            :aria-expanded="isExpanded(item)"
                            :aria-controls="sectionId(item)"
                            @click="toggle(item)"
                        >
                            <Icon :name="item.icon || 'grid'" size="sm" />
                            <span class="flex-1">{{ item.label }}</span>
                            <span v-if="!isExpanded(item) && badgeCount(item)" :class="badgeClass">{{ badgeText(badgeCount(item)) }}<span class="sr-only"> new inside</span></span>
                            <Icon name="chevronDown" size="sm" class="transition-transform" :class="{ 'rotate-180': isExpanded(item) }" />
                        </button>
                        <ul v-show="isExpanded(item)" :id="sectionId(item)" class="mt-0.5 mb-1 space-y-0.5">
                            <li v-for="link in item.items" :key="link.href">
                                <Link
                                    :href="link.href"
                                    class="flex items-center gap-3 rounded-md py-1.5 pr-3 pl-9 text-sm transition-colors"
                                    :class="linkClass(link.href)"
                                    :aria-current="isActive(link.href) ? 'page' : undefined"
                                    @click="$emit('navigate')"
                                >
                                    <Icon :name="link.icon" size="sm" />
                                    {{ link.label }}
                                    <span v-if="badgeCount(link)" :class="badgeClass">{{ badgeText(badgeCount(link)) }}<span class="sr-only"> new</span></span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </template>
    </nav>
</template>
