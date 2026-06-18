<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 overflow-y-auto" @click.self="$emit('close')">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-[100rem] my-6 dark:bg-gray-800">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white rounded-t-2xl z-10 dark:bg-gray-800 dark:border-gray-700">
                <h2 class="text-lg font-black text-gray-800 dark:text-gray-200">{{ isEdit ? 'Edit' : 'New' }} Performance Commitment Form</h2>
                <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none dark:text-gray-400">&times;</button>
            </div>

            <div class="p-6 space-y-5">
                <!-- Header fields -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Team Member</label>
                        <Autocomplete
                            v-model="form.user_id"
                            :options="availableUsers"
                            :disabled="isEdit"
                            placeholder="Search team member…"
                        />
                        <p v-if="!isEdit && availableUsers.length === 0" class="text-[11px] text-amber-600 mt-1">
                            Everyone available already has a PCF for {{ form.year }}.
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Year</label>
                        <input v-model.number="form.year" type="number" min="2000" max="2100"
                               class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" />
                    </div>
                </div>

                <!-- WIG items -->
                <div class="border border-gray-100 rounded-xl overflow-hidden dark:border-gray-700">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100 flex items-center justify-between dark:bg-gray-900/50 dark:border-gray-700">
                        <h3 class="text-xs font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Wildly Important Goals</h3>
                        <button @click="addItem" class="text-xs font-bold text-blue-600 hover:text-blue-800">+ Add WIG</button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="bg-gray-50 text-[10px] uppercase tracking-wider text-gray-500 dark:bg-gray-900/50 dark:text-gray-300">
                                    <th class="px-2 py-2 text-left font-bold">KRA</th>
                                    <th class="px-2 py-2 text-left font-bold">WIG</th>
                                    <th class="px-2 py-2 text-left font-bold">Lead Measures</th>
                                    <th class="px-2 py-2 text-left font-bold">Perf. Standard</th>
                                    <th class="px-2 py-2 text-left font-bold">Performance Metric</th>
                                    <th class="px-2 py-2 text-left font-bold">Metric Benchmark</th>
                                    <th class="px-2 py-2 text-center font-bold">Q1</th>
                                    <th class="px-2 py-2 text-center font-bold">Q2</th>
                                    <th class="px-2 py-2 text-center font-bold">Q3</th>
                                    <th class="px-2 py-2 text-center font-bold">Q4</th>
                                    <th class="px-2 py-2 text-left font-bold">Value Alignment</th>
                                    <th class="px-2 py-2 text-left font-bold">Value Remarks</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="(it, i) in form.items" :key="i" class="align-top">
                                    <td class="px-1 py-1"><textarea v-model="it.kra" rows="3" class="w-28 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                    <td class="px-1 py-1"><textarea v-model="it.wig" rows="3" class="w-44 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                    <td class="px-1 py-1"><textarea v-model="it.lead_measures" rows="3" class="w-36 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                    <td class="px-1 py-1">
                                        <select v-model="it.performance_standard" class="w-36 text-xs border-gray-200 rounded pl-2 pr-7 dark:border-gray-700">
                                            <option value="">—</option>
                                            <option v-for="s in standardOptions" :key="s" :value="s">{{ s }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1"><textarea v-model="it.performance_metric" rows="3" class="w-48 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                    <td class="px-1 py-1"><textarea v-model="it.metric_benchmark" rows="3" class="w-48 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                    <td class="px-1 py-1"><input v-model.number="it.q1_weight" type="number" min="0" max="100" step="0.01" class="w-16 text-xs text-center border-gray-200 rounded dark:border-gray-700" /></td>
                                    <td class="px-1 py-1"><input v-model.number="it.q2_weight" type="number" min="0" max="100" step="0.01" class="w-16 text-xs text-center border-gray-200 rounded dark:border-gray-700" /></td>
                                    <td class="px-1 py-1"><input v-model.number="it.q3_weight" type="number" min="0" max="100" step="0.01" class="w-16 text-xs text-center border-gray-200 rounded dark:border-gray-700" /></td>
                                    <td class="px-1 py-1"><input v-model.number="it.q4_weight" type="number" min="0" max="100" step="0.01" class="w-16 text-xs text-center border-gray-200 rounded dark:border-gray-700" /></td>
                                    <td class="px-1 py-1">
                                        <select v-model="it.value_alignment" class="w-32 text-xs border-gray-200 rounded pl-2 pr-7 dark:border-gray-700">
                                            <option value="">—</option>
                                            <option v-for="v in valueOptions" :key="v" :value="v">{{ v }}</option>
                                        </select>
                                    </td>
                                    <td class="px-1 py-1"><textarea v-model="it.value_remarks" rows="3" class="w-44 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                    <td class="px-1 py-1 text-center">
                                        <button @click="form.items.splice(i, 1)" class="text-red-500 hover:text-red-700 font-bold">✕</button>
                                    </td>
                                </tr>
                                <tr v-if="form.items.length === 0">
                                    <td colspan="13" class="px-4 py-6 text-center text-gray-400 dark:text-gray-400">No WIGs yet — add at least one.</td>
                                </tr>
                            </tbody>
                            <tfoot v-if="form.items.length">
                                <tr class="bg-gray-50 font-black text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                                    <td colspan="6" class="px-2 py-2 text-right uppercase tracking-wider text-[10px]">Total:</td>
                                    <td class="px-1 py-2 text-center" :class="totalClass(totals.q1)">{{ totals.q1.toFixed(0) }}%</td>
                                    <td class="px-1 py-2 text-center" :class="totalClass(totals.q2)">{{ totals.q2.toFixed(0) }}%</td>
                                    <td class="px-1 py-2 text-center" :class="totalClass(totals.q3)">{{ totals.q3.toFixed(0) }}%</td>
                                    <td class="px-1 py-2 text-center" :class="totalClass(totals.q4)">{{ totals.q4.toFixed(0) }}%</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <p class="text-[11px] text-gray-400 dark:text-gray-400">Each quarter's weights should total 100%. Totals shown in green when balanced.</p>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 sticky bottom-0 bg-white rounded-b-2xl dark:bg-gray-800 dark:border-gray-700">
                <button @click="$emit('close')" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                <button @click="submit" :disabled="saving" class="px-5 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 disabled:opacity-50">
                    {{ saving ? 'Saving…' : (isEdit ? 'Update PCF' : 'Create PCF') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@/Composables/useToast'
import Autocomplete from '@/Components/Autocomplete.vue'

const props = defineProps({
    pcf: { type: Object, default: null },
    year: { type: Number, required: true },
    standardOptions: { type: Array, default: () => [] },
    valueOptions: { type: Array, default: () => [] },
    selectableUsers: { type: Array, default: () => [] },
    currentUserId: { type: Number, default: null },
    takenPcf: { type: Array, default: () => [] },
})
const emit = defineEmits(['close'])

const { showSuccess, showError } = useToast()

const isEdit = computed(() => !!props.pcf)
const saving = ref(false)

const blankItem = () => ({
    kra: '', wig: '', lead_measures: '', performance_standard: '',
    performance_metric: '', metric_benchmark: '',
    q1_weight: 0, q2_weight: 0, q3_weight: 0, q4_weight: 0,
    value_alignment: '', value_remarks: '',
})

const isTaken = (userId, year) =>
    props.takenPcf.some(t => Number(t.user_id) === Number(userId) && Number(t.year) === Number(year))

// In edit mode the team member is fixed; ensure their option is present even
// if they're outside the create list, so the (disabled) field shows the name.
const editingUserOption = props.pcf?.user
    ? { value: props.pcf.user.id, label: props.pcf.user.position ? `${props.pcf.user.name} — ${props.pcf.user.position}` : props.pcf.user.name }
    : null

// Default to the logged-in user, but only if they're selectable and don't
// already have a PCF for the chosen year.
const initialYear = props.pcf?.year ?? props.year
const selfSelectable = props.currentUserId != null
    && props.selectableUsers.some(u => u.value === props.currentUserId)
const defaultUserId = props.pcf
    ? props.pcf.user_id
    : (selfSelectable && !isTaken(props.currentUserId, initialYear) ? props.currentUserId : null)

const form = reactive({
    user_id: defaultUserId,
    year: initialYear,
    items: props.pcf
        ? props.pcf.items.map(it => ({
            kra: it.kra ?? '', wig: it.wig ?? '', lead_measures: it.lead_measures ?? '',
            performance_standard: it.performance_standard ?? '',
            performance_metric: it.performance_metric ?? '', metric_benchmark: it.metric_benchmark ?? '',
            q1_weight: Number(it.q1_weight) || 0, q2_weight: Number(it.q2_weight) || 0,
            q3_weight: Number(it.q3_weight) || 0, q4_weight: Number(it.q4_weight) || 0,
            value_alignment: it.value_alignment ?? '', value_remarks: it.value_remarks ?? '',
        }))
        : [blankItem()],
})

const totals = computed(() => form.items.reduce((acc, it) => {
    acc.q1 += Number(it.q1_weight) || 0
    acc.q2 += Number(it.q2_weight) || 0
    acc.q3 += Number(it.q3_weight) || 0
    acc.q4 += Number(it.q4_weight) || 0
    return acc
}, { q1: 0, q2: 0, q3: 0, q4: 0 }))

const totalClass = (v) => Number(v) === 100 ? 'text-green-600' : 'text-amber-600'

// Team members available for the chosen year: hide anyone who already has a
// PCF for that year. Edit mode keeps the fixed (disabled) team member.
const availableUsers = computed(() => {
    if (isEdit.value) return editingUserOption ? [editingUserOption] : props.selectableUsers
    return props.selectableUsers.filter(u => !isTaken(u.value, form.year))
})

// When the year changes, drop a now-unavailable selection and re-default to
// the logged-in user if they're free for the new year.
watch(() => form.year, () => {
    if (isEdit.value) return
    const stillAvailable = availableUsers.value.some(u => u.value === form.user_id)
    if (!stillAvailable) {
        form.user_id = (selfSelectable && !isTaken(props.currentUserId, form.year)) ? props.currentUserId : null
    }
})

const addItem = () => form.items.push(blankItem())

const submit = () => {
    if (!form.user_id) { showError('Please select a team member.'); return }
    saving.value = true
    const payload = { user_id: form.user_id, year: form.year, items: form.items }
    const opts = {
        preserveScroll: true,
        onSuccess: () => { showSuccess(`PCF ${isEdit.value ? 'updated' : 'created'}.`); emit('close') },
        onError: (errors) => showError(Object.values(errors)[0] || 'Please review the form.'),
        onFinish: () => { saving.value = false },
    }
    if (isEdit.value) {
        router.put(route('wigs.pcf.update', props.pcf.id), payload, opts)
    } else {
        router.post(route('wigs.pcf.store'), payload, opts)
    }
}
</script>
