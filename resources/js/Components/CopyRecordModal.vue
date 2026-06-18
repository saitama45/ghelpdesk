<script setup>
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    show: Boolean,
    sourceRecord: Object,
    sourceType: String, // 'sap', 'pos', 'dynamic'
})

const emit = defineEmits(['close'])

const { showError } = useToast()
const isLoading = ref(false)
const step = ref('select') // 'select' | 'map'
const targets = ref({ sap_types: [], pos_types: [], form_definitions: [] })
const selectedTarget = ref(null) // { type: 'sap'|'pos'|'dynamic', item: {...} }

const fieldMappings = ref({})       // { destKey: srcKey | '__none__' | '__custom__' }
const customFieldValues = ref({})   // { destKey: string }
const itemColMappings = ref({})     // { destColKey: srcColKey | '__none__' | '__custom__' }
const customItemColValues = ref({}) // { destColKey: string }

// ── Source schema helpers ─────────────────────────────────────────────────────

const sourceSchema = computed(() => {
    if (props.sourceType === 'sap' || props.sourceType === 'pos') {
        return props.sourceRecord?.request_type?.form_schema ?? {}
    }
    if (props.sourceType === 'dynamic') {
        return props.sourceRecord?.definition?.form_schema ?? {}
    }
    return {}
})

const sourceFields = computed(() => sourceSchema.value.fields ?? [])
const sourceItemColumns = computed(() => sourceSchema.value.items_columns ?? [])

const sourceFormData = computed(() => {
    if (props.sourceType === 'dynamic') return props.sourceRecord?.data ?? {}
    return props.sourceRecord?.form_data ?? {}
})

const sourceItems = computed(() => {
    if (props.sourceType === 'sap') {
        return (props.sourceRecord?.items ?? []).map(i => i.item_data ?? {})
    }
    if (props.sourceType === 'pos') {
        const schemaItems = props.sourceRecord?.form_data?.items
        if (schemaItems?.length) return schemaItems
        return (props.sourceRecord?.details ?? []).map(d => ({ ...d }))
    }
    if (props.sourceType === 'dynamic') {
        return props.sourceRecord?.data?.items ?? []
    }
    return []
})

// ── Destination schema helpers ────────────────────────────────────────────────

const destSchema = computed(() => selectedTarget.value?.item?.form_schema ?? {})
const destFields = computed(() => destSchema.value.fields ?? [])
const destItemColumns = computed(() => destSchema.value.items_columns ?? [])
const destHasItems = computed(() => !!(destSchema.value.has_items || destItemColumns.value.length > 0))

// ── Display helpers ───────────────────────────────────────────────────────────

const getSourceDisplayValue = (key) => {
    const val = sourceFormData.value[key]
    if (val === null || val === undefined || val === '') return '—'
    if (Array.isArray(val)) return val.join(', ')
    if (typeof val === 'object') return Object.values(val).filter(Boolean).join(', ')
    return String(val)
}

const getSampleItemValue = (colKey) => {
    const first = sourceItems.value[0]
    if (!first) return '—'
    const val = first[colKey]
    if (val === null || val === undefined || val === '') return '—'
    return String(val)
}

const sourceFieldLabel = (key) => {
    const f = sourceFields.value.find(f => f.key === key)
    return f ? f.label : key
}

const sourceItemColLabel = (key) => {
    const c = sourceItemColumns.value.find(c => c.key === key)
    return c ? c.label : key
}

// ── Fetch targets ─────────────────────────────────────────────────────────────

const fetchTargets = async () => {
    try {
        const response = await axios.get(route('copy.targets'))
        targets.value = response.data
    } catch {
        showError('Failed to load copy targets.')
    }
}

onMounted(fetchTargets)

// ── Step transition ───────────────────────────────────────────────────────────

const selectTarget = (type, item) => {
    selectedTarget.value = { type, item }

    const schema = item.form_schema ?? {}
    const destFieldsList = schema.fields ?? []
    const destColsList = schema.items_columns ?? []

    // Auto-match fields by key; fall back to '__none__'
    const fm = {}
    const cfv = {}
    for (const df of destFieldsList) {
        const match = sourceFields.value.find(sf => sf.key === df.key)
        fm[df.key] = match ? match.key : '__none__'
        cfv[df.key] = ''
    }
    fieldMappings.value = fm
    customFieldValues.value = cfv

    // Auto-match item columns by key
    const icm = {}
    const cicv = {}
    for (const dc of destColsList) {
        const match = sourceItemColumns.value.find(sc => sc.key === dc.key)
        icm[dc.key] = match ? match.key : '__none__'
        cicv[dc.key] = ''
    }
    itemColMappings.value = icm
    customItemColValues.value = cicv

    step.value = 'map'
}

const backToSelect = () => {
    step.value = 'select'
    selectedTarget.value = null
}

// ── Build payload from mappings ───────────────────────────────────────────────

const buildPayload = () => {
    const record = props.sourceRecord
    const payload = {
        company_id: record.company_id,
        source_user_id: record.user_id,
        requester_name: record.requester_name || record.user?.name || '',
        requester_email: record.requester_email || record.user?.email || '',
        form_data: {},
        items: [],
    }

    // Map header fields
    for (const [destKey, srcKey] of Object.entries(fieldMappings.value)) {
        if (srcKey === '__none__') continue
        if (srcKey === '__custom__') {
            payload.form_data[destKey] = customFieldValues.value[destKey] ?? ''
        } else {
            payload.form_data[destKey] = sourceFormData.value[srcKey] ?? ''
        }
    }

    // Map line items
    if (destHasItems.value && sourceItems.value.length > 0) {
        payload.items = sourceItems.value.map(srcItem => {
            const destItem = {}
            for (const [destColKey, srcColKey] of Object.entries(itemColMappings.value)) {
                if (srcColKey === '__none__') continue
                if (srcColKey === '__custom__') {
                    destItem[destColKey] = customItemColValues.value[destColKey] ?? ''
                } else {
                    destItem[destColKey] = srcItem[srcColKey] ?? ''
                }
            }
            return destItem
        })
    } else if (props.sourceType === 'sap' && selectedTarget.value?.type === 'sap') {
        // Same-type SAP copy: pass items directly even without schema mapping
        payload.items = sourceItems.value
    }

    // POS-specific fields
    if (selectedTarget.value?.type === 'pos') {
        payload.launch_date = record.launch_date ?? new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10)
        payload.stores_covered = record.stores_covered ? [...record.stores_covered] : ['Not Specified']
    }

    return payload
}

const confirmCopy = async () => {
    isLoading.value = true
    const { type, item } = selectedTarget.value
    const payload = buildPayload()

    try {
        const response = await axios.post(route('copy.transfer'), {
            target_type: type,
            target_id: type === 'dynamic' ? item.slug : item.id,
            payload,
        })
        router.visit(response.data.redirect_url)
    } catch (error) {
        showError(error.response?.data?.message || 'Failed to copy record.')
    } finally {
        isLoading.value = false
    }
}

const handleClose = () => {
    step.value = 'select'
    selectedTarget.value = null
    emit('close')
}
</script>

<template>
    <transition
        enter-active-class="duration-300 ease-out"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="duration-200 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
    >
        <div v-if="show" class="fixed inset-0 z-[60] overflow-y-auto flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="handleClose"></div>

            <div class="relative bg-white rounded-3xl shadow-2xl w-full max-w-5xl p-0 border border-gray-100 transform transition-all max-h-[92vh] overflow-hidden flex flex-col dark:bg-gray-800 dark:border-gray-700">

                <!-- Header -->
                <div class="flex items-center justify-between px-8 py-6 border-b border-gray-100 shrink-0 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <!-- Breadcrumb step indicator -->
                        <button v-if="step === 'map'" type="button" @click="backToSelect"
                            class="p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all dark:text-gray-400 dark:hover:bg-gray-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <div>
                            <h3 class="text-xl font-black text-gray-900 tracking-tight dark:text-gray-100">
                                {{ step === 'select' ? 'Copy Record' : 'Map Fields' }}
                            </h3>
                            <p class="text-xs text-gray-400 font-medium mt-0.5 dark:text-gray-400">
                                <span v-if="step === 'select'">Select a destination module to copy this record to</span>
                                <span v-else>
                                    Copying to <span class="font-black text-gray-700 dark:text-gray-300">{{ selectedTarget?.item?.name }}</span>
                                    — map source fields to destination fields
                                </span>
                            </p>
                        </div>
                    </div>
                    <button @click="handleClose" class="p-2 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-full transition-all dark:text-gray-400 dark:hover:bg-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Step indicators -->
                <div class="flex items-center gap-2 px-8 py-3 bg-gray-50/70 border-b border-gray-100 shrink-0 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-black"
                            :class="step === 'select' ? 'bg-indigo-600 text-white' : 'bg-emerald-500 text-white'">
                            {{ step === 'select' ? '1' : '✓' }}
                        </span>
                        <span class="text-xs font-bold" :class="step === 'select' ? 'text-indigo-600' : 'text-emerald-600'">
                            Select Destination
                        </span>
                    </div>
                    <div class="h-px flex-1 bg-gray-200 mx-1 dark:bg-gray-700"></div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-black"
                            :class="step === 'map' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-400'">2</span>
                        <span class="text-xs font-bold" :class="step === 'map' ? 'text-indigo-600' : 'text-gray-400'">
                            Map Fields
                        </span>
                    </div>
                </div>

                <!-- ── STEP 1: Select Destination ── -->
                <div v-if="step === 'select'" class="flex-1 overflow-y-auto p-8 custom-scrollbar">
                    <div class="space-y-8">

                        <!-- SAP Targets -->
                        <section v-if="sourceType !== 'sap'">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="h-8 w-8 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                                    </svg>
                                </div>
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] dark:text-gray-400">SAP Requests</h4>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <button v-for="type in targets.sap_types" :key="'sap-'+type.id"
                                    @click="selectTarget('sap', type)"
                                    class="p-4 bg-gray-50 hover:bg-blue-600 hover:text-white rounded-2xl border border-transparent text-left transition-all group dark:bg-gray-900/50">
                                    <p class="text-xs font-black">{{ type.name }}</p>
                                    <p class="text-[10px] mt-1 opacity-50 group-hover:opacity-80">
                                        {{ (type.form_schema?.fields?.length ?? 0) }} fields
                                        <template v-if="type.form_schema?.items_columns?.length">
                                            · {{ type.form_schema.items_columns.length }} item cols
                                        </template>
                                    </p>
                                </button>
                            </div>
                        </section>

                        <!-- POS Targets -->
                        <section v-if="sourceType !== 'pos'">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="h-8 w-8 bg-purple-50 rounded-xl flex items-center justify-center text-purple-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] dark:text-gray-400">POS Requests</h4>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <button v-for="type in targets.pos_types" :key="'pos-'+type.id"
                                    @click="selectTarget('pos', type)"
                                    class="p-4 bg-gray-50 hover:bg-purple-600 hover:text-white rounded-2xl border border-transparent text-left transition-all group dark:bg-gray-900/50">
                                    <p class="text-xs font-black">{{ type.name }}</p>
                                    <p class="text-[10px] mt-1 opacity-50 group-hover:opacity-80">
                                        {{ (type.form_schema?.fields?.length ?? 0) }} fields
                                        <template v-if="type.form_schema?.items_columns?.length">
                                            · {{ type.form_schema.items_columns.length }} item cols
                                        </template>
                                    </p>
                                </button>
                            </div>
                        </section>

                        <!-- Dynamic Form Targets -->
                        <section v-if="sourceType !== 'dynamic'">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="h-8 w-8 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                </div>
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] dark:text-gray-400">Dynamic Forms</h4>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <button v-for="form in targets.form_definitions" :key="'dyn-'+form.id"
                                    @click="selectTarget('dynamic', form)"
                                    class="p-4 bg-gray-50 hover:bg-indigo-600 hover:text-white rounded-2xl border border-transparent text-left transition-all group dark:bg-gray-900/50">
                                    <p class="text-xs font-black">{{ form.name }}</p>
                                    <p class="text-[10px] mt-1 opacity-50 group-hover:opacity-80">
                                        {{ (form.form_schema?.fields?.length ?? 0) }} fields
                                        <template v-if="form.form_schema?.items_columns?.length">
                                            · {{ form.form_schema.items_columns.length }} item cols
                                        </template>
                                    </p>
                                </button>
                            </div>
                        </section>

                    </div>
                </div>

                <!-- ── STEP 2: Map Fields ── -->
                <div v-if="step === 'map'" class="flex-1 overflow-y-auto custom-scrollbar">

                    <!-- Source info bar -->
                    <div class="px-8 py-3 bg-amber-50 border-b border-amber-100 flex items-center gap-2 shrink-0">
                        <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-xs text-amber-700 font-medium">
                            Fields auto-matched by key name. Adjust any that don't match, or select <strong>"Custom Value"</strong> to type a value manually.
                        </p>
                    </div>

                    <div class="p-8 space-y-10">

                        <!-- ── Header Fields ── -->
                        <div v-if="destFields.length > 0">
                            <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-4 flex items-center gap-2 dark:text-gray-400">
                                <span class="inline-block w-2 h-2 rounded-full bg-indigo-400"></span>
                                Header Fields ({{ destFields.length }})
                            </h4>

                            <div class="rounded-2xl border border-gray-100 overflow-hidden dark:border-gray-700">
                                <!-- Table header -->
                                <div class="grid grid-cols-[1fr_1fr_1fr] bg-gray-50 px-4 py-2.5 border-b border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Destination Field</span>
                                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Map From Source</span>
                                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Preview / Custom Value</span>
                                </div>

                                <div class="divide-y divide-gray-50">
                                    <div v-for="df in destFields" :key="df.key"
                                        class="grid grid-cols-[1fr_1fr_1fr] px-4 py-3 items-center hover:bg-gray-50/60 transition-colors">

                                        <!-- Dest field label -->
                                        <div>
                                            <p class="text-xs font-bold text-gray-800 dark:text-gray-200">{{ df.label }}</p>
                                            <p class="text-[10px] text-gray-400 font-mono dark:text-gray-400">{{ df.key }}</p>
                                        </div>

                                        <!-- Mapping dropdown -->
                                        <div class="pr-4">
                                            <select v-model="fieldMappings[df.key]"
                                                class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-700 focus:border-indigo-400 focus:ring-0 bg-white dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">
                                                <option value="__none__">— Skip this field —</option>
                                                <option v-for="sf in sourceFields" :key="sf.key" :value="sf.key">
                                                    {{ sf.label }} ({{ sf.key }})
                                                </option>
                                                <option value="__custom__">✏️ Custom value...</option>
                                            </select>
                                        </div>

                                        <!-- Preview / custom input -->
                                        <div>
                                            <span v-if="fieldMappings[df.key] === '__none__'"
                                                class="text-[10px] text-gray-300 italic">not copied</span>
                                            <input v-else-if="fieldMappings[df.key] === '__custom__'"
                                                v-model="customFieldValues[df.key]"
                                                type="text"
                                                placeholder="Enter value..."
                                                class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-700 focus:border-indigo-400 focus:ring-0 dark:text-gray-300 dark:border-gray-700" />
                                            <span v-else
                                                class="text-xs text-gray-600 font-medium bg-gray-100 px-2 py-1 rounded-lg block truncate max-w-full dark:bg-gray-800 dark:text-gray-300"
                                                :title="getSourceDisplayValue(fieldMappings[df.key])">
                                                {{ getSourceDisplayValue(fieldMappings[df.key]) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Item Columns ── -->
                        <div v-if="destHasItems">
                            <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 flex items-center gap-2 dark:text-gray-400">
                                <span class="inline-block w-2 h-2 rounded-full bg-teal-400"></span>
                                Line Item Columns ({{ destItemColumns.length }})
                            </h4>
                            <p class="text-[10px] text-gray-400 mb-4 dark:text-gray-400">
                                Source has <strong class="text-gray-600 dark:text-gray-300">{{ sourceItems.length }}</strong> item(s).
                                Column values shown are from the 1st item as a preview.
                            </p>

                            <div class="rounded-2xl border border-gray-100 overflow-hidden dark:border-gray-700">
                                <div class="grid grid-cols-[1fr_1fr_1fr] bg-gray-50 px-4 py-2.5 border-b border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Destination Column</span>
                                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Map From Source</span>
                                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Sample Value</span>
                                </div>

                                <div class="divide-y divide-gray-50">
                                    <div v-for="dc in destItemColumns" :key="dc.key"
                                        class="grid grid-cols-[1fr_1fr_1fr] px-4 py-3 items-center hover:bg-gray-50/60 transition-colors">

                                        <div>
                                            <p class="text-xs font-bold text-gray-800 dark:text-gray-200">{{ dc.label }}</p>
                                            <p class="text-[10px] text-gray-400 font-mono dark:text-gray-400">{{ dc.key }}</p>
                                        </div>

                                        <div class="pr-4">
                                            <select v-model="itemColMappings[dc.key]"
                                                class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-700 focus:border-teal-400 focus:ring-0 bg-white dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">
                                                <option value="__none__">— Skip this column —</option>
                                                <option v-for="sc in sourceItemColumns" :key="sc.key" :value="sc.key">
                                                    {{ sc.label }} ({{ sc.key }})
                                                </option>
                                                <option value="__custom__">✏️ Same value for all rows...</option>
                                            </select>
                                        </div>

                                        <div>
                                            <span v-if="itemColMappings[dc.key] === '__none__'"
                                                class="text-[10px] text-gray-300 italic">not copied</span>
                                            <input v-else-if="itemColMappings[dc.key] === '__custom__'"
                                                v-model="customItemColValues[dc.key]"
                                                type="text"
                                                placeholder="Enter value for all rows..."
                                                class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-700 focus:border-teal-400 focus:ring-0 dark:text-gray-300 dark:border-gray-700" />
                                            <span v-else
                                                class="text-xs text-gray-600 font-medium bg-gray-100 px-2 py-1 rounded-lg block truncate max-w-full dark:bg-gray-800 dark:text-gray-300"
                                                :title="getSampleItemValue(itemColMappings[dc.key])">
                                                {{ getSampleItemValue(itemColMappings[dc.key]) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Items preview table -->
                            <div v-if="sourceItems.length > 0 && destItemColumns.length > 0" class="mt-4">
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 dark:text-gray-400">
                                    Items Preview ({{ sourceItems.length }} row{{ sourceItems.length !== 1 ? 's' : '' }} will be copied)
                                </p>
                                <div class="overflow-x-auto rounded-xl border border-dashed border-gray-200 dark:border-gray-700">
                                    <table class="w-full text-[10px]">
                                        <thead>
                                            <tr class="bg-gray-50 dark:bg-gray-900/50">
                                                <th v-for="dc in destItemColumns" :key="dc.key"
                                                    class="px-3 py-2 text-left font-black text-gray-400 uppercase tracking-widest whitespace-nowrap dark:text-gray-400">
                                                    {{ dc.label }}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            <tr v-for="(srcItem, i) in sourceItems.slice(0, 5)" :key="i"
                                                class="hover:bg-gray-50/60">
                                                <td v-for="dc in destItemColumns" :key="dc.key"
                                                    class="px-3 py-2 text-gray-600 font-medium whitespace-nowrap max-w-[120px] truncate dark:text-gray-300">
                                                    <template v-if="itemColMappings[dc.key] === '__none__'">
                                                        <span class="text-gray-300 italic">—</span>
                                                    </template>
                                                    <template v-else-if="itemColMappings[dc.key] === '__custom__'">
                                                        <span class="text-indigo-500 italic">{{ customItemColValues[dc.key] || '...' }}</span>
                                                    </template>
                                                    <template v-else>
                                                        {{ srcItem[itemColMappings[dc.key]] ?? '—' }}
                                                    </template>
                                                </td>
                                            </tr>
                                            <tr v-if="sourceItems.length > 5">
                                                <td :colspan="destItemColumns.length" class="px-3 py-2 text-center text-gray-400 italic dark:text-gray-400">
                                                    + {{ sourceItems.length - 5 }} more rows...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- No source items notice -->
                            <div v-else-if="sourceItems.length === 0" class="mt-3 p-3 bg-amber-50 rounded-xl border border-amber-100">
                                <p class="text-xs text-amber-700 font-medium">
                                    The source record has no line items. Destination item columns will be empty.
                                </p>
                            </div>
                        </div>

                        <!-- No schemas notice -->
                        <div v-if="destFields.length === 0 && !destHasItems"
                            class="py-8 text-center text-gray-400 dark:text-gray-400">
                            <p class="text-sm font-bold">This destination has no schema-defined fields.</p>
                            <p class="text-xs mt-1">The record will be created with the requester and company info only.</p>
                        </div>

                    </div>
                </div>

                <!-- ── Footer ── -->
                <div v-if="step === 'map'" class="px-8 py-5 border-t border-gray-100 bg-white flex items-center justify-between shrink-0 dark:bg-gray-800 dark:border-gray-700">
                    <button type="button" @click="backToSelect"
                        class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-xl transition-all dark:text-gray-300 dark:hover:bg-gray-700">
                        ← Back
                    </button>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <p class="text-xs font-bold text-gray-700 dark:text-gray-300">Ready to copy to <span class="text-indigo-600">{{ selectedTarget?.item?.name }}</span></p>
                            <p class="text-[10px] text-gray-400 dark:text-gray-400">
                                {{ Object.values(fieldMappings).filter(v => v !== '__none__').length }} field(s) mapped
                                <template v-if="destHasItems">
                                    · {{ sourceItems.length }} item row(s)
                                </template>
                            </p>
                        </div>
                        <button type="button" @click="confirmCopy"
                            class="px-6 py-2.5 bg-indigo-600 text-white text-sm font-black rounded-xl hover:bg-indigo-700 transition-all shadow-md shadow-indigo-100 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V17a2 2 0 01-2 2h-2"/>
                            </svg>
                            Save & Copy Record
                        </button>
                    </div>
                </div>

                <!-- Loading overlay -->
                <div v-if="isLoading" class="absolute inset-0 bg-white/70 backdrop-blur-[2px] flex items-center justify-center z-10 rounded-3xl">
                    <div class="flex flex-col items-center">
                        <svg class="animate-spin h-10 w-10 text-indigo-600 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-sm font-black text-gray-900 uppercase tracking-widest dark:text-gray-100">Saving to Database...</span>
                    </div>
                </div>

            </div>
        </div>
    </transition>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>
