<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Squares2X2Icon } from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission.js';
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js';
import { MODULE_REGISTRY, MODULE_SECTIONS } from '@/Composables/useModuleRegistry.js';

/**
 * GLOBAL module tab strip: self-determines the current section from the route and
 * renders that section's modules as horizontal tabs, so every sibling module under
 * the active parent menu is visible at a glance (replaces the right-edge drawer).
 */
const page = usePage();
const route = window.route;
const { hasPermission } = usePermission();
const { getSectionLabel, getChildLabel, getChildOrder } = useSidebarOrder();

const permitted = (permission) => {
    if (!permission) return true;
    if (Array.isArray(permission)) return permission.some((p) => hasPermission(p));
    return hasPermission(permission);
};

/** Dynamic forms behave as extra Services modules. */
const dynamicFormChildren = computed(() =>
    (page.props.dynamicForms || [])
        .filter((f) => hasPermission(f.slug + '.view'))
        .map((f) => {
            const id = 'form-' + f.slug;
            const label = getChildLabel('services', id);
            return {
                id,
                resolvedLabel: label === id ? f.name : label,
                icon: null,
                routeName: 'dynamic-form.index',
                routeParams: [f.slug],
                activeMatch: [],
                slug: f.slug,
            };
        })
);

/** The section that owns the current route (hub page or a module page under it). */
const currentSection = computed(() => {
    if (route().current('hub.show')) {
        const m = (page.url || '').match(/\/hub\/([^/?#]+)/);
        if (m && MODULE_SECTIONS[m[1]]) return MODULE_SECTIONS[m[1]];
    }
    for (const section of MODULE_REGISTRY) {
        if (section.direct) {
            if ((section.activeMatch || []).some((p) => route().current(p))) return section;
        } else if (section.children.some((c) => (c.activeMatch || []).some((p) => route().current(p)))) {
            return section;
        }
    }
    return null;
});

const tabs = computed(() => {
    const section = currentSection.value;
    if (!section || section.direct) return [];
    const base = section.children
        .filter((c) => permitted(c.permission))
        .map((c) => {
            const label = getChildLabel(section.id, c.id);
            return { ...c, resolvedLabel: label === c.id ? c.label : label };
        });
    const all = section.id === 'services' ? [...base, ...dynamicFormChildren.value] : base;
    return all.slice().sort((a, b) => getChildOrder(section.id, a.id) - getChildOrder(section.id, b.id));
});

const sectionLabel = computed(() => (currentSection.value ? getSectionLabel(currentSection.value.id) : ''));
const accent = computed(() => page.props.departmentContext?.accent || '#2d6fe4');

/** Hub overview tab is active only when we're on the hub page itself. */
const onHub = computed(() => route().current('hub.show'));

const isActive = (tab) => {
    if (onHub.value) return false;
    if (tab.slug) return route().current('dynamic-form.index') && (page.url || '').includes('/' + tab.slug);
    return (tab.activeMatch || []).some((p) => route().current(p));
};

const href = (tab) => route(tab.routeName, ...(tab.routeParams || []));
</script>

<template>
    <div
        v-if="tabs.length && !onHub"
        class="border-b border-gray-200 bg-gray-50/95 backdrop-blur px-4 sm:px-6 lg:px-8 dark:border-gray-700 dark:bg-gray-900/95"
    >
        <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar -mx-1 px-1 py-2">
            <span class="shrink-0 pr-1 text-[10px] font-black uppercase tracking-[0.18em] text-gray-400 dark:text-gray-500">
                {{ sectionLabel }}
            </span>

            <!-- Section hub overview -->
            <Link
                :href="route('hub.show', currentSection.id)"
                :title="'Back to the ' + sectionLabel + ' overview'"
                class="group inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-bold transition-all"
                :class="onHub
                    ? 'border-transparent text-white shadow-md'
                    : 'border-gray-200 bg-white text-gray-600 shadow-sm hover:-translate-y-0.5 hover:text-gray-900 hover:shadow dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:text-white'"
                :style="onHub ? { backgroundColor: accent } : {}"
                @mouseenter="(e) => { if (!onHub) e.currentTarget.style.borderColor = accent; }"
                @mouseleave="(e) => { if (!onHub) e.currentTarget.style.borderColor = ''; }"
            >
                <Squares2X2Icon class="h-4 w-4 shrink-0" />
                <span>Overview</span>
            </Link>

            <span class="h-5 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>

            <!-- Sibling module tabs -->
            <Link
                v-for="tab in tabs"
                :key="tab.id"
                :href="href(tab)"
                :title="'Open ' + tab.resolvedLabel + (tab.description ? ' — ' + tab.description : '')"
                class="group inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-bold transition-all"
                :class="isActive(tab)
                    ? 'border-transparent text-white shadow-md'
                    : 'border-gray-200 bg-white text-gray-600 shadow-sm hover:-translate-y-0.5 hover:text-gray-900 hover:shadow dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:text-white'"
                :style="isActive(tab) ? { backgroundColor: accent } : {}"
                @mouseenter="(e) => { if (!isActive(tab)) e.currentTarget.style.borderColor = accent; }"
                @mouseleave="(e) => { if (!isActive(tab)) e.currentTarget.style.borderColor = ''; }"
            >
                <component :is="tab.icon" v-if="tab.icon" class="h-4 w-4 shrink-0"
                           :class="isActive(tab) ? '' : 'text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-200'" />
                <span>{{ tab.resolvedLabel }}</span>
            </Link>
        </div>
    </div>
</template>
