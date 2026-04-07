<script setup>
/**
 * DynamicFormRenderer
 *
 * Renders a form from a schema array (stored in request_types.form_schema.fields).
 *
 * Props:
 *   fields         – array of field definitions
 *   modelValue     – object of { key: value } for all fields (v-model)
 *   itemsColumns   – array of column defs for the repeating-row table (optional)
 *   items          – array of row objects for the repeating table (v-model:items)
 *   itemLabel      – singular label for each row (default: "Row")
 *   errors         – flat error object from Inertia { key: 'message' }
 *   context        – extra context (e.g. { companyName }) for conditional display
 *   hasItems       – whether to show the items table section
 */

import { computed } from 'vue'

const props = defineProps({
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    itemsColumns: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    itemLabel: { type: String, default: 'Row' },
    errors: { type: Object, default: () => ({}) },
    context: { type: Object, default: () => ({}) },
    hasItems: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'update:items'])

// ── Sorted visible fields ─────────────────────────────────────────────────────
const sortedFields = computed(() =>
    [...props.fields].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
)

const isVisible = (field) => {
    if (!field.show_when) return true
    const { field: depKey, value: depVal } = field.show_when
    const current = props.modelValue[depKey]
    if (Array.isArray(current)) return current.includes(depVal)
    return String(current ?? '') === String(depVal)
}

const visibleFields = computed(() => sortedFields.value.filter(isVisible))

// ── Field value helpers ───────────────────────────────────────────────────────
const getValue = (key) => props.modelValue[key] ?? ''

const setValue = (key, value) => {
    emit('update:modelValue', { ...props.modelValue, [key]: value })
}

const getCheckboxValue = (key) => {
    const v = props.modelValue[key]
    return Array.isArray(v) ? v : []
}

const toggleCheckbox = (key, optValue) => {
    const arr = getCheckboxValue(key)
    const idx = arr.indexOf(optValue)
    const next = idx === -1 ? [...arr, optValue] : arr.filter(x => x !== optValue)
    setValue(key, next)
}

// ── Items table helpers ───────────────────────────────────────────────────────
const addItemRow = () => {
    const blank = {}
    props.itemsColumns.forEach(c => { blank[c.key] = '' })
    emit('update:items', [...props.items, blank])
}

const removeItemRow = (idx) => {
    const next = props.items.filter((_, i) => i !== idx)
    emit('update:items', next.length ? next : [buildBlankRow()])
}

const setItemCell = (rowIdx, key, value) => {
    const next = props.items.map((row, i) => i === rowIdx ? { ...row, [key]: value } : row)
    emit('update:items', next)
}

const buildBlankRow = () => {
    const r = {}
    props.itemsColumns.forEach(c => { r[c.key] = '' })
    return r
}
</script>

<template>
    <div class="space-y-5">
        <!-- ── Field list ── -->
        <template v-for="field in visibleFields" :key="field.key">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5">
                    {{ field.label }}
                    <span v-if="field.required" class="text-red-500 ml-0.5">*</span>
                </label>

                <!-- text / email / tel / number / date -->
                <input
                    v-if="['text','email','tel','number','date'].includes(field.type)"
                    :type="field.type"
                    :value="getValue(field.key)"
                    @input="setValue(field.key, $event.target.value)"
                    :required="field.required"
                    :step="field.type === 'number' ? '0.01' : undefined"
                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                />

                <!-- textarea -->
                <textarea
                    v-else-if="field.type === 'textarea'"
                    :value="getValue(field.key)"
                    @input="setValue(field.key, $event.target.value)"
                    :required="field.required"
                    rows="3"
                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                />

                <!-- select -->
                <select
                    v-else-if="field.type === 'select'"
                    :value="getValue(field.key)"
                    @change="setValue(field.key, $event.target.value)"
                    :required="field.required"
                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                >
                    <option value="">-- select --</option>
                    <option v-for="opt in (field.options || [])" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>

                <!-- radio -->
                <div v-else-if="field.type === 'radio'" class="flex flex-wrap gap-2">
                    <button
                        v-for="opt in (field.options || [])" :key="opt.value"
                        type="button"
                        @click="setValue(field.key, opt.value)"
                        :class="[
                            'px-4 py-2 rounded-lg text-sm font-semibold border transition-all',
                            getValue(field.key) === opt.value
                                ? 'bg-indigo-600 text-white border-indigo-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-indigo-400'
                        ]"
                    >{{ opt.label }}</button>
                </div>

                <!-- checkbox_group -->
                <div v-else-if="field.type === 'checkbox_group'" class="flex flex-wrap gap-2">
                    <button
                        v-for="opt in (field.options || [])" :key="opt.value"
                        type="button"
                        @click="toggleCheckbox(field.key, opt.value)"
                        :class="[
                            'px-4 py-2 rounded-lg text-sm font-semibold border transition-all',
                            getCheckboxValue(field.key).includes(opt.value)
                                ? 'bg-indigo-600 text-white border-indigo-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-indigo-400'
                        ]"
                    >{{ opt.label }}</button>
                </div>

                <!-- toggle -->
                <div v-else-if="field.type === 'toggle'" class="flex items-center gap-3">
                    <button
                        type="button"
                        @click="setValue(field.key, !getValue(field.key))"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none', getValue(field.key) ? 'bg-indigo-600' : 'bg-gray-200']"
                    >
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', getValue(field.key) ? 'translate-x-6' : 'translate-x-1']" />
                    </button>
                    <span class="text-sm text-gray-700 font-medium">{{ getValue(field.key) ? 'Yes' : 'No' }}</span>
                </div>

                <!-- file -->
                <input
                    v-else-if="field.type === 'file'"
                    type="file"
                    :multiple="field.multiple"
                    @change="setValue(field.key, field.multiple ? $event.target.files : $event.target.files[0])"
                    :required="field.required"
                    class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                />

                <!-- help text -->
                <p v-if="field.help_text" class="mt-1 text-xs text-gray-400">{{ field.help_text }}</p>

                <!-- error -->
                <p v-if="errors[field.key]" class="mt-1 text-xs text-red-600">{{ errors[field.key] }}</p>
            </div>
        </template>

        <!-- ── Line Items Table ── -->
        <div v-if="hasItems && itemsColumns.length" class="space-y-3">
            <div class="flex items-center justify-between">
                <h4 class="text-xs font-black text-gray-500 uppercase tracking-wider">{{ itemLabel }}s</h4>
                <button type="button" @click="addItemRow"
                    class="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 rounded-lg text-xs font-bold transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    Add {{ itemLabel }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th v-for="col in itemsColumns" :key="col.key"
                                class="px-3 py-2.5 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider whitespace-nowrap">
                                {{ col.label }}
                                <span v-if="col.required" class="text-red-400">*</span>
                            </th>
                            <th class="px-3 py-2.5 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="(row, rowIdx) in items" :key="rowIdx" class="hover:bg-gray-50">
                            <td v-for="col in itemsColumns" :key="col.key" class="px-3 py-2">
                                <!-- select -->
                                <select v-if="col.type === 'select'"
                                    :value="row[col.key] ?? ''"
                                    @change="setItemCell(rowIdx, col.key, $event.target.value)"
                                    class="w-full border-gray-200 rounded-lg text-xs focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]">
                                    <option value="">--</option>
                                    <option v-for="opt in (col.options || [])" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                                <!-- toggle -->
                                <button v-else-if="col.type === 'toggle'"
                                    type="button"
                                    @click="setItemCell(rowIdx, col.key, !row[col.key])"
                                    :class="['relative inline-flex h-5 w-9 items-center rounded-full transition-colors', row[col.key] ? 'bg-indigo-600' : 'bg-gray-200']">
                                    <span :class="['inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform', row[col.key] ? 'translate-x-4' : 'translate-x-0.5']" />
                                </button>
                                <!-- default: text/number/date/email -->
                                <input v-else
                                    :type="col.type"
                                    :value="row[col.key] ?? ''"
                                    @input="setItemCell(rowIdx, col.key, $event.target.value)"
                                    :step="col.type === 'number' ? '0.01' : undefined"
                                    class="w-full border-gray-200 rounded-lg text-xs focus:ring-indigo-500 focus:border-indigo-500 min-w-[100px]"
                                />
                            </td>
                            <td class="px-2 py-2 text-center">
                                <button type="button" @click="removeItemRow(rowIdx)"
                                    :disabled="items.length === 1"
                                    class="p-1 text-gray-300 hover:text-red-500 disabled:opacity-20 transition-colors rounded-full">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
