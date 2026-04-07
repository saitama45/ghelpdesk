<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    requestType: { type: Object, required: true },
    show: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const { showError } = useToast()

// ── Schema state ──────────────────────────────────────────────────────────────
const activeTab = ref('fields')
const isSaving = ref(false)

const schema = reactive({
    fields: [],
    has_items: false,
    item_label: 'Row',
    items_columns: [],
})

// Seed from existing schema when modal opens
watch(() => props.show, (val) => {
    if (!val) return
    const src = props.requestType.form_schema || {}
    schema.fields = (src.fields || []).map(f => ({ ...f, options: f.options ? f.options.map(o => ({ ...o })) : [] }))
    schema.has_items = src.has_items ?? false
    schema.item_label = src.item_label ?? 'Row'
    schema.items_columns = (src.items_columns || []).map(c => ({ ...c }))
    editingField.value = null
    editingItemCol.value = null
    activeTab.value = 'fields'
})

// ── Field editing ─────────────────────────────────────────────────────────────
const FIELD_TYPES = [
    { value: 'text', label: 'Text' },
    { value: 'number', label: 'Number' },
    { value: 'email', label: 'Email' },
    { value: 'tel', label: 'Phone' },
    { value: 'date', label: 'Date' },
    { value: 'textarea', label: 'Textarea' },
    { value: 'select', label: 'Dropdown' },
    { value: 'radio', label: 'Radio' },
    { value: 'checkbox_group', label: 'Checkboxes' },
    { value: 'toggle', label: 'Toggle' },
    { value: 'file', label: 'File Upload' },
]

const HAS_OPTIONS = ['select', 'radio', 'checkbox_group']

const blankField = () => ({
    key: '',
    label: '',
    type: 'text',
    required: false,
    help_text: '',
    options: [],
    show_when: null,
    multiple: false,
    _showCondition: false,
    _conditionField: '',
    _conditionValue: '',
})

const editingField = ref(null)
const editingIndex = ref(null)

const openAddField = () => {
    editingField.value = blankField()
    editingIndex.value = null
}

const openEditField = (idx) => {
    const f = schema.fields[idx]
    editingField.value = {
        ...f,
        options: (f.options || []).map(o => ({ ...o })),
        _showCondition: !!f.show_when,
        _conditionField: f.show_when?.field ?? '',
        _conditionValue: f.show_when?.value ?? '',
    }
    editingIndex.value = idx
}

const slugify = (str) =>
    str.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')

watch(() => editingField.value?.label, (val) => {
    if (editingField.value && editingIndex.value === null) {
        editingField.value.key = slugify(val || '')
    }
})

const saveField = () => {
    if (!editingField.value) return
    const f = { ...editingField.value }

    if (!f.label.trim()) { showError('Label is required'); return }
    if (!f.key.trim())   { showError('Key is required'); return }

    // Build show_when
    f.show_when = f._showCondition && f._conditionField
        ? { field: f._conditionField, value: f._conditionValue }
        : null
    delete f._showCondition
    delete f._conditionField
    delete f._conditionValue

    if (editingIndex.value === null) {
        f.sort_order = schema.fields.length + 1
        schema.fields.push(f)
    } else {
        schema.fields.splice(editingIndex.value, 1, f)
    }
    editingField.value = null
}

const removeField = (idx) => schema.fields.splice(idx, 1)

const moveField = (idx, dir) => {
    const target = idx + dir
    if (target < 0 || target >= schema.fields.length) return
    const tmp = schema.fields[idx]
    schema.fields.splice(idx, 1)
    schema.fields.splice(target, 0, tmp)
}

// Option rows
const addOption = () => editingField.value.options.push({ label: '', value: '' })
const removeOption = (i) => editingField.value.options.splice(i, 1)

// ── Items columns ─────────────────────────────────────────────────────────────
const ITEM_COL_TYPES = ['text', 'number', 'email', 'date', 'select', 'toggle']

const blankItemCol = () => ({ key: '', label: '', type: 'text', required: false, options: [] })
const editingItemCol = ref(null)
const editingItemColIndex = ref(null)

const openAddItemCol = () => {
    editingItemCol.value = blankItemCol()
    editingItemColIndex.value = null
}
const openEditItemCol = (idx) => {
    editingItemCol.value = { ...schema.items_columns[idx], options: (schema.items_columns[idx].options || []).map(o => ({ ...o })) }
    editingItemColIndex.value = idx
}
const saveItemCol = () => {
    if (!editingItemCol.value) return
    const c = { ...editingItemCol.value }
    if (!c.label.trim()) { showError('Column label is required'); return }
    if (!c.key.trim()) c.key = slugify(c.label)
    if (editingItemColIndex.value === null) {
        schema.items_columns.push(c)
    } else {
        schema.items_columns.splice(editingItemColIndex.value, 1, c)
    }
    editingItemCol.value = null
}
const removeItemCol = (idx) => schema.items_columns.splice(idx, 1)

// ── Save schema ───────────────────────────────────────────────────────────────
const saveSchema = () => {
    isSaving.value = true
    const payload = {
        form_schema: {
            fields: schema.fields.map((f, i) => ({ ...f, sort_order: i + 1 })),
            has_items: schema.has_items,
            item_label: schema.item_label,
            items_columns: schema.items_columns,
        }
    }
    router.put(route('request-types.schema', props.requestType.id), payload, {
        preserveScroll: true,
        onSuccess: () => { emit('close') },
        onError: () => showError('Failed to save schema'),
        onFinish: () => { isSaving.value = false },
    })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const typeLabel = (t) => FIELD_TYPES.find(x => x.value === t)?.label ?? t
const fieldKeys = computed(() => schema.fields.map(f => f.key).filter(Boolean))
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div v-if="show" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-900/70 backdrop-blur-sm" @click="emit('close')" />

                <div class="relative bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col border border-gray-100">
                    <!-- Header -->
                    <div class="flex items-start justify-between p-7 border-b border-gray-100 shrink-0">
                        <div>
                            <h2 class="text-xl font-black text-gray-900">Configure Form Fields</h2>
                            <p class="text-sm text-gray-500 mt-0.5 font-medium">{{ requestType.name }}</p>
                        </div>
                        <button @click="emit('close')" class="p-2 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="flex border-b border-gray-100 px-7 shrink-0">
                        <button
                            v-for="tab in [{ key: 'fields', label: 'Form Fields' }, { key: 'items', label: 'Line Items' }]"
                            :key="tab.key"
                            @click="activeTab = tab.key"
                            :class="[
                                'py-3 px-4 text-sm font-bold border-b-2 -mb-px transition-colors',
                                activeTab === tab.key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-400 hover:text-gray-700'
                            ]"
                        >
                            {{ tab.label }}
                            <span v-if="tab.key === 'fields' && schema.fields.length" class="ml-1.5 text-[10px] bg-indigo-100 text-indigo-700 rounded-full px-1.5 py-0.5 font-black">{{ schema.fields.length }}</span>
                            <span v-if="tab.key === 'items' && schema.has_items" class="ml-1.5 w-2 h-2 rounded-full bg-teal-500 inline-block align-middle"></span>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto p-7 space-y-4">

                        <!-- ── FIELDS TAB ── -->
                        <template v-if="activeTab === 'fields'">
                            <!-- Field list -->
                            <div v-if="schema.fields.length" class="space-y-2">
                                <div
                                    v-for="(field, idx) in schema.fields"
                                    :key="idx"
                                    class="flex items-center gap-3 p-3 rounded-2xl border border-gray-100 bg-gray-50 group"
                                >
                                    <!-- Move -->
                                    <div class="flex flex-col gap-0.5 shrink-0">
                                        <button @click="moveField(idx, -1)" :disabled="idx === 0" class="p-0.5 text-gray-300 hover:text-gray-600 disabled:opacity-20 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <button @click="moveField(idx, 1)" :disabled="idx === schema.fields.length - 1" class="p-0.5 text-gray-300 hover:text-gray-600 disabled:opacity-20 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                    <!-- Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-sm font-bold text-gray-900">{{ field.label }}</span>
                                            <span class="text-[10px] font-mono bg-gray-200 text-gray-600 rounded px-1.5 py-0.5">{{ field.key }}</span>
                                            <span class="text-[10px] bg-indigo-50 text-indigo-600 font-bold rounded px-1.5 py-0.5 border border-indigo-100">{{ typeLabel(field.type) }}</span>
                                            <span v-if="field.required" class="text-[10px] bg-red-50 text-red-600 font-bold rounded px-1.5 py-0.5 border border-red-100">Required</span>
                                            <span v-if="field.show_when" class="text-[10px] bg-amber-50 text-amber-600 font-bold rounded px-1.5 py-0.5 border border-amber-100">
                                                when {{ field.show_when.field }} = {{ field.show_when.value }}
                                            </span>
                                        </div>
                                        <div v-if="field.help_text" class="text-xs text-gray-400 mt-0.5 truncate">{{ field.help_text }}</div>
                                    </div>
                                    <!-- Actions -->
                                    <div class="flex gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="openEditField(idx)" class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                        <button @click="removeField(idx)" class="p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-400 text-center py-6">No fields yet. Add your first field below.</p>

                            <button @click="openAddField" class="w-full flex items-center justify-center gap-2 py-2.5 rounded-2xl border-2 border-dashed border-indigo-200 text-indigo-500 hover:border-indigo-400 hover:bg-indigo-50 text-sm font-bold transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                Add Field
                            </button>

                            <!-- Field editor -->
                            <div v-if="editingField" class="mt-2 p-5 bg-indigo-50 rounded-2xl border border-indigo-100 space-y-4">
                                <h4 class="text-sm font-black text-indigo-900">{{ editingIndex === null ? 'New Field' : 'Edit Field' }}</h4>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-1">Label *</label>
                                        <input v-model="editingField.label" type="text" placeholder="e.g. Vendor Name" class="w-full rounded-xl border-gray-200 text-sm focus:ring-indigo-500 focus:border-indigo-500 bg-white" />
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-1">Key *</label>
                                        <input v-model="editingField.key" type="text" placeholder="vendor_name" class="w-full rounded-xl border-gray-200 text-sm font-mono focus:ring-indigo-500 focus:border-indigo-500 bg-white" />
                                    </div>
                                </div>
                                <!-- Type selector -->
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-2">Field Type</label>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="ft in FIELD_TYPES" :key="ft.value"
                                            type="button"
                                            @click="editingField.type = ft.value"
                                            :class="[
                                                'px-3 py-1.5 rounded-xl text-xs font-bold transition-colors',
                                                editingField.type === ft.value
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'bg-white text-gray-600 border border-gray-200 hover:border-indigo-300'
                                            ]"
                                        >{{ ft.label }}</button>
                                    </div>
                                </div>
                                <!-- Options -->
                                <div v-if="HAS_OPTIONS.includes(editingField.type)">
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-2">Options</label>
                                    <div class="space-y-1.5">
                                        <div v-for="(opt, oi) in editingField.options" :key="oi" class="flex gap-2 items-center">
                                            <input v-model="opt.label" placeholder="Label" class="flex-1 rounded-xl border-gray-200 text-xs bg-white focus:ring-indigo-500 focus:border-indigo-500" />
                                            <input v-model="opt.value" placeholder="Value" class="flex-1 rounded-xl border-gray-200 text-xs font-mono bg-white focus:ring-indigo-500 focus:border-indigo-500" />
                                            <button @click="removeOption(oi)" class="text-rose-400 hover:text-rose-600 p-1">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <button @click="addOption" class="mt-2 text-xs text-indigo-600 font-bold hover:underline">+ Add option</button>
                                </div>
                                <!-- Flags row -->
                                <div class="flex flex-wrap gap-4 items-center">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input v-model="editingField.required" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                        <span class="text-xs font-bold text-gray-700">Required</span>
                                    </label>
                                    <label v-if="editingField.type === 'file'" class="flex items-center gap-2 cursor-pointer select-none">
                                        <input v-model="editingField.multiple" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                        <span class="text-xs font-bold text-gray-700">Multiple files</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input v-model="editingField._showCondition" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                        <span class="text-xs font-bold text-gray-700">Conditional</span>
                                    </label>
                                </div>
                                <!-- Conditional config -->
                                <div v-if="editingField._showCondition" class="flex gap-2 items-center">
                                    <span class="text-xs text-gray-500 font-medium shrink-0">Show when</span>
                                    <select v-model="editingField._conditionField" class="flex-1 rounded-xl border-gray-200 text-xs bg-white focus:ring-indigo-500 focus:border-indigo-500">
                                        <option value="">-- select field --</option>
                                        <option v-for="k in fieldKeys" :key="k" :value="k">{{ k }}</option>
                                    </select>
                                    <span class="text-xs text-gray-500 shrink-0">=</span>
                                    <input v-model="editingField._conditionValue" type="text" placeholder="value" class="flex-1 rounded-xl border-gray-200 text-xs bg-white focus:ring-indigo-500 focus:border-indigo-500" />
                                </div>
                                <!-- Help text -->
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-1">Help Text</label>
                                    <input v-model="editingField.help_text" type="text" placeholder="Optional hint shown below the field" class="w-full rounded-xl border-gray-200 text-sm bg-white focus:ring-indigo-500 focus:border-indigo-500" />
                                </div>
                                <div class="flex justify-end gap-2 pt-1">
                                    <button @click="editingField = null" type="button" class="px-4 py-2 text-xs font-bold text-gray-600 bg-white rounded-xl border border-gray-200 hover:bg-gray-50 transition-colors">Cancel</button>
                                    <button @click="saveField" type="button" class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors">{{ editingIndex === null ? 'Add Field' : 'Update Field' }}</button>
                                </div>
                            </div>
                        </template>

                        <!-- ── LINE ITEMS TAB ── -->
                        <template v-else>
                            <div class="flex items-center justify-between p-4 rounded-2xl bg-teal-50 border border-teal-100">
                                <div>
                                    <p class="text-sm font-bold text-teal-900">Enable Line Items Table</p>
                                    <p class="text-xs text-teal-600 mt-0.5">Add a repeating-row table section (e.g. multiple SKUs, BOM lines)</p>
                                </div>
                                <button type="button" @click="schema.has_items = !schema.has_items"
                                    :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none', schema.has_items ? 'bg-teal-600' : 'bg-gray-200']">
                                    <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow', schema.has_items ? 'translate-x-6' : 'translate-x-1']" />
                                </button>
                            </div>

                            <template v-if="schema.has_items">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-1">Row Label</label>
                                    <input v-model="schema.item_label" type="text" placeholder="e.g. Item, SKU, BOM Line" class="w-full rounded-xl border-gray-200 text-sm focus:ring-teal-500 focus:border-teal-500" />
                                </div>

                                <!-- Column list -->
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-2">Columns</label>
                                    <div v-if="schema.items_columns.length" class="space-y-2 mb-3">
                                        <div v-for="(col, idx) in schema.items_columns" :key="idx"
                                            class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100 group">
                                            <div class="flex-1 min-w-0">
                                                <span class="text-sm font-bold text-gray-900">{{ col.label }}</span>
                                                <span class="ml-2 text-[10px] font-mono bg-gray-200 text-gray-600 rounded px-1.5 py-0.5">{{ col.key }}</span>
                                                <span class="ml-1 text-[10px] bg-teal-50 text-teal-700 font-bold rounded px-1.5 py-0.5 border border-teal-100">{{ col.type }}</span>
                                                <span v-if="col.required" class="ml-1 text-[10px] bg-red-50 text-red-600 font-bold rounded px-1.5 py-0.5 border border-red-100">Required</span>
                                            </div>
                                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                                                <button @click="openEditItemCol(idx)" class="p-1.5 text-teal-600 hover:bg-teal-50 rounded-lg transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                </button>
                                                <button @click="removeItemCol(idx)" class="p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="text-xs text-gray-400 mb-3">No columns defined yet.</p>

                                    <button @click="openAddItemCol" class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border-2 border-dashed border-teal-200 text-teal-500 hover:border-teal-400 hover:bg-teal-50 text-sm font-bold transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                        Add Column
                                    </button>
                                </div>

                                <!-- Column editor -->
                                <div v-if="editingItemCol" class="p-4 bg-teal-50 rounded-2xl border border-teal-100 space-y-3">
                                    <h4 class="text-sm font-black text-teal-900">{{ editingItemColIndex === null ? 'New Column' : 'Edit Column' }}</h4>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-1">Label *</label>
                                            <input v-model="editingItemCol.label" type="text" placeholder="e.g. Product Name" class="w-full rounded-xl border-gray-200 text-sm bg-white focus:ring-teal-500 focus:border-teal-500" @input="editingItemColIndex === null && (editingItemCol.key = slugify(editingItemCol.label))" />
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-1">Key</label>
                                            <input v-model="editingItemCol.key" type="text" class="w-full rounded-xl border-gray-200 text-sm font-mono bg-white focus:ring-teal-500 focus:border-teal-500" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-2">Type</label>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button v-for="t in ITEM_COL_TYPES" :key="t" type="button" @click="editingItemCol.type = t"
                                                :class="['px-3 py-1.5 rounded-xl text-xs font-bold transition-colors', editingItemCol.type === t ? 'bg-teal-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:border-teal-300']">
                                                {{ t }}
                                            </button>
                                        </div>
                                    </div>
                                    <!-- Options for select type -->
                                    <div v-if="editingItemCol.type === 'select'">
                                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-wider mb-2">Options</label>
                                        <div class="space-y-1.5">
                                            <div v-for="(opt, oi) in editingItemCol.options" :key="oi" class="flex gap-2 items-center">
                                                <input v-model="opt.label" placeholder="Label" class="flex-1 rounded-xl border-gray-200 text-xs bg-white" />
                                                <input v-model="opt.value" placeholder="Value" class="flex-1 rounded-xl border-gray-200 text-xs font-mono bg-white" />
                                                <button @click="editingItemCol.options.splice(oi, 1)" class="text-rose-400 hover:text-rose-600 p-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                        <button @click="editingItemCol.options.push({ label: '', value: '' })" class="mt-1.5 text-xs text-teal-600 font-bold hover:underline">+ Add option</button>
                                    </div>
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input v-model="editingItemCol.required" type="checkbox" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                                        <span class="text-xs font-bold text-gray-700">Required</span>
                                    </label>
                                    <div class="flex justify-end gap-2">
                                        <button @click="editingItemCol = null" type="button" class="px-4 py-2 text-xs font-bold text-gray-600 bg-white rounded-xl border border-gray-200 hover:bg-gray-50 transition-colors">Cancel</button>
                                        <button @click="saveItemCol" type="button" class="px-4 py-2 text-xs font-bold text-white bg-teal-600 rounded-xl hover:bg-teal-700 transition-colors">{{ editingItemColIndex === null ? 'Add Column' : 'Update Column' }}</button>
                                    </div>
                                </div>
                            </template>
                        </template>
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-between items-center p-7 border-t border-gray-100 shrink-0">
                        <p class="text-xs text-gray-400">Changes affect new form submissions only.</p>
                        <div class="flex gap-3">
                            <button @click="emit('close')" type="button" class="px-5 py-2.5 text-sm font-bold text-gray-600 bg-gray-100 rounded-2xl hover:bg-gray-200 transition-colors">Cancel</button>
                            <button @click="saveSchema" :disabled="isSaving" type="button" class="px-6 py-2.5 text-sm font-black text-white bg-indigo-600 rounded-2xl hover:bg-indigo-700 shadow-lg hover:shadow-indigo-200 transition-all disabled:opacity-60">
                                {{ isSaving ? 'Saving…' : 'Save Schema' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
