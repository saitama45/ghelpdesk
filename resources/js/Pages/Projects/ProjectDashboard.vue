<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    MagnifyingGlassIcon,
    ArrowPathIcon,
    TableCellsIcon,
    ArrowTrendingUpIcon,
    ArrowTrendingDownIcon,
    MinusSmallIcon,
} from '@heroicons/vue/24/outline';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';

const props = defineProps({
    dashboard: { type: Object, default: null },
    projectOptions: { type: Array, default: () => [] },
    projectTypes: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

/* ---------------------------------------------------------------- filters */

const types = ref([...(props.filters?.dash_types || [])]);
const selected = ref([...(props.filters?.dash_projects || [])]);
const from = ref(props.filters?.dash_from || '');
const to = ref(props.filters?.dash_to || '');
const loading = ref(false);

const typeOptions = computed(() => props.projectTypes.map((t) => ({ label: t, value: t })));

// Every project of the chosen type(s), whether it is run on the Task Board or only
// on /projects — the source shows in the row so nothing looks missing.
const dropdownOptions = computed(() =>
    props.projectOptions.map((p) => ({
        ...p,
        label: p.source === 'Projects' ? p.label : `${p.label}  ·  Task Board`,
    }))
);

const reload = (extra = {}) => {
    loading.value = true;
    router.reload({
        only: ['dashboard', 'dashboardProjectOptions', 'dashboardFilters'],
        data: {
            dash_from: from.value || undefined,
            dash_to: to.value || undefined,
            dash_types: types.value.length ? types.value : undefined,
            dash_projects: selected.value.length ? selected.value : undefined,
            ...extra,
        },
        onFinish: () => { loading.value = false; },
    });
};

// Changing the type changes which projects exist, so the picked ids are dropped
// rather than silently filtering the chart to an empty set.
const onTypesChanged = () => {
    selected.value = [];
    reload({ dash_projects: undefined });
};

const resetFilters = () => {
    types.value = [];
    selected.value = [];
    from.value = '';
    to.value = '';
    reload({ dash_from: undefined, dash_to: undefined, dash_types: undefined, dash_projects: undefined });
};

/* ------------------------------------------------------------ view toggles */

const style = ref('line');      // 'line' | 'bar'
const showTable = ref(false);
const showOverall = ref(true);

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
// Every project shares one chart, so the palette has to carry more than the six
// categorical slots a status breakdown needed. Twelve hues, each checked against
// both surfaces; past that they cycle and the legend plus the table carry identity.
const PALETTE = [
    { light: '#2a78d6', dark: '#3987e5' },
    { light: '#1baf7a', dark: '#199e70' },
    { light: '#eda100', dark: '#c98500' },
    { light: '#e34948', dark: '#e66767' },
    { light: '#4a3aa7', dark: '#9085e9' },
    { light: '#008300', dark: '#22a06b' },
    { light: '#c2410c', dark: '#fb923c' },
    { light: '#0e7490', dark: '#22d3ee' },
    { light: '#a21caf', dark: '#e879f9' },
    { light: '#4d7c0f', dark: '#a3e635' },
    { light: '#b91c1c', dark: '#f87171' },
    { light: '#5b21b6', dark: '#c4b5fd' },
];

const hue = (slot) => (isDark.value ? slot.dark : slot.light);

/* ------------------------------------------------------------------- data */

const weeks = computed(() => props.dashboard?.weeks || []);
const summary = computed(() => props.dashboard?.summary || {});

// One line per project — this is the whole chart, not a separate "per project" mode.
const projectSeries = computed(() =>
    (props.dashboard?.projects || []).map((p, i) => ({
        ...p,
        stroke: hue(PALETTE[i % PALETTE.length]),
    }))
);

const overallSeries = computed(() => {
    const o = props.dashboard?.overall;
    if (!o || !o.values?.length) return null;
    return { ...o, stroke: isDark.value ? '#ffffff' : '#0b0b0b', emphasis: true };
});

const activeSeries = computed(() => {
    const series = [...projectSeries.value];
    if (showOverall.value && overallSeries.value && series.length > 1) series.push(overallSeries.value);
    return series;
});

const hasData = computed(() => weeks.value.length > 0 && projectSeries.value.length > 0);

/* --------------------------------------------------------------- geometry */

const W = 1000;
const H = 380;
// Room on the left for the rotated axis title; the legend carries series identity,
// so the right edge only needs to clear the final marker.
const PAD = { top: 16, right: 28, bottom: 44, left: 64 };
const plotW = W - PAD.left - PAD.right;
const plotH = H - PAD.top - PAD.bottom;

// Lines use a point scale (first and last week sit on the axis ends). Bars need a
// band scale — on a point scale the opening and closing groups straddle the axis
// and spill over the % labels — so each week owns a slot and its bars centre in it.
const slotW = (count = weeks.value.length) => plotW / Math.max(1, count);

const xAt = (i, count = weeks.value.length) => {
    if (style.value === 'bar') {
        return PAD.left + slotW(count) * (i + 0.5);
    }

    return count <= 1 ? PAD.left + plotW / 2 : PAD.left + (i * plotW) / (count - 1);
};

// The scale floor sits below zero on purpose: a project flat at 0% would otherwise
// be drawn straight on the axis line and vanish. This lifts the 0% track clear of
// the baseline so those series stay visible.
const Y_MIN = -4;

const yAt = (v) => PAD.top + ((100 - v) / (100 - Y_MIN)) * plotH;

/** Where a 0% value sits — the baseline bars grow from. */
const zeroY = () => yAt(0);

const gridLines = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

/* ---------------------------------------------------------- curved lines */
// Catmull-Rom through the weekly points, converted to cubic beziers — the same
// eased line Chart.js draws at its default tension. Control points are clamped to
// the plot so an overshoot can never render above 100% or below 0%.
const TENSION = 0.42;

const clampY = (y) => Math.max(PAD.top, Math.min(zeroY(), y));

const smoothPath = (points) => {
    if (!points.length) return '';
    if (points.length === 1) return `M${points[0].x},${points[0].y}`;

    let d = `M${points[0].x},${points[0].y}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] || points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] || p2;

        const c1x = p1.x + ((p2.x - p0.x) / 6) * TENSION * 2;
        const c1y = clampY(p1.y + ((p2.y - p0.y) / 6) * TENSION * 2);
        const c2x = p2.x - ((p3.x - p1.x) / 6) * TENSION * 2;
        const c2y = clampY(p2.y - ((p3.y - p1.y) / 6) * TENSION * 2);

        d += ` C${c1x},${c1y} ${c2x},${c2y} ${p2.x},${p2.y}`;
    }

    return d;
};

// Nulls (weeks before the project had any activities) break the line into separate
// runs rather than reading as a genuine 0%.
const linePath = (series, count = weeks.value.length) => {
    const runs = [];
    let run = [];

    series.values.forEach((v, i) => {
        if (v === null || v === undefined) {
            if (run.length) runs.push(run);
            run = [];
            return;
        }
        run.push({ x: xAt(i, count), y: yAt(v) });
    });

    if (run.length) runs.push(run);

    return runs.map(smoothPath).join(' ');
};

// A quarter-long range packs more week labels than the axis can hold, so thin them
// to roughly one per 60px and always keep the closing week.
const visibleWeekIndexes = computed(() => {
    const n = weeks.value.length;
    const step = Math.max(1, Math.ceil(n / 13));
    const idx = [];

    for (let i = 0; i < n; i += step) idx.push(i);

    // The closing week always shows; drop the tick before it when they would crowd.
    const last = n - 1;
    if (last >= 0 && !idx.includes(last)) {
        if (last - idx[idx.length - 1] < step * 0.8) idx.pop();
        idx.push(last);
    }

    return new Set(idx);
});

/* ------------------------------------------------------------------- bars */

const BAR_GAP = 1; // surface gap between adjacent bars in a group

const barGeom = (seriesIndex, weekIndex, value) => {
    const n = activeSeries.value.length;
    const groupW = slotW() * 0.82;
    const barW = Math.max(1.5, (groupW - BAR_GAP * (n - 1)) / n);
    // Centre the group on its slot using the real drawn width, so rounding never
    // pushes the last bar past the slot.
    const drawnW = barW * n + BAR_GAP * (n - 1);
    const groupLeft = xAt(weekIndex) - drawnW / 2;
    const x = groupLeft + seriesIndex * (barW + BAR_GAP);
    const y = yAt(value);
    // Bars grow from the 0% track, not the scale floor, so a 0% bar is empty rather
    // than a misleading stub.
    return { x, y, w: barW, h: Math.max(0, zeroY() - y) };
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

// Legend order, so the tooltip reads the same way top-to-bottom as the key above it.
const tooltipRows = computed(() => {
    if (hoverIndex.value === null) return [];
    return activeSeries.value
        .map((s) => ({
            label: s.label,
            stroke: s.stroke,
            value: s.values[hoverIndex.value],
            delta: s.deltas?.[hoverIndex.value],
        }))
        .filter((r) => r.value !== null && r.value !== undefined);
});

// How many projects the Overall mean covers that week. Overall can dip purely
// because a project joined the cohort at 0%, so the count travels with the number.
const cohortAt = (index) => props.dashboard?.overall?.counts?.[index] ?? null;

const tooltipLeftPct = computed(() =>
    hoverIndex.value === null ? 0 : (xAt(hoverIndex.value) / W) * 100
);

const fmt = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(1)}%`);

const fmtDelta = (v) => {
    if (v === null || v === undefined) return '—';
    const n = Number(v);
    return `${n > 0 ? '+' : ''}${n.toFixed(1)}`;
};

const deltaClass = (v) => {
    if (v === null || v === undefined || Number(v) === 0) return 'text-gray-400 dark:text-gray-500';
    return Number(v) > 0
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-red-600 dark:text-red-400';
};
</script>

<template>
    <div class="space-y-4">
        <!-- Filters -->
        <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm xl:flex-row xl:items-end dark:border-gray-700 dark:bg-gray-800">
            <div class="min-w-0 xl:w-64">
                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Project Type</label>
                <MultiAutocomplete
                    v-model="types"
                    :options="typeOptions"
                    label-key="label"
                    value-key="value"
                    placeholder="All Project Types"
                    @update:modelValue="onTypesChanged"
                />
            </div>
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Projects</label>
                <MultiAutocomplete
                    v-model="selected"
                    :options="dropdownOptions"
                    label-key="label"
                    value-key="value"
                    placeholder="All projects in the selected type(s)"
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
                    @click="reload()"
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

        <!-- Headline numbers: what gets read out at ManCom -->
        <div v-if="hasData" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Overall Progress</p>
                <p class="mt-1 text-3xl font-black tabular-nums text-gray-900 dark:text-gray-100">{{ fmt(summary.overall_rate) }}</p>
                <p class="mt-1 text-[11px] leading-snug text-gray-400 dark:text-gray-500">
                    Average completion of the selected projects at the end of the last week in range.
                    Each project's own % is the mean of its activities.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">vs Last Week</p>
                <p :class="['mt-1 inline-flex items-center gap-1 text-3xl font-black tabular-nums', deltaClass(summary.last_week_change)]">
                    <ArrowTrendingUpIcon v-if="summary.last_week_change > 0" class="h-6 w-6" />
                    <ArrowTrendingDownIcon v-else-if="summary.last_week_change < 0" class="h-6 w-6" />
                    <MinusSmallIcon v-else class="h-6 w-6" />
                    {{ fmtDelta(summary.last_week_change) }}
                </p>
                <p class="mt-1 text-[11px] leading-snug text-gray-400 dark:text-gray-500">
                    Overall this week minus overall the week before — percentage points gained or lost in the last 7 days.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Movement in Range</p>
                <p :class="['mt-1 text-3xl font-black tabular-nums', deltaClass(summary.movement)]">{{ fmtDelta(summary.movement) }}</p>
                <p class="mt-1 text-[11px] leading-snug text-gray-400 dark:text-gray-500">
                    Overall at the last week minus the first, across the whole FROM–TO range.
                    Can read negative when a new project joins the mix at 0%.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Scope</p>
                <p class="mt-1 text-3xl font-black tabular-nums text-gray-900 dark:text-gray-100">{{ summary.project_count }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">project(s) over {{ summary.week_count }} week(s)</p>
                <p class="mt-1 text-[11px] leading-snug text-gray-400 dark:text-gray-500">
                    Projects matching the type and dropdown filters. Projects with no activities yet are listed below the chart title instead of plotted.
                </p>
            </div>
        </div>

        <!-- Chart card -->
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h3 class="text-lg font-black text-gray-900 dark:text-gray-100">Weekly Progress Comparison</h3>
                    <p v-if="summary.from" class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        {{ summary.from }} to {{ summary.to }} — {{ summary.project_count }} project(s)
                    </p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        One line per project. Each point is that project's completion at the week's end — the same
                        average-of-activities figure the project list shows — replayed from its recorded progress history.
                        Overall is the mean of the projects in flight that week, so it can dip when a new project joins at 0%.
                    </p>
                    <p v-if="summary.unmeasured?.length" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        Not plotted (no activities to measure yet): {{ summary.unmeasured.join(', ') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="projectSeries.length > 1"
                        type="button"
                        @click="showOverall = !showOverall"
                        :class="['rounded-lg border px-3 py-2 text-sm font-bold transition', showOverall ? 'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900' : 'border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700']"
                    >
                        Overall
                    </button>
                    <div class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700">
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
            <div v-if="!hasData" class="flex h-64 items-center justify-center px-6 text-center text-sm text-gray-400 dark:text-gray-500">
                {{ loading ? 'Loading…' : 'No projects match this filter, or none have activities to measure progress against.' }}
            </div>

            <template v-else>
                <!-- Legend: one entry per project -->
                <div v-if="activeSeries.length > 1" class="mt-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                    <span v-for="s in activeSeries" :key="s.key" class="inline-flex max-w-full items-center gap-2">
                        <span class="inline-block h-3 w-6 shrink-0 rounded-sm" :style="{ backgroundColor: s.stroke }" />
                        <span
                            :class="['truncate text-xs', s.emphasis ? 'font-black text-gray-900 dark:text-gray-100' : 'font-semibold text-gray-600 dark:text-gray-300']"
                            :title="s.label"
                        >{{ s.label }}</span>
                    </span>
                </div>

                <div class="relative mt-3 overflow-x-auto">
                    <svg
                        ref="svgRef"
                        :viewBox="`0 0 ${W} ${H}`"
                        class="w-full min-w-[640px]"
                        role="img"
                        aria-label="Weekly project progress comparison"
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

                        <!-- Y axis title -->
                        <text
                            :transform="`rotate(-90 16 ${PAD.top + plotH / 2})`"
                            :x="16" :y="PAD.top + plotH / 2"
                            text-anchor="middle"
                            class="fill-gray-500 text-[12px] font-semibold dark:fill-gray-400"
                        >Progress (%)</text>

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
                            <g v-for="(s, si) in activeSeries" :key="`bar-${s.key}`">
                                <path
                                    v-for="(v, wi) in s.values"
                                    v-show="v !== null && v !== undefined"
                                    :key="`bar-${s.key}-${wi}`"
                                    :d="barPath(barGeom(si, wi, v || 0))"
                                    :fill="s.stroke"
                                />
                            </g>
                        </template>

                        <!-- Lines -->
                        <template v-else>
                            <g v-for="s in activeSeries" :key="`line-${s.key}`">
                                <path
                                    :d="linePath(s)"
                                    fill="none"
                                    :stroke="s.stroke"
                                    :stroke-width="s.emphasis ? 3 : 2.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <!-- 2px surface ring keeps overlapping markers legible -->
                                <circle
                                    v-for="(v, wi) in s.values"
                                    v-show="v !== null && v !== undefined"
                                    :key="`pt-${s.key}-${wi}`"
                                    :cx="xAt(wi)" :cy="yAt(v || 0)"
                                    :r="hoverIndex === wi ? 5.5 : 4"
                                    :fill="s.stroke"
                                    class="stroke-white dark:stroke-gray-800"
                                    stroke-width="2"
                                />
                            </g>
                        </template>

                        <!-- x axis: thinned so long ranges stay readable -->
                        <template v-for="(w, i) in weeks" :key="`x-${i}`">
                            <text
                                v-if="visibleWeekIndexes.has(i)"
                                :x="xAt(i)" :y="H - 18"
                                text-anchor="middle"
                                class="fill-gray-500 text-[11px] dark:fill-gray-400"
                            >{{ w.label }}</text>
                            <line
                                v-else
                                :x1="xAt(i)" :x2="xAt(i)"
                                :y1="PAD.top + plotH" :y2="PAD.top + plotH + 4"
                                class="stroke-gray-300 dark:stroke-gray-600"
                                stroke-width="1"
                            />
                        </template>
                    </svg>

                    <!-- Tooltip: value plus the week-over-week move, which is the whole point -->
                    <div
                        v-if="hoverIndex !== null && tooltipRows.length"
                        class="pointer-events-none absolute top-8 z-10 max-h-80 w-72 -translate-x-1/2 overflow-hidden rounded-lg bg-gray-900/95 p-3 shadow-xl ring-1 ring-black/10 dark:bg-black/90"
                        :style="{ left: `min(max(${tooltipLeftPct}%, 9rem), calc(100% - 9rem))` }"
                    >
                        <p class="mb-2 text-xs font-black text-white">
                            {{ weeks[hoverIndex].label }}
                            <span v-if="cohortAt(hoverIndex) !== null" class="ml-1 font-semibold text-gray-400">
                                · {{ cohortAt(hoverIndex) }} in flight
                            </span>
                        </p>
                        <div v-for="row in tooltipRows" :key="row.label" class="flex items-center justify-between gap-3 py-0.5">
                            <span class="inline-flex min-w-0 items-center gap-1.5">
                                <span class="inline-block h-3 w-3 shrink-0 rounded-sm" :style="{ backgroundColor: row.stroke }" />
                                <span class="truncate text-[11px] text-gray-200">{{ row.label }}</span>
                            </span>
                            <span class="shrink-0 text-[11px] tabular-nums">
                                <span class="font-bold text-white">{{ fmt(row.value) }}</span>
                                <span
                                    :class="[
                                        'ml-1.5 font-semibold',
                                        row.delta > 0 ? 'text-emerald-400' : row.delta < 0 ? 'text-red-400' : 'text-gray-500',
                                    ]"
                                >{{ fmtDelta(row.delta) }}</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Table view: identity never rests on colour alone, and ManCom wants the numbers -->
                <div v-if="showTable" class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="sticky left-0 bg-white px-3 py-2 text-left text-[10px] font-black uppercase tracking-widest text-gray-500 dark:bg-gray-800 dark:text-gray-400">Project</th>
                                <th v-for="(w, i) in weeks" :key="`th-${i}`" class="px-3 py-2 text-right text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">
                                    {{ w.label }}
                                </th>
                                <th class="px-3 py-2 text-right text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-400">Movement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in projectSeries" :key="`row-${p.id}`" class="border-b border-gray-100 dark:border-gray-700/60">
                                <td class="sticky left-0 max-w-56 truncate bg-white px-3 py-2 font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300" :title="p.label">
                                    <span class="mr-1.5 inline-block h-2.5 w-2.5 rounded-sm align-middle" :style="{ backgroundColor: p.stroke }" />
                                    {{ p.label }}
                                </td>
                                <td v-for="(v, i) in p.values" :key="`td-${p.id}-${i}`" class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                    {{ fmt(v) }}
                                    <span :class="['ml-1 text-[10px] font-semibold', deltaClass(p.deltas?.[i])]">{{ fmtDelta(p.deltas?.[i]) }}</span>
                                </td>
                                <td :class="['px-3 py-2 text-right font-bold tabular-nums', deltaClass(p.movement)]">{{ fmtDelta(p.movement) }}</td>
                            </tr>
                            <tr v-if="overallSeries" class="border-t-2 border-gray-300 dark:border-gray-600">
                                <td class="sticky left-0 bg-white px-3 py-2 font-black text-gray-900 dark:bg-gray-800 dark:text-gray-100">Overall</td>
                                <td v-for="(v, i) in overallSeries.values" :key="`tdo-${i}`" class="px-3 py-2 text-right font-bold tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ fmt(v) }}
                                    <span :class="['ml-1 text-[10px] font-semibold', deltaClass(overallSeries.deltas?.[i])]">{{ fmtDelta(overallSeries.deltas?.[i]) }}</span>
                                </td>
                                <td :class="['px-3 py-2 text-right font-black tabular-nums', deltaClass(overallSeries.movement)]">{{ fmtDelta(overallSeries.movement) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>
</template>
