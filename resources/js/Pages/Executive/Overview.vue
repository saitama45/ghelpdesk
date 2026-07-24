<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    entity: { type: Object, default: () => ({ code: '—', name: 'Enterprise' }) },
    period: { type: String, default: '' },
    kpis: { type: Array, default: () => [] },
    pillars: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    portfolio: { type: Array, default: () => [] },
    agenda: { type: Array, default: () => [] },
    brandHealth: { type: Object, default: () => ({}) },
});

const page = usePage();
const accent = computed(() => page.props.departmentContext?.accent || '#253d5b');

const kpiTone = {
    teal: 'text-teal-600 dark:text-teal-400',
    blue: 'text-blue-600 dark:text-blue-400',
    green: 'text-emerald-600 dark:text-emerald-400',
    amber: 'text-amber-600 dark:text-amber-400',
    red: 'text-red-600 dark:text-red-400',
};
const dotTone = {
    green: 'bg-emerald-500', blue: 'bg-blue-500', amber: 'bg-amber-500', red: 'bg-red-500', teal: 'bg-teal-500',
};
const attainClass = (v) => v >= 92 ? 'bg-emerald-100 text-emerald-800' : v >= 89 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800';
const outlookClass = (o) => ({
    'On Track': 'bg-emerald-100 text-emerald-800',
    'Watch': 'bg-amber-100 text-amber-800',
    'Needs Attention': 'bg-red-100 text-red-800',
}[o] || 'bg-gray-100 text-gray-700');
</script>

<template>
    <AppLayout title="Executive" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <Head title="Executive Scorecard" />

        <div class="py-6 space-y-6">
            <!-- Hero -->
            <div class="flex flex-wrap items-end justify-between gap-3 rounded-2xl p-5 text-white" :style="{ background: 'linear-gradient(120deg,' + accent + ', #1f2d45)' }">
                <div>
                    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-white/70">{{ entity.code }} Executive Office</div>
                    <h1 class="mt-1 text-2xl font-black tracking-tight">{{ entity.name }} WIG Executive Scorecard</h1>
                    <p class="mt-1 max-w-3xl text-sm text-white/80">
                        Enterprise alignment and attainment across every department — outcomes, trends, and decisions requiring executive attention.
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-[10px] font-black uppercase tracking-widest text-white/60">Reporting period</div>
                    <div class="text-sm font-black">{{ period }}</div>
                </div>
            </div>

            <!-- Enterprise KPIs -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <div v-for="kpi in kpis" :key="kpi.label" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ kpi.label }}</span>
                        <span class="h-2 w-2 rounded-full" :class="dotTone[kpi.tone] || dotTone.blue"></span>
                    </div>
                    <div class="mt-1 text-2xl font-black" :class="kpiTone[kpi.tone] || 'text-gray-900 dark:text-white'">{{ kpi.value }}</div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400">{{ kpi.note }}</div>
                </div>
            </div>

            <!-- Empty state (entity with no departments) -->
            <div v-if="!departments.length" class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 text-center dark:border-gray-700 dark:bg-gray-900/40">
                <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ entity.name }} has no departments configured. Switch to an entity with departments (e.g. TGI) to see the scorecard.</p>
            </div>

            <template v-else>
                <!-- WIG Pillars -->
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: accent }">Enterprise WIG Pillars</div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div v-for="p in pillars" :key="p.name">
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs font-bold text-gray-700 dark:text-gray-200">{{ p.name }}</span>
                                <span class="text-lg font-black text-gray-900 dark:text-white">{{ p.value }}%</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full" :style="{ width: p.value + '%', backgroundColor: accent }"></div>
                            </div>
                            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ p.note }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <!-- Department WIG table -->
                    <div class="xl:col-span-2 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                            <div class="text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: accent }">Department WIG Alignment & Attainment</div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-gray-100 text-[10px] font-black uppercase tracking-wider text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                        <th class="px-4 py-2">Department</th>
                                        <th class="px-4 py-2">WIG</th>
                                        <th class="px-4 py-2">Strategic Area</th>
                                        <th class="px-4 py-2">Current Evidence</th>
                                        <th class="px-4 py-2">Outlook</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="d in departments" :key="d.id" class="border-b border-gray-50 hover:bg-gray-50 dark:border-gray-700/50 dark:hover:bg-gray-700/30">
                                        <td class="px-4 py-2">
                                            <span class="font-bold text-gray-900 dark:text-white">{{ d.code || d.name }}</span>
                                            <div class="text-[11px] text-gray-400">{{ d.name }}</div>
                                        </td>
                                        <td class="px-4 py-2"><span class="rounded px-1.5 py-0.5 text-[10px] font-black" :class="attainClass(d.attainment)">{{ d.attainment }}%</span></td>
                                        <td class="px-4 py-2">
                                            <div class="font-semibold text-gray-800 dark:text-gray-200">{{ d.strategic_area }}</div>
                                            <div class="text-[11px] text-gray-400">{{ d.key_result }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ d.evidence }}</td>
                                        <td class="px-4 py-2"><span class="rounded px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider" :class="outlookClass(d.outlook)">{{ d.outlook }}</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Side: Attention Agenda + Portfolio -->
                    <div class="space-y-4">
                        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                            <div class="border-b border-gray-100 px-4 py-3 text-[10px] font-black uppercase tracking-[0.18em] dark:border-gray-700" :style="{ color: accent }">President's Attention Agenda</div>
                            <ul class="divide-y divide-gray-50 dark:divide-gray-700/50">
                                <li v-for="a in agenda" :key="a.code" class="flex items-center gap-3 px-4 py-2.5">
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-black text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ a.code }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs text-gray-800 dark:text-gray-200">{{ a.item }}</span>
                                        <span class="text-[10px] text-gray-400">WIG · {{ a.attainment }}%</span>
                                    </span>
                                    <span class="rounded px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider" :class="a.status === 'Decision' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'">{{ a.status }}</span>
                                </li>
                                <li v-if="!agenda.length" class="px-4 py-6 text-center text-xs text-gray-400">All departments on track.</li>
                            </ul>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <div class="mb-3 text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: accent }">Strategic Portfolio Outlook</div>
                            <div class="grid grid-cols-2 gap-3">
                                <div v-for="p in portfolio" :key="p.label" class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full" :class="dotTone[p.tone]"></span>
                                    <span class="text-lg font-black text-gray-900 dark:text-white">{{ p.value }}</span>
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ p.label }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="text-[11px] text-gray-400 dark:text-gray-500">
                Enterprise view · <Link :href="route('dashboard')" class="font-bold text-blue-600 hover:underline dark:text-blue-400">Back to Dashboard</Link>
            </div>
        </div>
    </AppLayout>
</template>
