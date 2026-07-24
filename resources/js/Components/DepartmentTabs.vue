<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowUpRightIcon, ArrowDownLeftIcon } from '@heroicons/vue/24/solid';

/**
 * Global department strip, shown on every page directly below the top nav.
 * Left: department master-tabs (the departments under the active entity).
 * Right: the derived provider/customer access indicator. Selecting a tab sets
 * the viewed department for the session, which flips the access view.
 */
const page = usePage();

const ctx = computed(() => page.props.departmentContext || { home: null, viewed: null, accessView: 'customer', isExecutive: false, canSwitchHome: false, departments: [] });
const departments = computed(() => ctx.value.departments || []);
const viewedId = computed(() => ctx.value.viewed);
const homeId = computed(() => ctx.value.home);
const isProvider = computed(() => ctx.value.accessView === 'provider');
const isExecutive = computed(() => ctx.value.isExecutive === true);
const canSwitchHome = computed(() => ctx.value.canSwitchHome === true);

const viewedName = computed(() => departments.value.find(d => d.id === viewedId.value)?.name || '');
const homeName = computed(() => departments.value.find(d => d.id === homeId.value)?.name || '');

// "I belong to" current selection: Executive sentinel or the home department id.
const belongValue = computed(() => isExecutive.value ? 'executive' : (homeId.value != null ? String(homeId.value) : ''));
const belongLabel = computed(() => {
    if (isExecutive.value) return 'Executive';
    const d = departments.value.find(x => x.id === homeId.value);
    return d ? (d.code || d.name) : '—';
});

const switchDepartment = (id) => {
    // From Executive: one round-trip clears the override, views the department, and
    // redirects to its workspace (no multi-load flash from chaining requests).
    if (isExecutive.value) {
        router.post(route('department-context.open-department'), { department_id: id }, { preserveState: false });
        return;
    }
    if (id === viewedId.value) return;
    router.post(route('department-context.switch'), { department_id: id }, {
        preserveScroll: true,
        preserveState: false,
    });
};

// The "ALL" pill = enterprise / Executive mode.
const selectAll = () => {
    router.post(route('department-context.belong'), { home: 'executive' }, {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => router.visit(route('executive.index')),
    });
};

const changeBelong = (event) => {
    const value = event.target.value;
    if (value === belongValue.value) return;
    router.post(route('department-context.belong'), { home: value }, {
        preserveScroll: true,
        preserveState: false,
        // Selecting Executive opens the enterprise master view.
        onSuccess: () => { if (value === 'executive') router.visit(route('executive.index')); },
    });
};
</script>

<template>
    <div
        v-if="departments.length"
        class="border-b border-gray-200 bg-white/95 backdrop-blur px-4 sm:px-6 lg:px-8 dark:border-gray-700 dark:bg-gray-800/95"
        :style="{ borderTop: '2px solid var(--dept-accent)' }"
    >
        <div class="flex flex-col gap-2 py-2 xl:flex-row xl:items-center xl:justify-between">
            <!-- Department master-tabs (full-width scrollable row below xl) -->
            <div class="flex flex-1 items-center gap-2 overflow-x-auto no-scrollbar min-w-0 -mx-1 px-1">
                <span class="shrink-0 text-[10px] font-black uppercase tracking-[0.18em] text-gray-400 dark:text-gray-500">
                    Department
                </span>
                <!-- ALL = enterprise / Executive mode -->
                <button
                    v-if="canSwitchHome"
                    type="button"
                    @click="selectAll"
                    :style="isExecutive ? { backgroundColor: '#253d5b' } : {}"
                    :class="[
                        'shrink-0 rounded-lg px-3 py-1.5 text-xs font-black uppercase tracking-wider transition-all duration-150',
                        isExecutive
                            ? 'text-white shadow-sm'
                            : 'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700',
                    ]"
                    title="Enterprise (Executive) view"
                >All</button>
                <button
                    v-for="dept in departments"
                    :key="dept.id"
                    type="button"
                    @click="switchDepartment(dept.id)"
                    :style="(dept.id === viewedId && !isExecutive) ? { backgroundColor: 'var(--dept-accent)' } : {}"
                    :class="[
                        'shrink-0 rounded-lg px-3 py-1.5 text-xs font-bold transition-all duration-150',
                        (dept.id === viewedId && !isExecutive)
                            ? 'text-white shadow-sm'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700',
                    ]"
                    :title="dept.name"
                >
                    {{ dept.code || dept.name }}
                    <span
                        v-if="dept.id === homeId"
                        class="ml-1 text-[8px] font-black uppercase tracking-widest"
                        :class="dept.id === viewedId ? 'text-white/70' : 'text-blue-500'"
                    >home</span>
                </button>
            </div>

            <!-- Right cluster: "I belong to" + derived access indicator -->
            <div class="flex shrink-0 items-center gap-2 self-end xl:self-auto">
            <!-- "I belong to" — read-only badge, or dropdown for elevated users -->
            <div class="hidden lg:flex shrink-0 items-center gap-2 rounded-lg border border-gray-200 px-3 py-1 dark:border-gray-700">
                <span class="text-[9px] font-black uppercase tracking-[0.18em] text-gray-400 dark:text-gray-500">I belong to</span>
                <select
                    v-if="canSwitchHome"
                    :value="belongValue"
                    @change="changeBelong"
                    class="border-0 bg-transparent py-0.5 pl-2 pr-7 text-xs font-bold text-gray-800 focus:ring-0 dark:text-gray-100"
                    aria-label="Choose the department you belong to"
                >
                    <option value="executive">Executive</option>
                    <option v-for="dept in departments" :key="dept.id" :value="String(dept.id)">
                        {{ dept.code || dept.name }}
                    </option>
                </select>
                <span v-else class="text-xs font-bold text-gray-800 dark:text-gray-100">{{ belongLabel }}</span>
            </div>

            <!-- Executive mode → link to the enterprise master view -->
            <Link
                v-if="isExecutive"
                :href="route('executive.index')"
                class="hidden md:flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-slate-100"
                style="background-color: #253d5b;"
                title="Open the enterprise Executive Overview"
            >
                <span class="text-[11px] font-black">Executive Overview</span>
                <span class="text-[8px] font-black uppercase tracking-widest opacity-70">Enterprise →</span>
            </Link>

            <!-- Derived access indicator -->
            <div
                v-else
                :class="[
                    'hidden md:flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5',
                    isProvider
                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/25 dark:text-emerald-300'
                        : 'bg-blue-50 text-blue-700 dark:bg-blue-900/25 dark:text-blue-300',
                ]"
                :title="isProvider
                    ? viewedName + ' — you manage this department\'s work (provider)'
                    : (homeName ? homeName + ' visiting ' + viewedName + ' (customer)' : viewedName + ' (customer)')"
            >
                <ArrowDownLeftIcon v-if="isProvider" class="h-3.5 w-3.5" />
                <ArrowUpRightIcon v-else class="h-3.5 w-3.5" />
                <span class="text-[11px] font-black">
                    <template v-if="isProvider">{{ viewedName }} Provider</template>
                    <template v-else-if="homeName">Customer of {{ viewedName }}</template>
                    <template v-else>{{ viewedName }}</template>
                </span>
                <span class="text-[8px] font-black uppercase tracking-widest opacity-70">Auto</span>
            </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { height: 0; }
.no-scrollbar { scrollbar-width: none; }
</style>
