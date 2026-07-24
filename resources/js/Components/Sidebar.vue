<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Bars3Icon, XMarkIcon } from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission.js';
import { usePresence } from '@/Composables/usePresence.js';
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js';
import { MODULE_REGISTRY } from '@/Composables/useModuleRegistry.js';
import EntitySwitcher from '@/Components/EntitySwitcher.vue';

const props = defineProps({
    isCollapsed: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['toggle']);

const page = usePage();
const dynamicForms = computed(() => page.props.dynamicForms || []);
const { hasPermission } = usePermission();

const visibleDynamicForms = computed(() => {
    return dynamicForms.value.filter(form => hasPermission(form.slug + '.view'));
});
const { init: initPresence, destroy: destroyPresence } = usePresence();
const { init: initSidebar, getSectionOrder, getSectionLabel, ensureDynamicFormChildren } = useSidebarOrder();

const route = window.route;

const toggleSidebar = () => {
    emit('toggle');
};

/** A permission entry may be a single string or an array meaning "any of". */
const permitted = (permission) => {
    if (!permission) return true;
    if (Array.isArray(permission)) return permission.some(p => hasPermission(p));
    return hasPermission(permission);
};

const childVisible = (child) => permitted(child.permission);

const sectionVisible = (section) => {
    if (section.requires && !hasPermission(section.requires)) return false;

    if (section.direct) return permitted(section.permission);

    // Children flagged countsForVisibility:false (e.g. My Profile) are reachable
    // by everyone and must not keep an otherwise-empty section on screen.
    if (section.id === 'services' && visibleDynamicForms.value.length > 0) {
        return true;
    }
    return section.children.some(child => child.countsForVisibility !== false && childVisible(child));
};

/** A section is active on its own hub page or on any of its modules' routes. */
const sectionActive = (section) => {
    if (section.direct) {
        return (section.activeMatch || []).some(pattern => route().current(pattern));
    }
    if (route().current('hub.show') && page.url.includes('/hub/' + section.id)) {
        return true;
    }
    const childActive = section.children.some(child =>
        (child.activeMatch || []).some(pattern => route().current(pattern))
    );
    if (childActive) return true;
    if (section.id === 'services' && route().current('dynamic-form.*')) {
        return visibleDynamicForms.value.some(f => page.url.includes('/forms/' + f.slug));
    }
    return false;
};

/** Where a section navigates: its module for direct sections, else its hub. */
const sectionHref = (section) =>
    section.direct ? route(section.routeName) : route('hub.show', section.id);

/** Sections in the user's saved order. */
const orderedSections = computed(() => {
    return MODULE_REGISTRY
        .filter(sectionVisible)
        .slice()
        .sort((a, b) => getSectionOrder(a.id) - getSectionOrder(b.id));
});

onMounted(() => {
    initPresence();
    initSidebar(page.props.sidebarLayout);
    // Dynamic forms still register as Services children so the hub can order and
    // label them from the saved sidebar layout.
    ensureDynamicFormChildren(dynamicForms.value);
});

onUnmounted(() => {
    destroyPresence();
});
</script>

<template>
    <aside
        :class="[
            'bg-white text-gray-700 border-r border-gray-200 transition-all duration-300 ease-in-out flex flex-col h-full shrink-0 dark:bg-gray-900 dark:text-white dark:border-transparent',
            isCollapsed ? 'w-20 z-[80]' : 'w-72'
        ]"
    >
        <!-- Sidebar Header -->
        <div class="relative flex items-center justify-between px-4 border-b border-gray-200 shrink-0 h-16 w-full dark:border-gray-800">
            <div v-if="!isCollapsed" class="flex items-center flex-shrink-0">
                <div class="h-10 px-3 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm" style="background-color: white !important;">
                    <img src="/images/company_logo.png" alt="Company Logo" class="h-7 w-auto object-contain flex-shrink-0">
                </div>
            </div>

            <button
                @click="toggleSidebar"
                class="relative z-10 p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200 flex-shrink-0 dark:hover:bg-gray-800"
                :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            >
                <Bars3Icon v-if="isCollapsed" class="w-5 h-5 text-gray-400 group-hover:text-white dark:text-gray-400" />
                <XMarkIcon v-else class="w-5 h-5 text-gray-400 dark:text-gray-400" />
            </button>
        </div>

        <!-- Navigation: every section is a single link (direct module or its hub) -->
        <nav
            :class="[
                'flex-1 p-4 flex flex-col gap-1',
                isCollapsed ? 'overflow-visible' : 'overflow-y-auto custom-scrollbar'
            ]"
        >
            <Link
                v-for="section in orderedSections"
                :key="section.id"
                :href="sectionHref(section)"
                :style="sectionActive(section) ? { backgroundColor: 'var(--dept-accent)' } : {}"
                :class="[
                    'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                    sectionActive(section)
                        ? 'text-white shadow-sm'
                        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white'
                ]"
            >
                <component
                    :is="section.icon"
                    v-if="section.icon"
                    :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']"
                />
                <svg
                    v-else-if="section.iconPath"
                    :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="section.iconPath" />
                </svg>
                <span v-if="!isCollapsed" class="flex flex-col min-w-0 leading-tight">
                    <span class="truncate font-medium">{{ getSectionLabel(section.id) }}</span>
                    <span
                        v-if="section.verb"
                        :class="[
                            'truncate text-[9px] font-black uppercase tracking-[0.18em]',
                            sectionActive(section) ? 'text-white/70' : 'text-gray-400 group-hover:text-gray-500 dark:text-gray-500 dark:group-hover:text-gray-400'
                        ]"
                    >{{ section.verb }}</span>
                </span>
                <!-- Collapsed: tooltip on hover -->
                <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                    {{ getSectionLabel(section.id) }}
                    <span v-if="section.verb" class="ml-1 text-[9px] font-black uppercase tracking-widest text-blue-300">{{ section.verb }}</span>
                </div>
            </Link>
        </nav>

        <!-- Entity Switcher (lower part) -->
        <div class="px-4 py-4 border-t border-gray-200 shrink-0 dark:border-gray-800">
            <EntitySwitcher :is-collapsed="isCollapsed" />
        </div>
    </aside>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #4b5563;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #6b7280;
}
</style>
