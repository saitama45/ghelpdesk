<script setup>
import { computed } from 'vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import ManageableAutocomplete from '@/Components/ManageableAutocomplete.vue'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    // [{ mode, amount, share_percent, reference_no, details: {}, remarks }]
    modelValue: { type: Array, default: () => [] },
    // Amount the lines must add up to (payment amount, or remaining balance when posting)
    total: { type: Number, default: 0 },
    // reference_options rows of type payment_mode
    modes: { type: Array, default: () => [] },
    // FIELD_GROUPS from PaymentRecordTender — { cheque: { label, fields: [...] }, ... }
    modeFields: { type: Object, default: () => ({}) },
    // [{ label, value }] — feeds detail fields of type 'user' (e.g. Card Owner)
    userOptions: { type: Array, default: () => [] },
    // 'planned' hides the mode-specific detail fields; 'actual' shows them
    kind: { type: String, default: 'actual' },
    // Planned splits may be left empty; actual postings need at least one line
    optional: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'modes-changed'])

const { hasPermission } = usePermission()

const rows = computed(() => props.modelValue || [])

const money = (n) => Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100

const allocated = computed(() => round2(rows.value.reduce((sum, r) => sum + (Number(r.amount) || 0), 0)))
const remaining = computed(() => round2(props.total - allocated.value))
const balanced = computed(() => Math.abs(remaining.value) <= 0.01)
const isEmpty = computed(() => rows.value.length === 0)

/* Mode → detail-field group. Mirrors PaymentRecordTender::fieldGroupFor so the
   editor renders the same fields the backend will validate. */
const fieldGroupFor = (mode) => {
    const m = String(mode || '').toLowerCase().trim()
    if (!m) return 'other'
    if (m.includes('cheque') || m.includes('check')) return 'cheque'
    if (m.includes('card')) return 'card'
    if (m.includes('transfer') || m.includes('bank') || m.includes('online') || m.includes('wire') || m.includes('gcash') || m.includes('maya')) return 'bank'
    if (m.includes('cod') || m.includes('cash')) return 'cash'
    return 'other'
}
const fieldsFor = (mode) => props.modeFields?.[fieldGroupFor(mode)]?.fields || []
const groupLabelFor = (mode) => props.modeFields?.[fieldGroupFor(mode)]?.label || 'Details'

const emitRows = (next) => emit('update:modelValue', next)

const blankRow = (amount = 0) => ({
    mode: '',
    amount: round2(amount),
    share_percent: props.total > 0 ? round2((amount / props.total) * 100) : 0,
    reference_no: '',
    details: {},
    remarks: '',
})

const addRow = () => {
    const next = [...rows.value, blankRow(Math.max(0, remaining.value))]
    emitRows(next)
}

const removeRow = (index) => {
    const next = rows.value.filter((_, i) => i !== index)
    emitRows(next)
}

const patchRow = (index, patch) => {
    const next = rows.value.map((row, i) => (i === index ? { ...row, ...patch } : row))
    emitRows(next)
}

const setMode = (index, mode) => {
    // Detail fields differ per mode — drop values that no longer apply.
    const keep = fieldsFor(mode).map(f => f.key)
    const current = rows.value[index]?.details || {}
    const details = {}
    keep.forEach((k) => { if (current[k]) details[k] = current[k] })
    patchRow(index, { mode, details })
}

const setAmount = (index, value) => {
    const amount = round2(value)
    patchRow(index, {
        amount,
        share_percent: props.total > 0 ? round2((amount / props.total) * 100) : 0,
    })
}

const setPercent = (index, value) => {
    const percent = round2(value)
    patchRow(index, {
        share_percent: percent,
        amount: round2((props.total * percent) / 100),
    })
}

const setDetail = (index, key, value) => {
    patchRow(index, { details: { ...(rows.value[index]?.details || {}), [key]: value } })
}

/* Even split across the current lines; the last line absorbs the rounding cents. */
const splitEvenly = () => {
    const count = rows.value.length
    if (!count || props.total <= 0) return
    const share = Math.floor((props.total / count) * 100) / 100
    const next = rows.value.map((row, i) => ({
        ...row,
        amount: i === count - 1 ? round2(props.total - share * (count - 1)) : share,
        share_percent: round2((100 / count)),
    }))
    emitRows(next)
}

/* Push whatever is unallocated onto the given line. */
const fillRemaining = (index) => {
    setAmount(index, round2((Number(rows.value[index]?.amount) || 0) + remaining.value))
}

const canCreateModes = computed(() => hasPermission('reference_options.create'))
const canEditModes = computed(() => hasPermission('reference_options.edit'))
const canDeleteModes = computed(() => hasPermission('reference_options.delete'))

defineExpose({ balanced, allocated, remaining })
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-2">
            <div class="text-[11px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">
                {{ kind === 'planned' ? 'Planned Payment Mode Split' : 'Payment Mode Split' }}
            </div>
            <div class="flex items-center gap-1.5">
                <button v-if="rows.length > 1" type="button" @click="splitEvenly" :disabled="disabled"
                        class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 disabled:opacity-50 dark:bg-blue-500/15 dark:text-blue-200">
                    Split evenly
                </button>
                <button type="button" @click="addRow" :disabled="disabled"
                        class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">
                    + Add mode
                </button>
            </div>
        </div>

        <div v-if="isEmpty"
             class="rounded-xl border border-dashed border-gray-300 py-4 text-center text-xs text-gray-400 italic dark:border-gray-600 dark:text-gray-400">
            {{ optional
                ? 'No split planned — the payment mode can be set when the payment is posted.'
                : 'Add at least one payment mode to post this payment.' }}
        </div>

        <div v-for="(row, index) in rows" :key="index"
             class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 space-y-2.5 dark:border-gray-700 dark:bg-gray-900/40">
            <div class="flex items-start gap-2">
                <div class="flex-1 grid grid-cols-1 sm:grid-cols-12 gap-2">
                    <div class="sm:col-span-6">
                        <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 mb-1 dark:text-gray-300">Payment Mode</label>
                        <ManageableAutocomplete
                            :model-value="row.mode"
                            @update:modelValue="setMode(index, $event)"
                            :options="modes"
                            option-type="payment_mode"
                            placeholder="Select payment mode..."
                            :disabled="disabled"
                            :can-create="canCreateModes"
                            :can-edit="canEditModes"
                            :can-delete="canDeleteModes"
                            @options-changed="$emit('modes-changed', $event)"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 mb-1 dark:text-gray-300">Amount</label>
                        <input :value="row.amount" @input="setAmount(index, $event.target.value)"
                               type="number" step="0.01" min="0" :disabled="disabled"
                               class="block w-full border-gray-300 rounded-lg text-sm text-right font-mono focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" />
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 mb-1 dark:text-gray-300">Share %</label>
                        <input :value="row.share_percent" @input="setPercent(index, $event.target.value)"
                               type="number" step="0.01" min="0" max="100" :disabled="disabled || total <= 0"
                               class="block w-full border-gray-300 rounded-lg text-sm text-right font-mono focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" />
                    </div>
                </div>
                <button type="button" @click="removeRow(index)" :disabled="disabled" title="Remove this mode"
                        class="mt-5 p-2 rounded-full text-red-600 hover:text-red-900 hover:bg-red-50 transition-colors disabled:opacity-50 dark:hover:bg-red-500/15">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
            </div>

            <!-- Mode-specific fields (actual postings only — a plan has no cheque number yet) -->
            <div v-if="kind === 'actual' && row.mode && fieldsFor(row.mode).length"
                 class="rounded-lg border border-gray-200 bg-white p-2.5 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5 dark:text-gray-400">
                    {{ groupLabelFor(row.mode) }}
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <div v-for="field in fieldsFor(row.mode)" :key="field.key">
                        <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 mb-1 dark:text-gray-300">
                            {{ field.label }}
                            <span v-if="field.required" class="text-red-500">*</span>
                        </label>
                        <Autocomplete v-if="field.type === 'user'"
                                      :model-value="row.details?.[field.key] || null"
                                      @update:modelValue="setDetail(index, field.key, $event)"
                                      :options="userOptions"
                                      :disabled="disabled"
                                      placeholder="Search user..." />
                        <input v-else
                               :value="row.details?.[field.key] || ''"
                               @input="setDetail(index, field.key, $event.target.value)"
                               :type="field.type === 'date' ? 'date' : 'text'"
                               :maxlength="field.maxlength || null"
                               :disabled="disabled"
                               class="block w-full border-gray-300 rounded-lg text-xs focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" />
                    </div>
                </div>
            </div>

            <div v-if="kind === 'actual'" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 mb-1 dark:text-gray-300">Reference No. (this mode)</label>
                    <input :value="row.reference_no || ''" @input="patchRow(index, { reference_no: $event.target.value })"
                           :disabled="disabled" placeholder="Falls back to the posting reference"
                           class="block w-full border-gray-300 rounded-lg text-xs focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" />
                </div>
                <div class="flex items-end">
                    <button v-if="!balanced" type="button" @click="fillRemaining(index)" :disabled="disabled"
                            class="px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-gray-600 bg-gray-100 hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-200">
                        Assign remaining ({{ money(remaining) }})
                    </button>
                </div>
            </div>
        </div>

        <!-- Running total -->
        <div v-if="!isEmpty"
             class="flex items-center justify-between rounded-xl border px-3 py-2 text-xs font-semibold"
             :class="balanced
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
                : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'">
            <span>Allocated {{ money(allocated) }} of {{ money(total) }}</span>
            <span>{{ balanced ? 'Fully allocated' : (remaining > 0 ? `${money(remaining)} unallocated` : `${money(Math.abs(remaining))} over`) }}</span>
        </div>
    </div>
</template>
