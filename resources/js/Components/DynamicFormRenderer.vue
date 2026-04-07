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

const getItemCellCheckboxValue = (rowIdx, key) => {
    const v = props.items[rowIdx][key]
    return Array.isArray(v) ? v : []
}

const toggleItemCellCheckbox = (rowIdx, key, optValue) => {
    const arr = getItemCellCheckboxValue(rowIdx, key)
    const idx = arr.indexOf(optValue)
    const nextVal = idx === -1 ? [...arr, optValue] : arr.filter(x => x !== optValue)
    setItemCell(rowIdx, key, nextVal)
}

const buildBlankRow = () => {
    const r = {}
    props.itemsColumns.forEach(c => { r[c.key] = '' })
    return r
}
</script>

<template>
    <div class="space-y-8">
        <!-- ── Field list ── -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <template v-for="field in visibleFields" :key="field.key">
                <div :class="[
                    field.type === 'textarea' ? 'lg:col-span-4' : 'lg:col-span-1',
                    (field.key.includes('name') || field.key.includes('description')) && field.type !== 'textarea' ? 'lg:col-span-2' : ''
                ]">
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 ml-1">
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
                        class="block w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none"
                    />

                    <!-- textarea -->
                    <textarea
                        v-else-if="field.type === 'textarea'"
                        :value="getValue(field.key)"
                        @input="setValue(field.key, $event.target.value)"
                        :required="field.required"
                        rows="3"
                        class="block w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-medium focus:border-indigo-500 focus:ring-0 transition-all outline-none"
                    />

                    <!-- select -->
                    <select
                        v-else-if="field.type === 'select'"
                        :value="getValue(field.key)"
                        @change="setValue(field.key, $event.target.value)"
                        :required="field.required"
                        class="block w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none"
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
                                'px-4 py-2 rounded-xl text-xs font-black border-2 transition-all',
                                getValue(field.key) === opt.value
                                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-lg shadow-indigo-100'
                                    : 'bg-white text-gray-500 border-gray-100 hover:border-indigo-200'
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
                                'px-4 py-2 rounded-xl text-xs font-black border-2 transition-all',
                                getCheckboxValue(field.key).includes(opt.value)
                                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-lg shadow-indigo-100'
                                    : 'bg-white text-gray-500 border-gray-100 hover:border-indigo-200'
                            ]"
                        >{{ opt.label }}</button>
                    </div>

                    <!-- toggle -->
                    <div v-else-if="field.type === 'toggle'" class="flex items-center gap-3 p-3 bg-gray-50 rounded-2xl border border-transparent hover:border-indigo-100 transition-all">
                        <button
                            type="button"
                            @click="setValue(field.key, !getValue(field.key))"
                            :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none', getValue(field.key) ? 'bg-indigo-600' : 'bg-gray-200']"
                        >
                            <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', getValue(field.key) ? 'translate-x-6' : 'translate-x-1']" />
                        </button>
                        <span class="text-xs text-gray-700 font-black uppercase">{{ getValue(field.key) ? 'Yes' : 'No' }}</span>
                    </div>

                    <!-- file -->
                    <input
                        v-else-if="field.type === 'file'"
                        type="file"
                        :multiple="field.multiple"
                        @change="setValue(field.key, field.multiple ? $event.target.files : $event.target.files[0])"
                        :required="field.required"
                        class="block w-full text-xs text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition-all"
                    />

                    <!-- help text -->
                    <p v-if="field.help_text" class="mt-1.5 text-[10px] text-gray-400 font-medium italic ml-1">{{ field.help_text }}</p>

                    <!-- error -->
                    <p v-if="errors[field.key]" class="mt-1.5 text-[10px] text-red-600 font-bold ml-1 uppercase">{{ errors[field.key] }}</p>
                </div>
            </template>
        </div>

        <!-- ── Line Items Section (Card-based Grid) ── -->
        <div v-if="hasItems && itemsColumns.length" class="space-y-6 pt-4">
            <div class="flex items-center justify-between border-b border-gray-100 pb-4">
                <div>
                    <h4 class="text-sm font-black text-gray-900 uppercase tracking-widest">{{ itemLabel }}s</h4>
                    <p class="text-[10px] text-gray-400 font-bold uppercase mt-1 italic">Add multiple records below</p>
                </div>
                <button type="button" @click="addItemRow"
                    class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white hover:bg-indigo-700 rounded-xl text-xs font-black uppercase tracking-widest transition-all shadow-lg shadow-indigo-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    Add {{ itemLabel }}
                </button>
            </div>

            <div class="space-y-4">
                <div v-for="(row, rowIdx) in items" :key="rowIdx" 
                    class="relative bg-gray-50/50 rounded-3xl p-8 border-2 border-gray-100 hover:border-indigo-100 hover:bg-white transition-all group">
                    
                    <!-- Item Header/Remove -->
                    <div class="flex items-center justify-between mb-6">
                        <span class="px-4 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-[10px] font-black uppercase tracking-widest">
                            {{ itemLabel }} #{{ rowIdx + 1 }}
                        </span>
                        <button v-if="items.length > 1" type="button" @click="removeItemRow(rowIdx)"
                            class="p-2 text-gray-300 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>

                    <!-- 4-Column Grid for Item Fields -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div v-for="col in itemsColumns" :key="col.key"
                            :class="[
                                col.type === 'textarea' ? 'lg:col-span-4' : 'lg:col-span-1',
                                (col.key.includes('name') || col.key.includes('description')) && col.type !== 'textarea' ? 'lg:col-span-2' : ''
                            ]">
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 ml-1">
                                {{ col.label }}
                                <span v-if="col.required" class="text-red-500 ml-0.5">*</span>
                            </label>

                            <!-- select -->
                            <select v-if="col.type === 'select'"
                                :value="row[col.key] ?? ''"
                                @change="setItemCell(rowIdx, col.key, $event.target.value)"
                                class="w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                                <option value="">-- select --</option>
                                <option v-for="opt in (col.options || [])" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>

                            <!-- textarea -->
                            <textarea v-else-if="col.type === 'textarea'"
                                :value="row[col.key] ?? ''"
                                @input="setItemCell(rowIdx, col.key, $event.target.value)"
                                rows="3"
                                class="w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-medium focus:border-indigo-500 focus:ring-0 transition-all outline-none"
                            />

                            <!-- radio -->
                            <div v-else-if="col.type === 'radio'" class="flex flex-wrap gap-2 p-1">
                                <button v-for="opt in (col.options || [])" :key="opt.value"
                                    type="button"
                                    @click="setItemCell(rowIdx, col.key, opt.value)"
                                    :class="[
                                        'px-3 py-1.5 rounded-xl text-[10px] font-black border-2 transition-all',
                                        row[col.key] === opt.value
                                            ? 'bg-indigo-600 text-white border-indigo-600 shadow-md shadow-indigo-100'
                                            : 'bg-white text-gray-500 border-gray-100 hover:border-indigo-200'
                                    ]"
                                >{{ opt.label }}</button>
                            </div>

                            <!-- checkbox_group -->
                            <div v-else-if="col.type === 'checkbox_group'" class="flex flex-wrap gap-2 p-1">
                                <button v-for="opt in (col.options || [])" :key="opt.value"
                                    type="button"
                                    @click="toggleItemCellCheckbox(rowIdx, col.key, opt.value)"
                                    :class="[
                                        'px-3 py-1.5 rounded-xl text-[10px] font-black border-2 transition-all',
                                        getItemCellCheckboxValue(rowIdx, col.key).includes(opt.value)
                                            ? 'bg-indigo-600 text-white border-indigo-600 shadow-md shadow-indigo-100'
                                            : 'bg-white text-gray-500 border-gray-100 hover:border-indigo-200'
                                    ]"
                                >{{ opt.label }}</button>
                            </div>

                            <!-- toggle -->
                            <div v-else-if="col.type === 'toggle'" class="flex items-center gap-3 p-3 bg-white border-2 border-gray-100 rounded-2xl">
                                <button type="button"
                                    @click="setItemCell(rowIdx, col.key, !row[col.key])"
                                    :class="['relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none', row[col.key] ? 'bg-indigo-600' : 'bg-gray-200']">
                                    <span :class="['inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform', row[col.key] ? 'translate-x-4' : 'translate-x-0.5']" />
                                </button>
                                <span class="text-[10px] text-gray-700 font-black uppercase">{{ row[col.key] ? 'Yes' : 'No' }}</span>
                            </div>

                            <!-- file -->
                            <input v-else-if="col.type === 'file'"
                                type="file"
                                @change="setItemCell(rowIdx, col.key, $event.target.files[0])"
                                class="block w-full text-[10px] text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-[10px] file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition-all"
                            />

                            <!-- default: text/number/date/email/tel -->
                            <input v-else
                                :type="col.type"
                                :value="row[col.key] ?? ''"
                                @input="setItemCell(rowIdx, col.key, $event.target.value)"
                                :step="col.type === 'number' ? '0.01' : undefined"
                                class="w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
