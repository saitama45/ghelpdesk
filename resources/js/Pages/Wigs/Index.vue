<template>
    <AppLayout title="WIGS">
        <div class="py-8">
            <div class="max-w-[110rem] mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Header -->
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-black text-gray-800 tracking-tight">Wildly Important Goals</h1>
                        <p class="text-sm text-gray-500 mt-1">Yardstick, Performance Commitment (PCF) &amp; Appraisal (PAF)</p>
                    </div>
                    <div class="flex items-end gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Year</label>
                            <input v-model.number="year" type="number" min="2000" max="2100"
                                   @change="applyYear"
                                   class="w-32 border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex gap-6">
                        <button v-for="t in tabs" :key="t.key" @click="activeTab = t.key"
                                :class="[
                                    'py-3 px-1 border-b-2 text-sm font-bold transition-colors',
                                    activeTab === t.key
                                        ? 'border-blue-600 text-blue-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                ]">
                            {{ t.label }}
                        </button>
                    </nav>
                </div>

                <!-- Tab content -->
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
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import YardstickTab from './_YardstickTab.vue'
import PcfTab from './_PcfTab.vue'
import PafTab from './_PafTab.vue'

const props = defineProps({
    filters: { type: Object, default: () => ({ year: new Date().getFullYear() }) },
    yardstick: { type: Object, default: () => ({ standards: [], values: [], ratings: [], guidelines: [] }) },
    pcfs: { type: Array, default: () => [] },
    quarterStatuses: { type: Object, default: () => ({}) },
    standardOptions: { type: Array, default: () => [] },
    valueOptions: { type: Array, default: () => [] },
    selectableUsers: { type: Array, default: () => [] },
    currentUserId: { type: Number, default: null },
    takenPcf: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
})

const tabs = [
    { key: 'yardstick', label: 'Yardstick' },
    { key: 'pcf', label: 'PCF' },
    { key: 'paf', label: 'PAF' },
]

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
