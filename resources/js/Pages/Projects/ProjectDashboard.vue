<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    MagnifyingGlassIcon,
    ArrowPathIcon,
    TableCellsIcon,
} from '@heroicons/vue/24/outline';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';

const props = defineProps({
    dashboard: { type: Object, default: null },
    projectTypes: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

/* ---------------------------------------------------------------- filters */

const types = ref([...(props.filters?.dash_types || [])]);
const from = ref(props.filters?.dash_from || '');
const to = ref(props.filters?.dash_to || '');
const loading = ref(false);

const typeOptions = computed(() => props.projectTypes.map((t) => ({ label: t, value: t })));

const reload = () => {
    loading.value = true;
    router.reload({
        only: ['dashboard', 'dashboardFilters'],
        data: {
            dash_from: from.value || undefined,
            dash_to: to.value || undefined,
            dash_types: types.value.length ? types.value : undefined,
        },
        onFinish: () => { loading.value = false; },
    });
};

const resetFilters = () => {
    types.value = [];
    from.value = '';
    to.value = '';
    reload();
};

/* ------------------------------------------------------------ view toggles */

const mode = ref('combined');   // 'combined' | 'per_project'
const style = ref('line');      // 'line' | 'bar'
const showTable = ref(false);

/* -------------------------------------------------------------- dark mode */
// Tailwind here uses the class strategy, so prefers-color-scheme would miss the
// in-app theme toggle. Watch the root class instead.
const isDark = ref(false);
let observer = null;

const syncDark = () => {
    isDark.value = document.documentElement.classList.contains('dark');
};

onMounted(() => {
    syncDark();
    observer = new MutationObserver(syncDark);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

onUnmounted(() => observer?.disconnect());

/* ---------------------------------------------------------------- palette */
// Categorical slots 1-6, validated for both surfaces. Assigned in fixed order,
// never cycled: past slot 6 we switch to small multiples instead of inventing hues.
const PALETTE = [
    { light: '#2a78d6', dark: '#3987e5' },
    { light: '#1baf7a', dark: '#199e70' },
    { light: '#eda100', dark: '#c98500' },
    { light: '#008300', dark: '#008300' },
    { light: '#4a3aa7', dark: '#9085e9' },
    { light: '#e34948', dark: '#e66767' },
];

const hue = (slot) => (isDark.value ? slot.dark : slot.light);

/* ------------------------------------------------------------------- data */

const weeks = computed(() => props.dashboard?.weeks || []);
const summary = computed(() => props.dashboard?.summary || {});
const projectSeries = computed(() => props.dashboard?.projects || []);

const overallSeries = computed(() => {
    const o = props.dashboard?.overall;
    if (!o) return null;
    return { ...o, stroke: isDark.value ? '#ffffff' : '#0b0b0b', emphasis: true };
});

const combinedSeries = computed(() => {
    const series = (props.dashboard?.series || []).map((s) => ({
        ...s,
        stroke: isDark.value ? s.dark : s.color,
    }));
    return overallSeries.value ? [...series, overallSeries.value] : series;
});

// Per-project mode overlays one line per project while the palette lasts.
const useSmallMultiples = computed(() => mode.value === 'per_project' && projectSeries.value.length > PALETTE.length);

const perProjectSeries = computed(() =>
    projectSeries.value.map((p, i) => ({
        ...p,
        key: `project-${p.id}`,
        stroke: hue(PALETTE[i % PALETTE.length]),
    }))
);

const activeSeries = computed(() => (mode.value === 'combined' ? combinedSeries.value : perProjectSeries.value));

const hasData = computed(() => weeks.value.length > 0 && activeSeries.value.length > 0);

/* --------------------------------------------------------------- geometry */

const W = 1000;
const H = 380;
const PAD = { top: 16, right: 168, bottom: 44, left: 52 };
const plotW = W - PAD.left - PAD.right;
const plotH = H - PAD.top - PAD.bottom;

const xAt = (i, count = weeks.value.length) =>
    count <= 1 ? PAD.left + plotW / 2 : PAD.left + (i * plotW) / (count - 1);

const yAt = (v) => PAD.top + ((100 - v) / 100) * plotH;

const gridLines = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

// Nulls (a milestone no project has set) break the line rather than reading as 0.
const pointsFor = (series, count = weeks.value.length) =>
    series.values
        .map((v, i) => (v === null || v === undefined ? null : `${xAt(i, count)},${yAt(v)}`))
        .filter(Boolean)
        .join(' ');

const lastPoint = (series) => {
    for (let i = series.values.length - 1; i >= 0; i--) {
        const v = series.values[i];
        if (v !== null && v !== undefined) return { i, v };
    }
    return null;
};

/* ------------------------------------------------------------------- bars */

const barGeom = (seriesIndex, weekIndex, value) => {
    const n = activeSeries.value.length;
    const slotW = weeks.value.length <= 1 ? plotW / 2 : plotW / weeks.value.length;
    const groupW = slotW * 0.72;
    const barW = Math.max(2, groupW / n - 2); // 2px surface gap between adjacent bars
    const groupLeft = xAt(weekIndex) - groupW / 2;
    const x = groupLeft + seriesIndex * (barW + 2);
    const y = yAt(value);
    return { x, y, w: barW, h: Math.max(0, PAD.top + plotH - y) };
};

// 4px rounded data-end, square where it meets the baseline.
const barPath = ({ x, y, w, h }) => {
    const r = Math.min(4, w / 2, h);
    if (h <= 0) return '';
    return `M${x},${y + h} L${x},${y + r} Q${x},${y} ${x + r},${y} L${x + w - r},${y} Q${x + w},${y} ${x + w},${y + r} L${x + w},${y + h} Z`;
};

/* ---------------------------------------------------------------- tooltip */

const hoverIndex = ref(null);
const svgRef = ref(null);

const onMove = (event) => {
    if (!weeks.value.length || !svgRef.value) return;
    const rect = svgRef.value.getBoundingClientRect();
    const px = ((event.clientX - rect.left) / rect.width) * W;
    if (px < PAD.left - 20 || px > W - PAD.right + 20) {
        hoverIndex.value = null;
        return;
    }
    let best = 0;
    let bestDist = Infinity;
    weeks.value.forEach((_, i) => {
        const d = Math.abs(xAt(i) - px);
        if (d < bestDist) { bestDist = d; best = i; }
    });
    hoverIndex.value = best;
};

const tooltipRows = computed(() => {
    if (hoverIndex.value === null) return [];
    return activeSeries.value
        .map((s) => ({
            label: s.label,
            stroke: s.stroke,
            value: s.values[hoverIndex.value],
        }))
        .filter((r) => r.value !== null && r.value !== undefined)
        .sort((a, b) => b.value - a.value);
});

const tooltipLeftPct = computed(() =>
    hoverIndex.value === null ? 0 : (xAt(hoverIndex.value) / W) * 100
);

const fmt = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(1)}%`);

/* --------------------------------------------- small-multiple mini charts */

const MW = 320;
const MH = 120;
const MPAD = { top: 10, right: 10, bottom: 18, left: 26 };
const mPlotW = MW - MPAD.left - MPAD.right;
const mPlotH = MH - MPAD.top - MPAD.bottom;

const miniPoints = (series) => {
    const n = weeks.value.length;
    return series.values
        .map((v, i) => {
            if (v === null || v === undefined) return null;
            const x = n <= 1 ? MPAD.left + mPlotW / 2 : MPAD.left + (i * mPlotW) / (n - 1);
            const y = MPAD.top + ((100 - v) / 100) * mPlotH;
            return `${x},${y}`;
        })
        .filter(Boolean)
        .join(' ');
};
</script>

<template>
    <div class="space-y-4">
        <!-- Filters: one row above the chart -->
        <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm lg:flex-row lg:items-end dark:border-gray-700 dark:bg-gray-800">
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Project Type</label>
                <MultiAutocomplete
                    v-model="types"
                    :options="typeOptions"
                    label-key="label"
                    value-key="value"
                    placeholder="All Project Types"
                />
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">From</label>
                <input v-model="from" type="date" class="h-[42px] rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">To</label>
                <input v-model="to" type="date" class="h-[42px] rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    @click="reload"
                    :disabled="loading"
                    class="inline-flex h-[42px] items-center gap-2 rounded-lg bg-gray-900 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-gray-800 disabled:opacity-60 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
                >
                    <MagnifyingGlassIcon class="h-4 w-4" />
                    {{ loading ? 'Loading…' : 'Apply' }}
                </button>
                <button
                    type="button"
                    @click="resetFilters"
                    class="inline-flex h-[42px] items-center gap-2 rounded-lg border border-gray-300 px-4 text-sm font-bold text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    <ArrowPathIcon class="h-4 w-4" />
                    Reset
                </button>
            </div>
        </div>

        <!-- Chart card -->
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h3 class="text-lg font-black text-gray-900 dark:text-gray-100">Weekly Milestone Completion</h3>
                    <p v-if="summary.from" class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        {{ summary.from }} to {{ summary.to }} — {{ summary.project_count }} project(s) across
                        {{ summary.type_count }} type(s) — Overall {{ fmt(summary.overall_rate) }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        A milestone counts as reached in a week when its planned date falls on or before that week's end.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700">
                        <button type="button" @click="mode = 'combined'" :class="['rounded-md px-3 py-1.5 text-sm font-bold transition', mode === 'combined' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700']">Combined</button>
                        <button type="button" @click="mode = 'per_project'" :class="['rounded-md px-3 py-1.5 text-sm font-bold transition', mode === 'per_project' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700']">Per Project</button>
                    </div>
                    <div v-if="!useSmallMultiples" class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700">
                        <button type="button" @click="style = 'line'" :class="['rounded-md px-3 py-1.5 text-sm font-bold transition', style === 'line' ? 'bg-blue-600 text-white' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700']">Line</button>
                        <button type="button" @click="style = 'bar'" :class="['rounded-md px-3 py-1.5 text-sm font-bold transition', style === 'bar' ? 'bg-blue-600 text-white' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700']">Bar</button>
                    </div>
                    <button
                        type="button"
                        @click="showTable = !showTable"
                        :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-bold transition', showTable ? 'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900' : 'border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700']"
                    >
                        <TableCellsIcon class="h-4 w-4" />
                        Table
                    </button>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!hasData" class="flex h-64 items-center justify-center text-sm text-gray-400 dark:text-gray-500">
                {{ loading ? 'Loading…' : 'No projects with milestone dates in this range.' }}
            </div>

            <template v-else>
                <!-- Legend: always present for >= 2 series -->
                <div v-if="!useSmallMultiples && activeSeries.length > 1" class="mt-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                    <span v-for="s in activeSeries" :key="s.key || s.id" class="inline-flex items-center gap-2">
                        <span class="inline-block h-3 w-6 rounded-sm" :style="{ backgroundColor: s.stroke }" />
                        <span :class="['text-xs', s.emphasis ? 'font-black text-gray-900 dark:text-gray-100' : 'font-semibold text-gray-600 dark:text-gray-300']">{{ s.label }}</span>
                    </span>
                </div>

                <!-- Overlaid chart -->
                <div v-if="!useSmallMultiples" class="relative mt-3 overflow-x-auto">
                    <svg
                        ref="svgRef"
                        :viewBox="`0 0 ${W} ${H}`"
                        class="w-full min-w-[640px]"
                        role="img"
                        aria-label="Weekly milestone completion rate"
                        @mousemove="onMove"
                        @mouseleave="hoverIndex = null"
                    >
                        <!-- Recessive grid -->
                        <g>
                            <line
                                v-for="g in gridLines"
                                :key="`g-${g}`"
                                :x1="PAD.left" :x2="W - PAD.right"
                                :y1="yAt(g)" :y2="yAt(g)"
                                class="stroke-gray-100 dark:stroke-gray-700"
                                stroke-width="1"
                            />
                            <text
                                v-for="g in gridLines"
                                :key="`gl-${g}`"
                                :x="PAD.left - 10" :y="yAt(g) + 4"
                                text-anchor="end"
                                class="fill-gray-400 text-[11px] tabular-nums dark:fill-gray-500"
                            >{{ g }}%</text>
                        </g>

                        <!-- Continuous axis line -->
                        <line :x1="PAD.left" :x2="PAD.left" :y1="PAD.top" :y2="PAD.top + plotH" class="stroke-gray-300 dark:stroke-gray-600" stroke-width="1" />
                        <line :x1="PAD.left" :x2="W - PAD.right" :y1="PAD.top + plotH" :y2="PAD.top + plotH" class="stroke-gray-300 dark:stroke-gray-600" stroke-width="1" />

                        <!-- Crosshair -->
                        <line
                            v-if="hoverIndex !== null"
                            :x1="xAt(hoverIndex)" :x2="xAt(hoverIndex)"
                            :y1="PAD.top" :y2="PAD.top + plotH"
                            class="stroke-gray-300 dark:stroke-gray-600"
                            stroke-width="1"
                            stroke-dasharray="4 4"
                        />

                        <!-- Bars -->
                        <template v-if="style === 'bar'">
                            <g v-for="(s, si) in activeSeries" :key="`bar-${s.key || s.id}`">
                                <path
                                    v-for="(v, wi) in s.values"
                                    v-show="v !== null && v !== undefined"
                                    :key="`bar-${s.key || s.id}-${wi}`"
                                    :d="barPath(barGeom(si, wi, v || 0))"
                                    :fill="s.stroke"
                                />
                            </g>
                        </template>

                        <!-- Lines -->
                        <template v-else>
                            <g v-for="s in activeSeries" :key="`line-${s.key || s.id}`">
                                <polyline
                                    :points="pointsFor(s)"
                                    fill="none"
                                    :stroke="s.stroke"
                                    :stroke-width="s.emphasis ? 3 : 2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <!-- 2px surface ring keeps overlapping markers legible -->
                                <circle
                                    v-for="(v, wi) in s.values"
                                    v-show="v !== null && v !== undefined"
                                    :key="`pt-${s.key || s.id}-${wi}`"
                                    :cx="xAt(wi)" :cy="yAt(v || 0)"
                                    :r="hoverIndex === wi ? 5.5 : 4"
                                    :fill="s.stroke"
                                    class="stroke-white dark:stroke-gray-800"
                                    stroke-width="2"
                                />
                            </g>
                        </template>

                        <!-- Direct end labels (the relief for low-contrast slots) -->
                        <g v-for="s in activeSeries" :key="`lbl-${s.key || s.id}`">
                            <template v-if="lastPoint(s)">
                                <circle :cx="W - PAD.right + 14" :cy="yAt(lastPoint(s).v)" r="4" :fill="s.stroke" />
                                <text
                                    :x="W - PAD.right + 24"
                                    :y="yAt(lastPoint(s).v) + 4"
                                    :class="['text-[11px]', s.emphasis ? 'fill-gray-900 font-bold dark:fill-gray-100' : 'fill-gray-500 dark:fill-gray-400']"
                                >{{ s.label.length > 16 ? s.label.slice(0, 15) + '…' : s.label }}</text>
                            </template>
                        </g>

                        <!-- x axis -->
                        <text
                            v-for="(w, i) in weeks"
                            :key="`x-${i}`"
                            :x="xAt(i)" :y="H - 18"
                            text-anchor="middle"
                            class="fill-gray-500 text-[11px] dark:fill-gray-400"
                        >{{ w.label }}</text>
                    </svg>

                    <!-- Tooltip -->
                    <div
                        v-if="hoverIndex !== null && tooltipRows.length"
                        class="pointer-events-none absolute top-2 z-10 w-56 -translate-x-1/2 rounded-lg border border-gray-200 bg-white p-3 shadow-xl dark:border-gray-700 dark:bg-gray-900"
                        :style="{ left: `min(max(${tooltipLeftPct}%, 7rem), calc(100% - 7rem))` }"
                    >
                        <p class="mb-2 text-xs font-black text-gray-900 dark:text-gray-100">{{ weeks[hoverIndex].label }}</p>
                        <div v-for="row in tooltipRows" :key="row.label" class="flex items-center justify-between gap-3 py-0.5">
                            <span class="inline-flex min-w-0 items-center gap-1.5">
                                <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: row.stroke }" />
                                <span class="truncate text-[11px] text-gray-600 dark:text-gray-300">{{ row.label }}</span>
                            </span>
                            <span class="shrink-0 text-[11px] font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ fmt(row.value) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Small multiples: past 6 projects a 7th hue would be invented, so split instead -->
                <div v-else class="mt-4">
                    <p class="mb-3 text-xs text-gray-400 dark:text-gray-500">
                        {{ projectSeries.length }} projects — shown as small multiples so each keeps its own readable line.
                        Narrow the Project Type filter to {{ PALETTE.length }} projects or fewer to overlay them on one chart.
                    </p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div v-for="p in projectSeries" :key="p.id" class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                            <div class="mb-1 flex items-baseline justify-between gap-2">
                                <p class="truncate text-xs font-bold text-gray-700 dark:text-gray-200" :title="p.label">{{ p.label }}</p>
                                <p class="shrink-0 text-xs font-black tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ fmt(lastPoint(p)?.v) }}
                                </p>
                            </div>
                            <p v-if="p.context || p.type" class="mb-1 truncate text-[10px] text-gray-400 dark:text-gray-500">
                                {{ [p.type, p.context].filter(Boolean).join(' · ') }}
                            </p>
                            <svg :viewBox="`0 0 ${MW} ${MH}`" class="w-full" role="img" :aria-label="`${p.label} completion`">
                                <line
                                    v-for="g in [0, 50, 100]"
                                    :key="`mg-${p.id}-${g}`"
                                    :x1="MPAD.left" :x2="MW - MPAD.right"
                                    :y1="MPAD.top + ((100 - g) / 100) * mPlotH"
                                    :y2="MPAD.top + ((100 - g) / 100) * mPlotH"
                                    class="stroke-gray-100 dark:stroke-gray-700"
                                    stroke-width="1"
                                />
                                <text
                                    v-for="g in [0, 100]"
                                    :key="`mgl-${p.id}-${g}`"
                                    :x="MPAD.left - 5"
                                    :y="MPAD.top + ((100 - g) / 100) * mPlotH + 3"
                                    text-anchor="end"
                                    class="fill-gray-400 text-[8px] tabular-nums dark:fill-gray-500"
                                >{{ g }}</text>
                                <polyline
                                    :points="miniPoints(p)"
                                    fill="none"
                                    :stroke="hue(PALETTE[0])"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Table view: identity never rests on colour alone -->
                <div v-if="showTable && !useSmallMultiples" class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Week</th>
                                <th v-for="s in activeSeries" :key="`th-${s.key || s.id}`" class="px-3 py-2 text-right text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">
                                    {{ s.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(w, i) in weeks" :key="`tr-${i}`" class="border-b border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-300">{{ w.label }}</td>
                                <td v-for="s in activeSeries" :key="`td-${s.key || s.id}-${i}`" class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                    {{ fmt(s.values[i]) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>
</template>
