<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Squares2X2Icon, XMarkIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission.js';
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js';
import { MODULE_REGISTRY, MODULE_SECTIONS } from '@/Composables/useModuleRegistry.js';

/**
 * GLOBAL floating module launcher: a fixed button on the right edge (follows
 * scroll on every page). It self-determines the current section from the route
 * and lists that section's modules, so you can hop between sibling modules from
 * anywhere within a section — the hub OR any module page under it.
 */
const page = usePage();
const route = window.route;
const { hasPermission } = usePermission();
const { getSectionLabel, getChildLabel, getChildOrder } = useSidebarOrder();

const open = ref(false);

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
                description: 'Dynamic form',
                icon: null,
                routeName: 'dynamic-form.index',
                routeParams: [f.slug],
            };
        })
);

/** The section that owns the current route (hub page or a module page under it). */
const currentSection = computed(() => {
    // On a hub page, use the URL section id.
    if (route().current('hub.show')) {
        const m = (page.url || '').match(/\/hub\/([^/?#]+)/);
        if (m && MODULE_SECTIONS[m[1]]) return MODULE_SECTIONS[m[1]];
    }
    // Otherwise, find the section whose module route matches.
    for (const section of MODULE_REGISTRY) {
        if (section.direct) {
            if ((section.activeMatch || []).some((p) => route().current(p))) return section;
        } else if (section.children.some((c) => (c.activeMatch || []).some((p) => route().current(p)))) {
            return section;
        }
    }
    return null;
});

const tiles = computed(() => {
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

const label = computed(() => (currentSection.value ? getSectionLabel(currentSection.value.id) : 'Modules'));
const accent = computed(() => page.props.departmentContext?.accent || '#2d6fe4');
const count = computed(() => tiles.value.length);

const go = (tile) => {
    open.value = false;
    router.visit(route(tile.routeName, ...(tile.routeParams || [])));
};
</script>

<template>
    <template v-if="count">
        <!-- Floating edge button -->
        <button
            type="button"
            @click="open = true"
            :style="{ backgroundColor: accent }"
            class="fixed right-0 top-1/2 z-40 flex -translate-y-1/2 flex-col items-center gap-2 rounded-l-2xl py-4 pl-3 pr-2.5 text-white shadow-lg transition-all hover:pr-4"
            :aria-label="'Open ' + label + ' modules'"
            :title="label + ' modules'"
        >
            <Squares2X2Icon class="h-6 w-6" />
            <span class="text-[10px] font-black uppercase tracking-widest" style="writing-mode: vertical-rl; text-orientation: mixed;">{{ label }}</span>
            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-white/25 text-[10px] font-black">{{ count }}</span>
        </button>

        <!-- Drawer -->
        <Teleport to="body">
            <transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0">
                <div v-if="open" @click="open = false" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm"></div>
            </transition>
            <transition enter-active-class="transition-transform duration-300 ease-out" enter-from-class="translate-x-full" leave-active-class="transition-transform duration-200 ease-in" leave-to-class="translate-x-full">
                <aside v-if="open" class="fixed right-0 top-0 z-50 flex h-full w-80 max-w-[88vw] flex-col bg-white shadow-2xl dark:bg-gray-900" :style="{ borderTop: '3px solid ' + accent }">
                    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: accent }">Modules</div>
                            <div class="text-sm font-black text-gray-900 dark:text-white">{{ label }}</div>
                        </div>
                        <button type="button" @click="open = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800" aria-label="Close">
                            <XMarkIcon class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="flex-1 space-y-2 overflow-y-auto p-3">
                        <button
                            v-for="tile in tiles"
                            :key="tile.id"
                            type="button"
                            @click="go(tile)"
                            class="group flex w-full items-start gap-3 rounded-xl border border-gray-200 bg-white p-3 text-left transition-all hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-blue-500/60"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                                <component :is="tile.icon" v-if="tile.icon" class="h-5 w-5" />
                                <Squares2X2Icon v-else class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1">
                                    <span class="truncate font-bold text-gray-900 dark:text-white">{{ tile.resolvedLabel }}</span>
                                    <ChevronRightIcon class="h-4 w-4 shrink-0 text-gray-300 transition-transform group-hover:translate-x-0.5 group-hover:text-blue-500 dark:text-gray-600" />
                                </span>
                                <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ tile.description }}</span>
                                <span v-if="tile.eta" class="mt-1 block text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">◷ Target response · {{ tile.eta }}</span>
                            </span>
                        </button>
                    </div>
                </aside>
            </transition>
        </Teleport>
    </template>
</template>
