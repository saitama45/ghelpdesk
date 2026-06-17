<template>
    <div class="space-y-4">
        <!-- toolbar + quarter windows -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm px-5 py-4 space-y-3">
            <div>
                <p class="text-sm font-black text-gray-800">Performance Appraisal Forms</p>
                <p class="text-xs text-gray-400 mt-0.5">
                    Auto-generated from confirmed PCFs. Grading opens the 1st of the month after each quarter ends.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <div v-for="q in [1,2,3,4]" :key="q"
                     class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold border-2 transition-colors"
                     :class="quarterStatuses[q]?.open
                         ? 'bg-green-50 border-green-200 text-green-700'
                         : 'bg-gray-50 border-gray-200 text-gray-400'">
                    <span class="w-2 h-2 rounded-full flex-shrink-0"
                          :class="quarterStatuses[q]?.open ? 'bg-green-500' : 'bg-gray-300'"></span>
                    {{ quarterStatuses[q]?.label }}
                    <span class="font-medium" :class="quarterStatuses[q]?.open ? 'text-green-600' : 'text-gray-400'">
                        {{ quarterStatuses[q]?.open ? '· Open' : `· Opens ${quarterStatuses[q]?.opens_at}` }}
                    </span>
                </div>
            </div>
        </div>

        <div v-if="pcfs.length === 0" class="bg-white rounded-xl border border-dashed border-gray-200 p-10 text-center text-gray-400 text-sm">
            No PCF records to appraise for this year.
        </div>

        <div v-else class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-[11px] uppercase tracking-wider text-gray-500">
                        <th class="px-4 py-3 text-left font-bold">Team Member</th>
                        <th class="px-4 py-3 text-center font-bold">WIGs</th>
                        <th class="px-4 py-3 text-center font-bold">Annual Score</th>
                        <th class="px-4 py-3 text-center font-bold">For Rehire</th>
                        <th class="px-4 py-3 text-right font-bold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="p in pcfs" :key="p.id" class="hover:bg-gray-50/60">
                        <td class="px-4 py-3">
                            <p class="font-bold text-gray-800">{{ p.user?.name }}</p>
                            <p class="text-xs text-gray-500">{{ p.user?.position }}</p>
                        </td>
                        <td class="px-4 py-3 text-center font-bold text-gray-700">{{ p.items.length }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-lg font-black text-blue-600">{{ Number(p.paf_total).toFixed(2) }}</span>
                            <span class="text-xs text-gray-400"> / 4.00</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span :class="p.for_rehire ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'"
                                  class="inline-flex px-2 py-1 text-[11px] font-bold rounded-full uppercase tracking-wider">
                                {{ p.for_rehire ? 'Yes' : '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button @click="open(p)"
                                    class="px-3 py-1.5 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-700">
                                {{ can.edit ? 'Grade / View' : 'View' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PafGradeModal
            v-if="selected"
            :pcf="selected"
            :can-edit="can.edit"
            :quarter-statuses="quarterStatuses"
            @close="selected = null"
        />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import PafGradeModal from './_PafGradeModal.vue'

defineProps({
    pcfs: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    quarterStatuses: { type: Object, default: () => ({}) },
})

const selected = ref(null)
const open = (p) => { selected.value = p }
</script>
