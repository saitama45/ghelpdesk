<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CalendarDaysIcon, ArrowTopRightOnSquareIcon, InboxStackIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    pipeline: { type: Object, default: () => ({}) },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['change-year']);

const months = computed(() => props.pipeline?.months || []);
const standby = computed(() => props.pipeline?.standby || []);
const statusLegend = computed(() => props.pipeline?.statusLegend || []);
const totals = computed(() => props.pipeline?.totals || { total: 0, dated: 0, standby: 0 });
const availableYears = computed(() => props.pipeline?.availableYears || []);
const year = computed(() => props.pipeline?.year);

const currentYear = new Date().getFullYear();
const currentMonth = new Date().getMonth() + 1;
const isCurrentMonth = (m) => year.value === currentYear && m === currentMonth;

// Status → color system. Falls back to indigo for any unmapped status.
const STATUS_STYLES = {
    'In Progress': { dot: 'bg-blue-500', bar: 'border-blue-500', chip: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', ring: 'ring-blue-500/20' },
    'Delayed':     { dot: 'bg-rose-500', bar: 'border-rose-500', chip: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', ring: 'ring-rose-500/20' },
    'Completed':   { dot: 'bg-emerald-500', bar: 'border-emerald-500', chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', ring: 'ring-emerald-500/20' },
    'Planning':    { dot: 'bg-slate-400', bar: 'border-slate-400', chip: 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300', ring: 'ring-slate-400/20' },
    'Pending':     { dot: 'bg-amber-500', bar: 'border-amber-500', chip: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', ring: 'ring-amber-500/20' },
    'Cancelled':   { dot: 'bg-gray-400', bar: 'border-gray-300', chip: 'bg-gray-100 text-gray-500 dark:bg-gray-600/20 dark:text-gray-400', ring: 'ring-gray-400/20' },
};
const FALLBACK_STYLE = { dot: 'bg-indigo-500', bar: 'border-indigo-500', chip: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300', ring: 'ring-indigo-500/20' };
const styleFor = (status) => STATUS_STYLES[status] || FALLBACK_STYLE;

const onYearChange = (e) => emit('change-year', parseInt(e.target.value, 10));
</script>

<template>
    <div class="relative">
        <!-- Loading overlay -->
        <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 backdrop-blur-sm rounded-2xl dark:bg-gray-900/60">
            <span class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></span>
        </div>

        <!-- Header -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-3">
                <div class="flex items-center justify-center w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 text-white shadow-lg shadow-blue-500/20">
                    <CalendarDaysIcon class="w-6 h-6" />
                </div>
                <div>
                    <h3 class="text-lg font-black text-gray-900 dark:text-gray-100 leading-tight">CASA Pipeline</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Projects placed by target go-live date &middot; {{ year }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <!-- Summary chips -->
                <div class="hidden sm:flex items-center gap-2">
                    <div class="px-3 py-1.5 rounded-xl bg-gray-50 border border-gray-100 text-center dark:bg-gray-800 dark:border-gray-700">
                        <div class="text-base font-black text-gray-900 dark:text-gray-100 leading-none">{{ totals.dated }}</div>
                        <div class="text-[9px] font-bold uppercase tracking-widest text-gray-400 mt-0.5">Scheduled</div>
                    </div>
                    <div class="px-3 py-1.5 rounded-xl bg-gray-50 border border-gray-100 text-center dark:bg-gray-800 dark:border-gray-700">
                        <div class="text-base font-black text-gray-900 dark:text-gray-100 leading-none">{{ totals.standby }}</div>
                        <div class="text-[9px] font-bold uppercase tracking-widest text-gray-400 mt-0.5">Standby</div>
                    </div>
                </div>

                <!-- Year selector -->
                <div class="relative">
                    <select
                        :value="year"
                        @change="onYearChange"
                        class="appearance-none rounded-xl border-gray-200 bg-white pl-4 pr-9 py-2.5 text-sm font-black text-gray-800 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100"
                    >
                        <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </div>
            </div>
        </div>

        <!-- Status legend -->
        <div v-if="statusLegend.length" class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-5">
            <div v-for="s in statusLegend" :key="s.status" class="flex items-center gap-1.5">
                <span :class="['w-2.5 h-2.5 rounded-full', styleFor(s.status).dot]"></span>
                <span class="text-xs font-bold text-gray-600 dark:text-gray-300">{{ s.status }}</span>
                <span class="text-[10px] font-black text-gray-400">{{ s.count }}</span>
            </div>
        </div>

        <div class="flex flex-col xl:flex-row gap-4">
            <!-- Standby panel -->
            <div class="xl:w-56 shrink-0">
                <div class="h-full rounded-2xl border border-dashed border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-800/40">
                    <div class="flex items-center gap-1.5 mb-3 px-1">
                        <InboxStackIcon class="w-4 h-4 text-gray-400" />
                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-400">Standby</span>
                        <span class="ml-auto text-[10px] font-black text-gray-400">{{ standby.length }}</span>
                    </div>
                    <div v-if="standby.length" class="flex flex-col gap-2 xl:max-h-[560px] xl:overflow-y-auto pr-0.5">
                        <Link
                            v-for="card in standby"
                            :key="card.id"
                            :href="card.url"
                            :class="['group block rounded-lg border-l-4 bg-white px-2.5 py-2 shadow-sm hover:shadow-md transition-shadow dark:bg-gray-800', styleFor(card.status).bar]"
                        >
                            <div class="flex items-start justify-between gap-1">
                                <span class="text-xs font-black text-gray-900 dark:text-gray-100 line-clamp-1">{{ card.label }}</span>
                                <ArrowTopRightOnSquareIcon class="w-3 h-3 text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity shrink-0 mt-0.5" />
                            </div>
                            <div class="text-[10px] text-gray-500 dark:text-gray-400 line-clamp-1">{{ card.store_name || card.name }}</div>
                            <span :class="['mt-1 inline-block rounded px-1.5 py-0.5 text-[9px] font-bold', styleFor(card.status).chip]">{{ card.status }}</span>
                        </Link>
                    </div>
                    <div v-else class="px-1 py-6 text-center text-[11px] text-gray-400 italic">No unscheduled projects.</div>
                </div>
            </div>

            <!-- Month timeline (horizontally scrollable) -->
            <div class="flex-1 min-w-0 overflow-x-auto pb-2">
                <div class="flex items-stretch gap-3 min-w-[900px]">
                    <div
                        v-for="m in months"
                        :key="m.month"
                        class="flex-1 min-w-[130px] flex flex-col"
                    >
                        <!-- Cards stack (grow upward toward the axis) -->
                        <div class="flex-1 flex flex-col justify-end gap-2 pb-3">
                            <Link
                                v-for="card in m.cards"
                                :key="card.id"
                                :href="card.url"
                                :class="['group block rounded-lg border-l-4 bg-white px-2.5 py-2 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all dark:bg-gray-800', styleFor(card.status).bar]"
                            >
                                <div class="flex items-start justify-between gap-1">
                                    <span class="text-xs font-black text-gray-900 dark:text-gray-100 line-clamp-1">{{ card.label }}</span>
                                    <span :class="['w-2 h-2 rounded-full shrink-0 mt-1', styleFor(card.status).dot]"></span>
                                </div>
                                <div class="text-[10px] text-gray-500 dark:text-gray-400 line-clamp-1">{{ card.store_name || card.name }}</div>
                                <div class="mt-1 flex items-center gap-1 text-[10px] font-bold text-gray-400">
                                    <CalendarDaysIcon class="w-3 h-3" />
                                    <span>{{ card.go_live_label }}</span>
                                </div>
                            </Link>
                        </div>

                        <!-- Axis node + month label -->
                        <div class="relative flex flex-col items-center">
                            <div class="absolute top-[7px] left-0 right-0 h-0.5 bg-gradient-to-r from-gray-200 via-gray-200 to-gray-200 dark:from-gray-700 dark:via-gray-700 dark:to-gray-700"></div>
                            <span
                                :class="[
                                    'relative z-[1] w-3.5 h-3.5 rounded-full border-2 border-white shadow dark:border-gray-900',
                                    m.cards.length ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600',
                                    isCurrentMonth(m.month) ? 'ring-4 ring-blue-500/25' : ''
                                ]"
                            ></span>
                            <span
                                :class="[
                                    'mt-2 text-[11px] font-black uppercase tracking-wider',
                                    isCurrentMonth(m.month) ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500'
                                ]"
                            >{{ m.label }}</span>
                            <span v-if="m.cards.length" class="text-[9px] font-bold text-gray-300 dark:text-gray-600">{{ m.cards.length }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="totals.total === 0 && !loading" class="mt-8 rounded-2xl border border-dashed border-gray-200 py-16 text-center dark:border-gray-700">
            <CalendarDaysIcon class="w-10 h-10 mx-auto text-gray-300 mb-3" />
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">No projects scheduled for {{ year }}.</p>
            <p class="text-xs text-gray-400 mt-1">Set a target go-live date on a project to see it here.</p>
        </div>
    </div>
</template>
