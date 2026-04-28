<script setup>
/**
 * DynamicFormRenderer
 *
 * Renders a form from a schema array (stored in request_types.form_schema.fields).
 *
 * Props:
 *   fields         – array of field definitions
 *   modelValue     – object of { key: value } for all fields (v-model)
 *   itemsColumns   – array of column defs for the repeating-row section (optional)
 *   items          – array of row objects for the repeating section (v-model:items)
 *   itemLabel      – singular label for each row (default: "Row")
 *   errors         – flat error object from Inertia { key: 'message' }
 *   context        – extra context (e.g. { companyName }) for conditional display
 *   hasItems       – whether to show the items section
 */

import { computed, reactive, onUnmounted, watch } from 'vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    itemsColumns: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    itemLabel: { type: String, default: 'Row' },
    itemsTemplateSource: { type: String, default: null },
    itemsTemplates: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    context: { type: Object, default: () => ({}) },
    hasItems: { type: Boolean, default: false },
    gridColumns: { type: [Number, String], default: 4 },
    gap: { type: [Number, String], default: 6 },
    space: { type: [Number, String], default: 8 },
    dense: { type: Boolean, default: false }
})

const emit = defineEmits(['update:modelValue', 'update:items'])

const { showError } = useToast()

// ── Local previews for newly selected files ──────────────────────────────────
const filePreviews = reactive({}) // { [fieldKey]: objectUrl }
const itemFilePreviews = reactive({}) // { [rowIdx_colKey]: objectUrl }

const clearPreviews = (previews) => {
    Object.values(previews).forEach(url => URL.revokeObjectURL(url))
    Object.keys(previews).forEach(key => delete previews[key])
}

onUnmounted(() => {
    clearPreviews(filePreviews)
    clearPreviews(itemFilePreviews)
})

const getFieldError = (key) => props.errors[key] || props.errors[`form_data.${key}`]
const getItemError = (rowIdx, colKey) => props.errors[`items.${rowIdx}.${colKey}`]

const gridClass = computed(() => {
    const cols = Number(props.gridColumns)
    if (cols === 1) return 'grid-cols-1'
    if (cols === 2) return 'grid-cols-1 sm:grid-cols-2'
    if (cols === 3) return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3'
    return 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4'
})

const gapClass = computed(() => `gap-${props.gap}`)
const spaceClass = computed(() => props.hasItems ? `space-y-${props.space}` : (props.dense ? 'space-y-4' : 'space-y-6'))

const inputClass = computed(() => {
    if (props.dense) {
        return 'block w-full bg-white border-2 border-gray-100 rounded-xl px-3 py-2 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none'
    }
    return 'block w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none'
})

const textareaClass = computed(() => {
    if (props.dense) {
        return 'block w-full bg-white border-2 border-gray-100 rounded-xl px-3 py-2 text-sm font-medium focus:border-indigo-500 focus:ring-0 transition-all outline-none'
    }
    return 'block w-full bg-white border-2 border-gray-100 rounded-2xl px-4 py-3 text-sm font-medium focus:border-indigo-500 focus:ring-0 transition-all outline-none'
})

const colSpanClass = (field) => {
    const cols = Number(props.gridColumns)
    if (cols === 1) return 'col-span-1'
    
    if (field.type === 'textarea') return `sm:col-span-${cols}`
    if ((field.key.includes('name') || field.key.includes('description')) && field.type !== 'textarea') {
        return cols > 2 ? 'sm:col-span-2' : 'col-span-1'
    }
    return 'col-span-1'
}

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

const selectedItemsTemplateKey = computed(() =>
    props.itemsTemplateSource ? String(props.modelValue[props.itemsTemplateSource] ?? '') : ''
)

const activeItemsTemplate = computed(() =>
    selectedItemsTemplateKey.value
        ? props.itemsTemplates?.[selectedItemsTemplateKey.value] || null
        : null
)

const resolvedItemsColumns = computed(() =>
    props.itemsTemplateSource
        ? (activeItemsTemplate.value?.columns || [])
        : props.itemsColumns
)

const resolvedItemsTitle = computed(() =>
    activeItemsTemplate.value?.label || `${props.itemLabel}s`
)

const resolvedItemBadgeLabel = computed(() =>
    activeItemsTemplate.value?.label
        ? activeItemsTemplate.value.label.replace(/\s+items$/i, ' Item')
        : props.itemLabel
)

const hasResolvedItems = computed(() =>
    props.hasItems && resolvedItemsColumns.value.length > 0
)

// ── Field value helpers ───────────────────────────────────────────────────────
const getValue = (key) => props.modelValue[key] ?? ''

const setValue = (key, value) => {
    const updated = { ...props.modelValue, [key]: value }
    // Auto-clear child fields that depend on this key
    props.fields.forEach(f => {
        if (f.depends_on === key) updated[f.key] = Array.isArray(updated[f.key]) ? [] : ''
    })
    emit('update:modelValue', updated)
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

// ── Cascading dropdown helpers ────────────────────────────────────────────────
const getOptions = (field) => {
    if (field.depends_on && field.option_map) {
        return field.option_map[props.modelValue[field.depends_on] ?? ''] ?? []
    }
    return field.options ?? []
}

const getItemColOptions = (col, row) => {
    if (col.depends_on && col.option_map) {
        return col.option_map[row[col.depends_on] ?? ''] ?? []
    }
    return col.options ?? []
}

// ── Items table helpers ───────────────────────────────────────────────────────
const blankValueForColumn = (col) => col.type === 'checkbox_group' ? [] : ''

const normalizeItemRowsForColumns = (rows = props.items, columns = resolvedItemsColumns.value) => {
    if (!columns.length) return []
    const sourceRows = rows.length ? rows : [{}]

    return sourceRows.map(row => {
        const normalized = {}
        columns.forEach(c => {
            normalized[c.key] = row?.[c.key] ?? blankValueForColumn(c)
        })
        return normalized
    })
}

const addItemRow = () => {
    const columns = resolvedItemsColumns.value
    if (!columns.length) return

    const first = props.items[0] ?? {}
    const newRow = {}
    columns.forEach(c => { newRow[c.key] = first[c.key] ?? blankValueForColumn(c) })
    emit('update:items', [...props.items, newRow])
}

const removeItemRow = (idx) => {
    const next = props.items.filter((_, i) => i !== idx)
    emit('update:items', next.length ? next : (resolvedItemsColumns.value.length ? [buildBlankRow()] : []))
}

const setItemCell = (rowIdx, key, value) => {
    const next = props.items.map((row, i) => {
        if (i !== rowIdx) return row
        const base = {}
        resolvedItemsColumns.value.forEach(c => { base[c.key] = row[c.key] ?? blankValueForColumn(c) })
        const updated = { ...base, [key]: value }
        // Auto-clear child columns that depend on this key
        resolvedItemsColumns.value.forEach(c => {
            if (c.depends_on === key) updated[c.key] = blankValueForColumn(c)
        })
        return updated
    })
    emit('update:items', next)
}

const getItemCellCheckboxValue = (rowIdx, key) => {
    const v = props.items[rowIdx]?.[key]
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
    resolvedItemsColumns.value.forEach(c => { r[c.key] = blankValueForColumn(c) })
    return r
}

watch(selectedItemsTemplateKey, () => {
    if (!props.hasItems) return
    emit('update:items', normalizeItemRowsForColumns())
})

// ── File attachment helpers ───────────────────────────────────────────────────
const validateFileSize = (file, maxSizeMb) => {
    if (!file || !maxSizeMb) return true
    const sizeInMb = file.size / (1024 * 1024)
    return sizeInMb <= maxSizeMb
}

const handleFileChange = (field, event) => {
    const files = event.target.files
    if (!files.length) return

    const maxSize = field.max_file_size || 10
    
    // Revoke old preview if any
    if (filePreviews[field.key]) {
        URL.revokeObjectURL(filePreviews[field.key])
        delete filePreviews[field.key]
    }

    if (field.multiple) {
        const fileArray = Array.from(files)
        for (const file of fileArray) {
            if (!validateFileSize(file, maxSize)) {
                showError(`File "${file.name}" exceeds the ${maxSize}MB limit.`)
                event.target.value = ''
                return
            }
        }
        setValue(field.key, fileArray)
    } else {
        const file = files[0]
        if (!validateFileSize(file, maxSize)) {
            showError(`File "${file.name}" exceeds the ${maxSize}MB limit.`)
            event.target.value = ''
            return
        }
        
        if (file.type.startsWith('image/')) {
            filePreviews[field.key] = URL.createObjectURL(file)
        }
        setValue(field.key, file)
    }
}

const removeFile = (key) => {
    if (filePreviews[key]) {
        URL.revokeObjectURL(filePreviews[key])
        delete filePreviews[key]
    }
    setValue(key, null)
}

const handleItemFileChange = (rowIdx, col, event) => {
    const files = event.target.files
    if (!files.length) return

    const maxSize = col.max_file_size || 10
    const previewKey = `${rowIdx}_${col.key}`
    
    if (itemFilePreviews[previewKey]) {
        URL.revokeObjectURL(itemFilePreviews[previewKey])
        delete itemFilePreviews[previewKey]
    }

    if (col.multiple) {
        const fileArray = Array.from(files)
        for (const file of fileArray) {
            if (!validateFileSize(file, maxSize)) {
                showError(`File "${file.name}" exceeds the ${maxSize}MB limit.`)
                event.target.value = ''
                return
            }
        }
        setItemCell(rowIdx, col.key, fileArray)
    } else {
        const file = files[0]
        if (!validateFileSize(file, maxSize)) {
            showError(`File "${file.name}" exceeds the ${maxSize}MB limit.`)
            event.target.value = ''
            return
        }

        if (file.type.startsWith('image/')) {
            itemFilePreviews[previewKey] = URL.createObjectURL(file)
        }
        setItemCell(rowIdx, col.key, file)
    }
}

const removeItemFile = (rowIdx, colKey) => {
    const previewKey = `${rowIdx}_${colKey}`
    if (itemFilePreviews[previewKey]) {
        URL.revokeObjectURL(itemFilePreviews[previewKey])
        delete itemFilePreviews[previewKey]
    }
    setItemCell(rowIdx, colKey, null)
}

// Returns a public URL for a stored path (e.g. "pos-requests/attachments/abc.jpg")
const storageUrl = (value) => {
    if (!value) return null
    if (typeof value === 'string' && value.startsWith('blob:')) return value
    
    let path = typeof value === 'object' ? value.path : value
    if (typeof path !== 'string') return null
    
    return `/storage/${path}`
}

const isImagePath = (value) => {
    if (!value) return false
    if (typeof value === 'string' && value.startsWith('blob:')) return true
    
    let path = typeof value === 'object' ? value.path : value
    if (typeof path !== 'string') return false
    
    return /\.(jpe?g|png|gif|webp|bmp|svg)$/i.test(path)
}

// True when value is an existing stored path, not a fresh File object
const isStoredFile = (value) => {
    if (!value) return false;
    if (typeof value === 'string') return value.length > 0 && !value.startsWith('blob:');
    if (typeof value === 'object' && value.path) return true;
    return false;
}

const getSelectedFileNames = (value) => {
    if (!value) return '';
    if (value instanceof FileList || Array.isArray(value)) {
        return Array.from(value).map(f => f.name || (typeof f === 'object' ? f.name : f)).join(', ');
    }
    if (value instanceof File) {
        return value.name;
    }
    if (typeof value === 'object' && value.name) return value.name;
    if (typeof value === 'string' && value.startsWith('blob:')) return 'New image selected';
    return '';
};
</script>

<template>
    <div :class="spaceClass">
        <!-- ── Field list ── -->
        <div class="grid" :class="[gridClass, gapClass]">
            <template v-for="field in visibleFields" :key="field.key">
                <div :class="colSpanClass(field)">
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
                        :class="inputClass"
                    />

                    <!-- textarea -->
                    <textarea
                        v-else-if="field.type === 'textarea'"
                        :value="getValue(field.key)"
                        @input="setValue(field.key, $event.target.value)"
                        :required="field.required"
                        rows="3"
                        :class="textareaClass"
                    />

                    <!-- select -->
                    <select
                        v-else-if="field.type === 'select'"
                        :value="getValue(field.key)"
                        @change="setValue(field.key, $event.target.value)"
                        :required="field.required"
                        :class="inputClass"
                    >
                        <option value="">-- select --</option>
                        <option v-for="opt in getOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>

                    <!-- radio -->
                    <div v-else-if="field.type === 'radio'" class="flex flex-wrap gap-2">
                        <button
                            v-for="opt in getOptions(field)" :key="opt.value"
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
                            v-for="opt in getOptions(field)" :key="opt.value"
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
                    <template v-else-if="field.type === 'file'">
                        <!-- Existing/New upload preview -->
                        <div v-if="isStoredFile(getValue(field.key)) || filePreviews[field.key]" class="mb-2 flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-200">
                            <div class="flex items-center gap-2 min-w-0 flex-1">
                                <img v-if="isImagePath(getValue(field.key)) || filePreviews[field.key]"
                                     :src="filePreviews[field.key] || storageUrl(getValue(field.key))"
                                     class="h-12 w-12 object-cover rounded-lg border border-gray-200 flex-shrink-0"
                                     alt="attachment preview" />
                                <span v-else class="flex-shrink-0 w-10 h-10 flex items-center justify-center bg-indigo-50 rounded-lg border border-indigo-100">
                                    <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <span class="block text-xs font-bold text-indigo-600 truncate">
                                        {{ filePreviews[field.key] ? 'New image selected' : getSelectedFileNames(getValue(field.key)) }}
                                    </span>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase">
                                        {{ filePreviews[field.key] ? 'New' : 'Current' }}
                                    </span>
                                </div>
                            </div>
                            <button type="button" @click="removeFile(field.key)" class="p-2 text-rose-500 hover:bg-rose-100 rounded-xl transition-all" title="Remove attachment">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <input
                            type="file"
                            :multiple="field.multiple"
                            @change="handleFileChange(field, $event)"
                            :required="field.required && !isStoredFile(getValue(field.key))"
                            class="block w-full text-xs text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition-all"
                        />
                        <p v-if="!isStoredFile(getValue(field.key)) && !filePreviews[field.key] && getValue(field.key)" class="mt-1 text-xs font-bold text-indigo-600">
                            Selected: {{ getSelectedFileNames(getValue(field.key)) }}
                        </p>
                        <p v-if="field.max_file_size" class="mt-1 text-[10px] text-gray-400 italic">Max size: {{ field.max_file_size }}MB</p>
                        <p v-if="isStoredFile(getValue(field.key))" class="mt-1 text-[10px] text-gray-400 italic">Choose a new file to replace the current attachment.</p>
                    </template>

                    <!-- help text -->
                    <p v-if="field.help_text" class="mt-1.5 text-[10px] text-gray-400 font-medium italic ml-1">{{ field.help_text }}</p>

                    <!-- error -->
                    <p v-if="getFieldError(field.key)" class="mt-1.5 text-[10px] text-red-600 font-bold ml-1 uppercase">{{ getFieldError(field.key) }}</p>
                </div>
            </template>
        </div>

        <!-- ── Line Items Section (Card-based Grid) ── -->
        <div v-if="hasResolvedItems" class="space-y-6 pt-4">
            <div class="flex items-center justify-between border-b border-gray-100 pb-4">
                <div>
                    <h4 class="text-sm font-black text-gray-900 uppercase tracking-widest">{{ resolvedItemsTitle }}</h4>
                    <p class="text-[10px] text-gray-400 font-bold uppercase mt-1 italic">Add multiple records below</p>
                </div>
                <button type="button" @click="addItemRow"
                    class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white hover:bg-indigo-700 rounded-xl text-xs font-black uppercase tracking-widest transition-all shadow-lg shadow-indigo-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    Add Item
                </button>
            </div>

            <div class="space-y-4">
                <div v-for="(row, rowIdx) in items" :key="rowIdx" 
                    class="relative bg-gray-50/50 rounded-3xl p-8 border-2 border-gray-100 hover:border-indigo-100 hover:bg-white transition-all group">
                    
                    <!-- Item Header/Remove -->
                    <div class="flex items-center justify-between mb-6">
                        <span class="px-4 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-[10px] font-black uppercase tracking-widest">
                            {{ resolvedItemBadgeLabel }} #{{ rowIdx + 1 }}
                        </span>
                        <button v-if="items.length > 1" type="button" @click="removeItemRow(rowIdx)"
                            class="p-2 text-gray-300 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>

                    <!-- Grid for Item Fields -->
                    <div class="grid gap-6" :class="gridClass">
                        <div v-for="col in resolvedItemsColumns" :key="col.key"
                            :class="colSpanClass(col)">
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 ml-1">
                                {{ col.label }}
                                <span v-if="col.required" class="text-red-500 ml-0.5">*</span>
                            </label>

                            <!-- select -->
                            <select v-if="col.type === 'select'"
                                :value="row[col.key] ?? ''"
                                @change="setItemCell(rowIdx, col.key, $event.target.value)"
                                :class="inputClass">
                                <option value="">-- select --</option>
                                <option v-for="opt in getItemColOptions(col, row)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>

                            <!-- textarea -->
                            <textarea v-else-if="col.type === 'textarea'"
                                :value="row[col.key] ?? ''"
                                @input="setItemCell(rowIdx, col.key, $event.target.value)"
                                rows="3"
                                :class="textareaClass"
                            />

                            <!-- radio -->
                            <div v-else-if="col.type === 'radio'" class="flex flex-wrap gap-2 p-1">
                                <button v-for="opt in getItemColOptions(col, row)" :key="opt.value"
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
                                <button v-for="opt in getItemColOptions(col, row)" :key="opt.value"
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
                            <template v-else-if="col.type === 'file'">
                                <!-- Existing/New upload preview -->
                                <div v-if="isStoredFile(row[col.key]) || itemFilePreviews[`${rowIdx}_${col.key}`]" class="mb-2 flex items-center gap-2 p-2 bg-gray-50 rounded-xl border border-gray-200">
                                    <div class="flex items-center gap-2 min-w-0 flex-1">
                                        <img v-if="isImagePath(row[col.key]) || itemFilePreviews[`${rowIdx}_${col.key}`]"
                                             :src="itemFilePreviews[`${rowIdx}_${col.key}`] || storageUrl(row[col.key])"
                                             class="h-10 w-10 object-cover rounded-lg border border-gray-200 flex-shrink-0"
                                             alt="attachment preview" />
                                        <span v-else class="flex-shrink-0 w-8 h-8 flex items-center justify-center bg-indigo-50 rounded-lg border border-indigo-100">
                                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                            </svg>
                                        </span>
                                        <div class="min-w-0">
                                            <span class="block text-[10px] font-bold text-indigo-600 truncate">
                                                {{ itemFilePreviews[`${rowIdx}_${col.key}`] ? 'New image selected' : getSelectedFileNames(row[col.key]) }}
                                            </span>
                                            <span class="text-[9px] text-gray-400 font-bold uppercase">
                                                {{ itemFilePreviews[`${rowIdx}_${col.key}`] ? 'New' : 'Current' }}
                                            </span>
                                        </div>
                                    </div>
                                    <button type="button" @click="removeItemFile(rowIdx, col.key)" class="p-1 text-rose-500 hover:bg-rose-100 rounded-lg transition-all" title="Remove attachment">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <input
                                    type="file"
                                    :multiple="col.multiple"
                                    @change="handleItemFileChange(rowIdx, col, $event)"
                                    class="block w-full text-[10px] text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-[10px] file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition-all"
                                />
                                <p v-if="getItemError(rowIdx, col.key)" class="mt-1 text-[10px] text-red-600 font-bold uppercase">{{ getItemError(rowIdx, col.key) }}</p>
                            </template>

                            <!-- default: text/number/date/email/tel -->
                            <input v-else
                                :type="col.type"
                                :value="row[col.key] ?? ''"
                                @input="setItemCell(rowIdx, col.key, $event.target.value)"
                                :step="col.type === 'number' ? '0.01' : undefined"
                                :class="inputClass"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Item button below last row -->
            <button type="button" @click="addItemRow"
                class="w-full flex items-center justify-center gap-2 px-6 py-3 border-2 border-dashed border-indigo-200 text-indigo-600 hover:border-indigo-400 hover:bg-indigo-50 rounded-2xl text-xs font-black uppercase tracking-widest transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                Add Item
            </button>
        </div>
    </div>
</template>
