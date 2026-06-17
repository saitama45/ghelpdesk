<template>
    <AppLayout title="WIGS" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6 space-y-5">

            <!-- ── Hero Header ──────────────────────────────────────────── -->
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-blue-700 via-blue-600 to-indigo-600 shadow-xl">
                <!-- dot-grid texture -->
                <div class="pointer-events-none absolute inset-0 opacity-[0.07]"
                     style="background-image:radial-gradient(circle,#fff 1px,transparent 1px);background-size:24px 24px"></div>
                <!-- bottom-right decorative circle -->
                <div class="pointer-events-none absolute -right-16 -bottom-16 w-64 h-64 rounded-full bg-white/5"></div>
                <div class="pointer-events-none absolute -right-6 -bottom-6 w-40 h-40 rounded-full bg-white/5"></div>

                <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-5 px-8 py-8">
                    <div>
                        <span class="inline-block mb-2.5 bg-white/20 text-white/90 text-[11px] font-black px-3 py-1 rounded-full uppercase tracking-[0.15em]">
                            Monitoring Module
                        </span>
                        <h1 class="text-3xl font-black text-white tracking-tight leading-tight">
                            Wildly Important Goals
                        </h1>
                        <p class="text-blue-200 text-sm mt-1.5 font-medium">
                            Yardstick &nbsp;·&nbsp; Performance Commitment (PCF) &nbsp;·&nbsp; Performance Appraisal (PAF)
                        </p>
                    </div>

                    <!-- Year picker -->
                    <div class="flex items-center gap-3 self-start md:self-auto
                                bg-white/15 backdrop-blur-sm border border-white/25
                                rounded-2xl px-5 py-3.5 min-w-[160px]">
                        <svg class="w-4 h-4 text-white/60 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <div>
                            <p class="text-[10px] font-black text-white/60 uppercase tracking-widest leading-none mb-1">Fiscal Year</p>
                            <input v-model.number="year" type="number" min="2000" max="2100"
                                   @change="applyYear"
                                   class="w-full bg-transparent border-0 border-b border-white/40 text-white font-black text-xl
                                          text-center focus:outline-none focus:border-white/80 pb-0.5 transition-colors
                                          [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"/>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Tab Cards ────────────────────────────────────────────── -->
            <div class="grid grid-cols-3 gap-3">
                <button v-for="t in tabs" :key="t.key" @click="activeTab = t.key"
                        class="group relative rounded-2xl border-2 text-left px-5 py-4 transition-all duration-200 focus:outline-none overflow-hidden"
                        :class="activeTab === t.key
                            ? 'border-blue-600 bg-gradient-to-br from-blue-600 to-indigo-600 shadow-lg shadow-blue-400/30'
                            : 'border-gray-200 bg-white hover:border-blue-300 hover:shadow-md hover:-translate-y-0.5'">

                    <!-- shimmer gloss on active -->
                    <div v-if="activeTab === t.key"
                         class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/10 via-white/0 to-transparent"></div>

                    <div class="relative flex items-start gap-3.5">
                        <!-- icon pill -->
                        <div class="flex-shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors"
                             :class="activeTab === t.key ? 'bg-white/20' : 'bg-blue-50 group-hover:bg-blue-100'">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                 :class="activeTab === t.key ? 'text-white' : 'text-blue-600'">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="t.icon"/>
                            </svg>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-black tracking-wide"
                                   :class="activeTab === t.key ? 'text-white' : 'text-gray-800'">
                                    {{ t.label }}
                                </p>
                                <span v-if="t.count != null"
                                      class="flex-shrink-0 text-[10px] font-black px-2 py-0.5 rounded-full tabular-nums"
                                      :class="activeTab === t.key
                                          ? 'bg-white/25 text-white'
                                          : 'bg-blue-100 text-blue-700'">
                                    {{ t.count }}
                                </span>
                            </div>
                            <p class="text-[11px] font-medium mt-0.5 leading-snug"
                               :class="activeTab === t.key ? 'text-blue-100' : 'text-gray-400 group-hover:text-gray-500'">
                                {{ t.desc }}
                            </p>
                        </div>
                    </div>

                    <!-- hover underline sweep -->
                    <div v-if="activeTab !== t.key"
                         class="absolute bottom-0 left-0 right-0 h-0.5 bg-blue-500 scale-x-0 group-hover:scale-x-100 transition-transform duration-200 origin-left"></div>
                </button>
            </div>

            <!-- ── Tab Content ───────────────────────────────────────────── -->
            <YardstickTab
                v-if="activeTab === 'yardstick'"
                :yardstick="yardstick"
                :can-edit="can.manage_yardstick"
            />
            <PcfTab
                v-else-if="activeTab === 'pcf'"
                :pcfs="pcfs"
                :can="can"
                :standard-options="standardOptions"
                :value-options="valueOptions"
                :selectable-users="selectableUsers"
                :current-user-id="currentUserId"
                :taken-pcf="takenPcf"
                :year="filters.year"
            />
            <PafTab
                v-else
                :pcfs="pcfs"
                :can="can"
                :quarter-statuses="quarterStatuses"
            />

        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import YardstickTab from './_YardstickTab.vue'
import PcfTab from './_PcfTab.vue'
import PafTab from './_PafTab.vue'

const props = defineProps({
    filters:        { type: Object, default: () => ({ year: new Date().getFullYear() }) },
    yardstick:      { type: Object, default: () => ({ standards: [], values: [], ratings: [], guidelines: [] }) },
    pcfs:           { type: Array,  default: () => [] },
    quarterStatuses:{ type: Object, default: () => ({}) },
    standardOptions:{ type: Array,  default: () => [] },
    valueOptions:   { type: Array,  default: () => [] },
    selectableUsers:{ type: Array,  default: () => [] },
    currentUserId:  { type: Number, default: null },
    takenPcf:       { type: Array,  default: () => [] },
    can:            { type: Object, default: () => ({}) },
})

const tabs = computed(() => [
    {
        key:   'yardstick',
        label: 'Yardstick',
        desc:  'Performance standards & rating guide',
        icon:  'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
        count: null,
    },
    {
        key:   'pcf',
        label: 'PCF',
        desc:  'Performance Commitment Form',
        icon:  'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
        count: props.pcfs.length,
    },
    {
        key:   'paf',
        label: 'PAF',
        desc:  'Performance Appraisal Form',
        icon:  'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        count: props.pcfs.filter(p => p.status === 'confirmed').length,
    },
])

const activeTab = ref('yardstick')
const year = ref(props.filters.year)

const applyYear = () => {
    if (!year.value) return
    router.get(route('wigs.index'), { year: year.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}
</script>
