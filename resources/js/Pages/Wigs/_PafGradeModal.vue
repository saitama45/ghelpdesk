<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 overflow-y-auto" @click.self="$emit('close')">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-[100rem] my-6 dark:bg-gray-800">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white rounded-t-2xl z-10 dark:bg-gray-800 dark:border-gray-700">
                <div>
                    <h2 class="text-lg font-black text-gray-800 dark:text-gray-200">Performance Appraisal — {{ pcf.user?.name }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-300">{{ pcf.year }} · {{ [pcf.level_1, pcf.level_2, pcf.level_3].filter(Boolean).join(' › ') }}</p>
                </div>
                <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none dark:text-gray-400">&times;</button>
            </div>

            <div class="p-6 space-y-6">
                <!-- Annual PAF summary -->
                <section class="border border-gray-100 rounded-xl overflow-hidden dark:border-gray-700">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100 flex items-center justify-between dark:bg-gray-900/50 dark:border-gray-700">
                        <h3 class="text-xs font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Annual Appraisal (avg of graded quarters)</h3>
                        <span class="text-sm font-black text-blue-600">Total: {{ Number(pcf.paf_total).toFixed(2) }} / 4.00</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="bg-gray-50 text-[10px] uppercase tracking-wider text-gray-500 dark:bg-gray-900/50 dark:text-gray-300">
                                    <th class="px-2 py-2 text-left font-bold">WIG</th>
                                    <th class="px-2 py-2 text-left font-bold">Lead Measures</th>
                                    <th class="px-2 py-2 text-center font-bold">Weight</th>
                                    <th class="px-2 py-2 text-left font-bold">Perf. Standard</th>
                                    <th class="px-2 py-2 text-center font-bold">Rating</th>
                                    <th class="px-2 py-2 text-center font-bold">Score</th>
                                    <th class="px-2 py-2 text-left font-bold">Value Alignment</th>
                                    <th class="px-2 py-2 text-center font-bold">Value</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="it in pcf.items" :key="it.id">
                                    <td class="px-2 py-2 text-gray-700 dark:text-gray-300">{{ it.wig }}</td>
                                    <td class="px-2 py-2 text-gray-500 dark:text-gray-300">{{ it.lead_measures }}</td>
                                    <td class="px-2 py-2 text-center text-gray-600 dark:text-gray-300">{{ Number(it.annual_weight).toFixed(0) }}%</td>
                                    <td class="px-2 py-2 text-gray-500 dark:text-gray-300">{{ it.performance_standard }}</td>
                                    <td class="px-2 py-2 text-center font-bold">{{ it.annual_rating ?? '—' }}</td>
                                    <td class="px-2 py-2 text-center font-bold text-blue-600">{{ it.annual_score != null ? Number(it.annual_score).toFixed(2) : '—' }}</td>
                                    <td class="px-2 py-2 text-gray-500 dark:text-gray-300">{{ it.value_alignment }}</td>
                                    <td class="px-2 py-2 text-center">
                                        <span v-if="it.value_pass === true" class="text-green-600 font-bold">Yes</span>
                                        <span v-else-if="it.value_pass === false" class="text-red-500 font-bold">No</span>
                                        <span v-else class="text-gray-300">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Quarter grading -->
                <section>
                    <div class="flex items-center gap-2 mb-3">
                        <button v-for="q in [1,2,3,4]" :key="q" @click="activeQuarter = q"
                                :class="[
                                    'px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors',
                                    activeQuarter === q ? 'bg-blue-600 text-white border-blue-600'
                                        : quarterStatuses[q]?.open ? 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'
                                        : 'bg-gray-50 text-gray-400 border-gray-200'
                                ]">
                            {{ quarterStatuses[q]?.label || ('Q' + q) }}
                            <span v-if="!quarterStatuses[q]?.open" class="ml-1">🔒</span>
                        </button>
                    </div>

                    <div v-if="!quarterStatuses[activeQuarter]?.open"
                         class="bg-amber-50 border border-amber-200 text-amber-700 text-xs font-semibold rounded-lg px-4 py-3">
                        Grading for {{ quarterStatuses[activeQuarter]?.label }} opens on {{ quarterStatuses[activeQuarter]?.opens_at }}.
                    </div>

                    <div class="border border-gray-100 rounded-xl overflow-hidden mt-2 dark:border-gray-700">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead>
                                    <tr class="bg-gray-50 text-[10px] uppercase tracking-wider text-gray-500 dark:bg-gray-900/50 dark:text-gray-300">
                                        <th class="px-2 py-2 text-left font-bold">WIG</th>
                                        <th class="px-2 py-2 text-left font-bold">Benchmark</th>
                                        <th class="px-2 py-2 text-center font-bold">Q Weight</th>
                                        <th class="px-2 py-2 text-left font-bold">Actual Performance</th>
                                        <th class="px-2 py-2 text-center font-bold">Rating</th>
                                        <th class="px-2 py-2 text-center font-bold">Score</th>
                                        <th class="px-2 py-2 text-center font-bold">Value Pass</th>
                                        <th class="px-2 py-2 text-left font-bold">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="it in pcf.items" :key="it.id" class="align-top">
                                        <td class="px-2 py-2 text-gray-700 w-48 dark:text-gray-300">{{ it.wig }}</td>
                                        <td class="px-2 py-2 text-gray-500 w-48 dark:text-gray-300">{{ it.metric_benchmark }}</td>
                                        <td class="px-2 py-2 text-center text-gray-600 dark:text-gray-300">{{ Number(it['q' + activeQuarter + '_weight']).toFixed(0) }}%</td>
                                        <td class="px-1 py-1">
                                            <textarea v-model="grades[it.id].actual_performance" :disabled="readOnly" rows="2"
                                                      class="w-56 text-xs border-gray-200 rounded disabled:bg-gray-100 dark:border-gray-700"></textarea>
                                        </td>
                                        <td class="px-1 py-1 text-center">
                                            <select v-model.number="grades[it.id].rating" :disabled="readOnly"
                                                    class="w-16 text-xs border-gray-200 rounded pl-2 pr-6 disabled:bg-gray-100 dark:border-gray-700">
                                                <option :value="null">—</option>
                                                <option v-for="r in [4,3,2,1]" :key="r" :value="r">{{ r }}</option>
                                            </select>
                                        </td>
                                        <td class="px-2 py-2 text-center font-bold text-blue-600">{{ liveScore(it) }}</td>
                                        <td class="px-1 py-1 text-center">
                                            <select v-model="grades[it.id].value_pass" :disabled="readOnly"
                                                    class="w-20 text-xs border-gray-200 rounded pl-2 pr-6 disabled:bg-gray-100 dark:border-gray-700">
                                                <option :value="null">—</option>
                                                <option :value="true">Yes</option>
                                                <option :value="false">No</option>
                                            </select>
                                        </td>
                                        <td class="px-1 py-1">
                                            <textarea v-model="grades[it.id].remarks" :disabled="readOnly" rows="2"
                                                      class="w-48 text-xs border-gray-200 rounded disabled:bg-gray-100 dark:border-gray-700"></textarea>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 sticky bottom-0 bg-white rounded-b-2xl dark:bg-gray-800 dark:border-gray-700">
                <button @click="$emit('close')" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Close</button>
                <button v-if="canEdit && !readOnly" @click="save" :disabled="saving"
                        class="px-5 py-2 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 disabled:opacity-50">
                    {{ saving ? 'Saving…' : 'Save ' + (quarterStatuses[activeQuarter]?.label || ('Q' + activeQuarter)) + ' Grades' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    pcf: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    quarterStatuses: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['close'])

const { showSuccess, showError } = useToast()

// Default to the latest open quarter, else Q1.
const firstOpen = [4, 3, 2, 1].find(q => props.quarterStatuses[q]?.open) || 1
const activeQuarter = ref(firstOpen)
const saving = ref(false)

const readOnly = computed(() => !props.canEdit || !props.quarterStatuses[activeQuarter.value]?.open)

// Per-item editable grade state for the active quarter.
const grades = reactive({})

const loadQuarter = (q) => {
    props.pcf.items.forEach(it => {
        const existing = it.quarters?.[q] || {}
        grades[it.id] = {
            actual_performance: existing.actual_performance ?? '',
            rating: existing.rating ?? null,
            value_pass: existing.value_pass ?? null,
            remarks: existing.remarks ?? '',
        }
    })
}
loadQuarter(activeQuarter.value)
watch(activeQuarter, (q) => loadQuarter(q))

const liveScore = (it) => {
    const r = grades[it.id]?.rating
    if (r == null) return '—'
    const w = Number(it['q' + activeQuarter.value + '_weight']) || 0
    return ((w / 100) * r).toFixed(2)
}

const save = () => {
    saving.value = true
    const scores = props.pcf.items.map(it => ({
        pcf_item_id: it.id,
        quarter: activeQuarter.value,
        actual_performance: grades[it.id].actual_performance || null,
        rating: grades[it.id].rating,
        value_pass: grades[it.id].value_pass,
        remarks: grades[it.id].remarks || null,
    }))
    router.put(route('wigs.pcf.grade', props.pcf.id), { scores }, {
        preserveScroll: true,
        onSuccess: () => { showSuccess('Quarterly grades saved.'); emit('close') },
        onError: () => showError('Could not save grades.'),
        onFinish: () => { saving.value = false },
    })
}
</script>
