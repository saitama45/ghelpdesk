<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    BuildingOffice2Icon,
    ClipboardDocumentCheckIcon,
    ExclamationTriangleIcon,
    ChartPieIcon,
    AdjustmentsHorizontalIcon,
    ChevronRightIcon,
    CheckIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    overview: { type: Object, default: null },
    type: { type: String, default: '' },
});

/* ------------------------------------------------------------------ blocks */

// Customize View state. Kept in localStorage per project type — this is a
// personal display preference, not data worth a round trip to the server.
const BLOCKS = [
    { key: 'banner',      label: 'Access banner' },
    { key: 'kpis',        label: 'Headline metrics' },
    { key: 'performance', label: 'Portfolio performance' },
    { key: 'departments', label: 'Progress by department' },
    { key: 'health',      label: 'Project health' },
    { key: 'alerts',      label: 'Critical alerts' },
];

const storageKey = computed(() => `projects.overview.blocks.${props.type || 'all'}`);
const hidden = ref([]);

onMounted(() => {
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey.value) || '[]');
        if (Array.isArray(saved)) hidden.value = saved;
    } catch {
        hidden.value = [];
    }
});

const shows = (key) => !hidden.value.includes(key);

const toggleBlock = (key) => {
    hidden.value = shows(key)
        ? [...hidden.value, key]
        : hidden.value.filter(k => k !== key);
    try {
        localStorage.setItem(storageKey.value, JSON.stringify(hidden.value));
    } catch { /* private mode — the preference just won't persist */ }
};

const customizeOpen = ref(false);
const customizeRef = ref(null);

const onClickOutside = (event) => {
    if (customizeRef.value && !customizeRef.value.contains(event.target)) {
        customizeOpen.value = false;
    }
};

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));

/* ------------------------------------------------------------------ colour */

// Categorical slots, validated for both surfaces (see the palette validator:
// worst adjacent CVD ΔE 9.1 light / 8.4 dark). Every bar is directly labelled
// with its department name and value, so colour never carries identity alone —
// which is also the relief required by the contrast warning on slots 3-5.
const SERIES_SLOTS = 8;

// Colour follows the department, not its position in the list, so filtering the
// portfolio never repaints the departments that remain.
const slotFor = (name) => {
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = (hash * 31 + name.charCodeAt(i)) % 100000;
    }
    return hash % SERIES_SLOTS;
};

const kpiTone = {
    slate:   { icon: BuildingOffice2Icon,        wrap: 'bg-slate-50 text-slate-600 dark:bg-slate-900/40 dark:text-slate-300' },
    indigo:  { icon: ClipboardDocumentCheckIcon, wrap: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-300' },
    rose:    { icon: ExclamationTriangleIcon,    wrap: 'bg-rose-50 text-rose-600 dark:bg-rose-900/30 dark:text-rose-300' },
    emerald: { icon: ChartPieIcon,               wrap: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300' },
};

const statePill = {
    delayed:  'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-900/30 dark:text-rose-300 dark:ring-rose-800',
    at_risk:  'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800',
    on_track: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800',
};

const stateLabel = {
    delayed: 'Delayed',
    at_risk: 'At Risk',
    on_track: 'On Track',
};

const kpis = computed(() => props.overview?.kpis ?? []);
const tiles = computed(() => props.overview?.tiles ?? []);
const departments = computed(() => props.overview?.departments ?? []);
const health = computed(() => props.overview?.health ?? []);
const alerts = computed(() => props.overview?.alerts ?? []);
const banner = computed(() => props.overview?.banner ?? null);

const hasAnything = computed(() =>
    departments.value.length > 0 || health.value.length > 0 || kpis.value.length > 0
);

/* --------------------------------------------------------------- drill-down */

// Every box carries the rows behind its number, so the modal is pure display —
// opening it costs no request.
const drill = ref(null);

const openDrill = (title, value, breakdown) => {
    if (!breakdown) return;
    drill.value = { title, value, ...breakdown };
};

const closeDrill = () => { drill.value = null; };
</script>

<template>
    <!-- Loading: the payload is lazy (Inertia::optional) and arrives on the first click. -->
    <div v-if="!overview" class="space-y-6">
        <div class="h-24 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="n in 4" :key="n" class="h-32 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
        </div>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="h-96 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800 lg:col-span-2" />
            <div class="h-96 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
        </div>
    </div>

    <div v-else class="viz-root space-y-6">

        <!-- Header row: title + Customize View -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-black tracking-tight text-gray-900 dark:text-gray-100">
                    {{ type }} Overview
                </h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    Schedules, department commitments, approvals and readiness across all {{ type }} projects.
                </p>
            </div>

            <div ref="customizeRef" class="relative">
                <button
                    type="button"
                    @click.stop="customizeOpen = !customizeOpen"
                    class="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-600 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    <AdjustmentsHorizontalIcon class="h-4 w-4" />
                    Customize View
                </button>

                <!-- Absolute (not fixed) so it is unaffected by blurred/transformed ancestors. -->
                <div
                    v-if="customizeOpen"
                    class="absolute right-0 z-30 mt-2 w-60 rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    <p class="px-2 py-1.5 text-[10px] font-black uppercase tracking-widest text-gray-400">
                        Sections
                    </p>
                    <button
                        v-for="block in BLOCKS"
                        :key="block.key"
                        type="button"
                        @click="toggleBlock(block.key)"
                        class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        {{ block.label }}
                        <CheckIcon v-if="shows(block.key)" class="h-4 w-4 text-emerald-600" />
                        <span v-else class="text-[10px] font-bold uppercase text-gray-400">Hidden</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Access banner — describes existing rights, grants nothing -->
        <div
            v-if="shows('banner') && banner"
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-emerald-100 bg-emerald-50/60 p-5 dark:border-emerald-900 dark:bg-emerald-900/20"
        >
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white text-emerald-600 shadow-sm dark:bg-gray-800 dark:text-emerald-400">
                    <BuildingOffice2Icon class="h-6 w-6" />
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-emerald-700 dark:text-emerald-400">
                        Current access<span v-if="banner.department"> • {{ banner.department }}</span>
                    </p>
                    <p class="mt-0.5 text-base font-black text-gray-900 dark:text-gray-100">{{ banner.headline }}</p>
                    <p class="mt-0.5 max-w-3xl text-sm text-gray-600 dark:text-gray-300">{{ banner.blurb }}</p>
                </div>
            </div>
            <span class="rounded-full border border-emerald-300 px-3 py-1.5 text-xs font-bold text-emerald-700 dark:border-emerald-700 dark:text-emerald-300">
                {{ banner.pill }}
            </span>
        </div>

        <!-- Headline metrics -->
        <div v-if="shows('kpis')" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button
                v-for="kpi in kpis"
                :key="kpi.key"
                type="button"
                @click="openDrill(kpi.label, kpi.display, kpi.breakdown)"
                class="group rounded-xl border border-gray-200 bg-white p-5 text-left shadow-sm transition hover:border-gray-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-500"
            >
                <div class="mb-4 flex items-start justify-between">
                    <div :class="['flex h-10 w-10 items-center justify-center rounded-lg', kpiTone[kpi.tone].wrap]">
                        <component :is="kpiTone[kpi.tone].icon" class="h-5 w-5" />
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-300 opacity-0 transition group-hover:opacity-100 dark:text-gray-500">
                        Details
                    </span>
                </div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ kpi.label }}</p>
                <p class="mt-1 text-4xl font-black tabular-nums leading-none text-gray-900 dark:text-gray-100">
                    {{ kpi.display }}
                </p>
                <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">{{ kpi.caption }}</p>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

            <!-- Portfolio performance + department bars -->
            <div
                v-if="shows('performance') || shows('departments')"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2"
            >
                <template v-if="shows('performance')">
                    <h3 class="text-base font-black text-gray-900 dark:text-gray-100">Portfolio Performance</h3>

                    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <button
                            v-for="tile in tiles"
                            :key="tile.label"
                            type="button"
                            @click="openDrill(tile.label, `${tile.value}%`, tile.breakdown)"
                            class="rounded-lg border border-gray-200 p-4 text-left transition hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:hover:border-gray-500 dark:hover:bg-gray-700/40"
                        >
                            <p class="text-3xl font-black tabular-nums leading-none text-gray-900 dark:text-gray-100">
                                {{ tile.value }}%
                            </p>
                            <p class="mt-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ tile.label }}</p>
                            <p class="mt-0.5 text-[10px] text-gray-400 dark:text-gray-500">{{ tile.caption }}</p>
                        </button>
                    </div>
                </template>

                <template v-if="shows('departments')">
                    <p class="mt-6 text-[10px] font-black uppercase tracking-widest text-gray-400">
                        Progress by department
                    </p>

                    <div v-if="departments.length" class="mt-3 space-y-1">
                        <button
                            v-for="dept in departments"
                            :key="dept.name"
                            type="button"
                            @click="openDrill(dept.name, `${dept.progress}%`, dept.breakdown)"
                            class="flex w-full items-center gap-3 rounded-lg px-1.5 py-1 text-left transition hover:bg-gray-50 dark:hover:bg-gray-700/40"
                        >
                            <span
                                class="w-40 shrink-0 truncate text-sm text-gray-700 dark:text-gray-300"
                                :title="`${dept.name} — ${dept.tasks} activities`"
                            >
                                {{ dept.name }}
                            </span>
                            <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :style="{ width: `${dept.progress}%`, backgroundColor: `var(--series-${slotFor(dept.name) + 1})` }"
                                />
                            </div>
                            <span class="w-12 shrink-0 text-right text-sm font-bold tabular-nums text-gray-600 dark:text-gray-300">
                                {{ dept.progress }}%
                            </span>
                        </button>
                    </div>

                    <p v-else class="mt-3 text-sm text-gray-400">
                        No activity has a department yet — set one on the activity template or assign the rows to a user.
                    </p>
                </template>
            </div>

            <div class="space-y-4">
                <!-- Project health -->
                <div
                    v-if="shows('health')"
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <h3 class="text-base font-black text-gray-900 dark:text-gray-100">Project Health</h3>

                    <div v-if="health.length" class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        <Link
                            v-for="project in health"
                            :key="project.id"
                            :href="route('projects.show', project.id)"
                            class="flex items-start justify-between gap-3 py-3 transition hover:opacity-70"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900 dark:text-gray-100">{{ project.name }}</p>
                                <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="project.location">{{ project.location }} · </span>
                                    {{ project.opens_label }} · {{ project.schedule }}
                                </p>
                            </div>
                            <span :class="['shrink-0 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ring-1', statePill[project.state]]">
                                {{ stateLabel[project.state] }}
                            </span>
                        </Link>
                    </div>

                    <p v-else class="mt-3 text-sm text-gray-400">No active projects of this type.</p>
                </div>

                <!-- Critical alerts -->
                <div
                    v-if="shows('alerts')"
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <h3 class="text-base font-black text-gray-900 dark:text-gray-100">Critical Alerts</h3>

                    <div v-if="alerts.length" class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        <Link
                            v-for="(alert, index) in alerts"
                            :key="`${alert.project_id}-${index}`"
                            :href="route('projects.show', alert.project_id)"
                            class="flex items-start justify-between gap-3 py-3 transition hover:opacity-70"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ alert.title }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ alert.detail }}</p>
                            </div>
                            <ChevronRightIcon class="h-4 w-4 shrink-0 text-gray-300" />
                        </Link>
                    </div>

                    <p v-else class="mt-3 text-sm text-gray-400">Nothing needs attention right now.</p>
                </div>
            </div>
        </div>

        <p v-if="!hasAnything" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400 dark:border-gray-600">
            No {{ type }} projects yet. Create one to populate this overview.
        </p>

        <!-- How this number was computed -->
        <Modal :show="drill !== null" max-width="4xl" @close="closeDrill">
            <div v-if="drill" class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">How this is computed</p>
                        <h3 class="mt-1 text-xl font-black text-gray-900 dark:text-gray-100">{{ drill.title }}</h3>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="text-3xl font-black tabular-nums leading-none text-gray-900 dark:text-gray-100">
                            {{ drill.value }}
                        </span>
                        <button
                            type="button"
                            @click="closeDrill"
                            class="rounded-full p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                        >
                            <XMarkIcon class="h-5 w-5" />
                        </button>
                    </div>
                </div>

                <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm leading-relaxed text-gray-600 dark:bg-gray-900/50 dark:text-gray-300">
                    {{ drill.formula }}
                </p>

                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    <div
                        v-for="item in drill.summary"
                        :key="item.label"
                        class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                    >
                        <p class="text-lg font-black tabular-nums leading-none text-gray-900 dark:text-gray-100">
                            {{ item.value }}
                        </p>
                        <p class="mt-1 text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ item.label }}</p>
                    </div>
                </div>

                <div v-if="drill.rows.length" class="mt-5 max-h-[45vh] overflow-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th
                                    v-for="column in drill.columns"
                                    :key="column"
                                    class="px-3 py-2 text-left text-[10px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                >
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="(row, index) in drill.rows" :key="index">
                                <td
                                    v-for="(cell, cellIndex) in row"
                                    :key="cellIndex"
                                    :class="[
                                        'px-3 py-2 text-gray-700 dark:text-gray-300',
                                        cellIndex === row.length - 1 ? 'whitespace-nowrap text-right tabular-nums font-semibold' : ''
                                    ]"
                                >
                                    {{ cell }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="mt-5 rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600">
                    Nothing to list — the rule above matched no rows.
                </p>

                <p v-if="drill.truncated" class="mt-2 text-xs text-gray-400">
                    Showing the first {{ drill.rows.length }} of {{ drill.total }} rows.
                </p>
            </div>
        </Modal>
    </div>
</template>

<style scoped>
/*
 * Categorical slots, validated against both surfaces with the palette checker.
 * The dark column is the same eight hues re-stepped for a dark surface, not a
 * flipped copy.
 */
.viz-root {
    --series-1: #2a78d6;
    --series-2: #eb6834;
    --series-3: #1baf7a;
    --series-4: #eda100;
    --series-5: #e87ba4;
    --series-6: #008300;
    --series-7: #4a3aa7;
    --series-8: #e34948;
}

:global(html.dark) .viz-root {
    --series-1: #3987e5;
    --series-2: #d95926;
    --series-3: #199e70;
    --series-4: #c98500;
    --series-5: #d55181;
    --series-6: #008300;
    --series-7: #9085e9;
    --series-8: #e66767;
}
</style>
