<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import { useToast } from '@/Composables/useToast'
import DynamicFormRenderer from '@/Components/DynamicFormRenderer.vue'

const props = defineProps({
    companies: Array,
    requestTypes: Array,
    stores: Array,
    priceTypes: Array,
    categories: Array,
    sub_categories: Array,
    posRequest: Object,
    isPublic: {
        type: Boolean,
        default: false
    }
})

const { showError, showSuccess } = useToast()

// Inline confirmation dialog state
const showConfirm = ref(false)
let resolveConfirm = null
const openConfirm = () => new Promise(resolve => { resolveConfirm = resolve; showConfirm.value = true })
const onConfirmYes = () => { showConfirm.value = false; resolveConfirm?.(true) }
const onConfirmNo  = () => { showConfirm.value = false; resolveConfirm?.(false) }

const isEditing = computed(() => !!props.posRequest)

const form = useForm({
    requester_name: props.posRequest?.requester_name ?? '',
    requester_email: props.posRequest?.requester_email ?? '',
    company_id: props.posRequest?.company_id ?? '',
    request_type_id: props.posRequest?.request_type_id ?? '',
    launch_date: props.posRequest?.launch_date ?? new Date().toISOString().slice(0, 10),
    stores_covered: props.posRequest?.stores_covered ?? [],
    form_data: (() => {
        // Strip the 'items' key that was merged in by the service — it belongs in details, not form_data
        const fd = { ...(props.posRequest?.form_data ?? {}) }
        delete fd.items
        return fd
    })(),
    details: (() => {
        // Schema-driven items live in form_data.items; hard-coded items live in posRequest.details
        const schemaItems = props.posRequest?.form_data?.items
        if (schemaItems && schemaItems.length > 0) return schemaItems

        if (props.posRequest?.details?.length > 0) {
            return props.posRequest.details.map(d => ({
                product_name: d.product_name,
                pos_name: d.pos_name,
                remarks_mechanics: d.remarks_mechanics,
                price_type: d.price_type,
                price_amount: d.price_amount,
                category: d.category,
                sub_category: d.sub_category,
                validity_date: d.validity_date,
                item_code: d.item_code,
                sc: d.sc,
                local_tax: d.local_tax,
                mgr_meal: d.mgr_meal === 'Yes' || d.mgr_meal === true,
                printer: d.printer
            }))
        }

        return [{
            product_name: '', pos_name: '', remarks_mechanics: '',
            price_type: 'In-Store', price_amount: '', category: '',
            sub_category: '', validity_date: '', item_code: '',
            sc: '', local_tax: '', mgr_meal: false, printer: ''
        }]
    })()
})

// Autocomplete Logic
const activeSuggest = ref({ index: null, type: null })
const filterSuggestions = (type, val) => {
    if (!val) return []
    const suggestions = type === 'category' ? props.categories : props.sub_categories
    return suggestions.filter(s => s.toLowerCase().includes(val.toLowerCase())).slice(0, 10)
}

const selectSuggest = (index, type, val) => {
    form.details[index][type] = val
    activeSuggest.value = { index: null, type: null }
}

// Store Selection Logic
const storeSearch = ref('')
const selectedCluster = ref('')
const selectedBrand = ref('')

const clusterOptions = computed(() => {
    return [...new Set(
        (props.stores ?? [])
            .map(store => store.cluster?.name || store.cluster_name || '')
            .filter(Boolean)
    )].sort((a, b) => a.localeCompare(b))
})

const brandOptions = computed(() => {
    return [...new Set(
        (props.stores ?? [])
            .map(store => store.brand || '')
            .filter(Boolean)
    )].sort((a, b) => a.localeCompare(b))
})

const filteredStores = computed(() => {
    const search = storeSearch.value.trim().toLowerCase()

    return (props.stores ?? []).filter(store => {
        const clusterName = store.cluster?.name || store.cluster_name || ''
        const brandName = store.brand || ''
        const matchesCluster = !selectedCluster.value || clusterName === selectedCluster.value
        const matchesBrand = !selectedBrand.value || brandName === selectedBrand.value
        const matchesSearch = !search
            || store.code.toLowerCase().includes(search)
            || store.name.toLowerCase().includes(search)
            || clusterName.toLowerCase().includes(search)
            || brandName.toLowerCase().includes(search)

        return matchesCluster && matchesBrand && matchesSearch
    }).slice(0, search ? 100 : 150)
})

const autoSelectedStoreCodes = computed(() => filteredStores.value.map(store => store.code))
const visibleSelectedStoreCodes = computed(() => form.stores_covered.slice(0, 10))
const hiddenSelectedStoreCount = computed(() => Math.max(form.stores_covered.length - visibleSelectedStoreCodes.value.length, 0))

const clearStoreFilters = () => {
    storeSearch.value = ''
    selectedCluster.value = ''
    selectedBrand.value = ''
    if (!isAllStores.value) {
        form.stores_covered = []
    }
}

watch([selectedCluster, selectedBrand], ([cluster, brand], [prevCluster, prevBrand]) => {
    if (cluster === prevCluster && brand === prevBrand) return
    if (isAllStores.value) form.stores_covered = []

    if (!cluster && !brand) return

    form.stores_covered = autoSelectedStoreCodes.value
})

const isAllStores = computed(() => form.stores_covered.includes('all'))

const toggleAllStores = () => {
    if (isAllStores.value) {
        form.stores_covered = []
    } else {
        form.stores_covered = ['all']
    }
}

const toggleStore = (code) => {
    if (isAllStores.value) form.stores_covered = []
    const index = form.stores_covered.indexOf(code)
    if (index === -1) {
        form.stores_covered.push(code)
    } else {
        form.stores_covered.splice(index, 1)
    }
}

const removeStoreTag = (code) => {
    const index = form.stores_covered.indexOf(code)
    if (index !== -1) form.stores_covered.splice(index, 1)
}

// Line Item Logic
const addRow = () => {
    form.details.push({
        product_name: '',
        pos_name: '',
        remarks_mechanics: '',
        price_type: 'In-Store',
        price_amount: '',
        category: '',
        sub_category: '',
        validity_date: '',
        item_code: '',
        sc: '',
        local_tax: '',
        mgr_meal: '',
        printer: ''
    })
}

const removeRow = (index) => {
    if (form.details.length > 1) form.details.splice(index, 1)
}

const getError = (key) => form.errors[key]
const allErrors = computed(() => [...Object.values(form.errors).flat(), ...lineItemErrors.value])
const errorBanner = ref(null)
const scrollToErrors = () => nextTick(() => errorBanner.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))

// Disable submit when a line-items section is shown but no items have been added yet
const canSubmit = computed(() => !schemaHasItems || form.details.length > 0)

// Client-side validation errors for schema line item required fields
const lineItemErrors = ref([])

const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const emailFormatError = computed(() =>
    form.requester_email && !emailRegex.test(form.requester_email)
        ? 'Please enter a valid email address.'
        : null
)

// ── Schema-driven ────────────────────────────────────────────────────────────
const selectedRequestType = computed(() =>
    props.requestTypes?.find(rt => rt.id == form.request_type_id)
)
// Regular (non-tabular) fields defined in the schema
const schemaFields = computed(() => selectedRequestType.value?.form_schema?.fields ?? [])
// Tabular items section
const schemaHasItems = computed(() => !!selectedRequestType.value?.form_schema?.has_items)
const schemaItemsColumns = computed(() => selectedRequestType.value?.form_schema?.items_columns ?? [])
const schemaItemLabel = computed(() => selectedRequestType.value?.form_schema?.item_label ?? 'Item')
// True when ANY schema content exists for this request type
const useSchema = computed(() => schemaFields.value.length > 0 || schemaHasItems.value)
// True when the schema defines a tabular items section
const useSchemaItems = computed(() => schemaHasItems.value && schemaItemsColumns.value.length > 0)

// Reset form_data and details when request type changes
watch(() => form.request_type_id, () => {
    form.form_data = {}
    lineItemErrors.value = []

    const rt = props.requestTypes?.find(rt => rt.id == form.request_type_id)
    const hasSchema = (rt?.form_schema?.fields?.length > 0) || !!rt?.form_schema?.has_items
    if (hasSchema) {
        // Schema type: clear the stale hard-coded row so product_name/pos_name are not submitted
        form.details = []
    } else {
        // Non-schema type: restore a blank hard-coded row for the fallback card
        form.details = [{
            product_name: '', pos_name: '', remarks_mechanics: '',
            price_type: 'In-Store', price_amount: '', category: '',
            sub_category: '', validity_date: '', item_code: '',
            sc: '', local_tax: '', mgr_meal: false, printer: ''
        }]
    }
})

const submit = async () => {
    if (emailFormatError.value) return

    // Validate required fields in schema line items
    lineItemErrors.value = []
    if (schemaHasItems && schemaItemsColumns.value.length > 0) {
        form.details.forEach((row, rowIdx) => {
            schemaItemsColumns.value.forEach(col => {
                if (col.required && (row[col.key] === '' || row[col.key] === null || row[col.key] === undefined)) {
                    lineItemErrors.value.push(`Item #${rowIdx + 1}: "${col.label}" is required.`)
                }
            })
        })
    }
    if (lineItemErrors.value.length > 0) { scrollToErrors(); return }

    const confirmed = await openConfirm()
    if (!confirmed) return

    form.clearErrors()

    if (props.isPublic) {
        form.post(route('public.pos-requests.store'), {
            onSuccess: () => {
                form.reset()
            },
            onError: () => {
                showError('Please review the errors highlighted below.')
                scrollToErrors()
            }
        })
    } else if (isEditing.value) {
        // PHP does not parse multipart/form-data bodies on real HTTP PUT requests.
        // Use POST + _method:put (Laravel method spoofing) so PHP always parses the body.
        form.transform(data => ({ ...data, _method: 'put' }))
            .post(route('pos-requests.update', props.posRequest.id), {
                onError: () => {
                    showError('Please review the errors highlighted below.')
                    scrollToErrors()
                }
            })
    } else {
        form.post(route('pos-requests.store'), {
            onError: () => {
                showError('Please review the errors highlighted below.')
                scrollToErrors()
            }
        })
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-8">

        <!-- Validation Error Summary -->
        <div v-if="allErrors.length > 0" ref="errorBanner"
             class="bg-rose-50 border-2 border-rose-200 rounded-2xl px-6 py-4 flex gap-4 items-start">
            <svg class="w-5 h-5 text-rose-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                      d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-xs font-black text-rose-700 uppercase tracking-widest mb-1">
                    Please fix {{ allErrors.length }} error(s) before submitting:
                </p>
                <ul class="space-y-0.5">
                    <li v-for="(msg, i) in allErrors" :key="i" class="text-xs text-rose-600 font-medium">• {{ msg }}</li>
                </ul>
            </div>
        </div>

        <!-- Header Card -->
        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 relative overflow-hidden">
            <div class="absolute top-0 right-0 w-96 h-96 bg-indigo-50/50 rounded-full -mr-48 -mt-48 blur-3xl"></div>
            
            <div class="relative z-10">
                <h2 class="text-3xl font-black text-gray-900 tracking-tight mb-10 flex items-center">
                    <span class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center mr-4 shadow-lg shadow-indigo-200">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </span>
                    Header Information
                </h2>

                <div v-if="isPublic" class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Your Name</label>
                        <input v-model="form.requester_name" type="text" required placeholder="John Doe"
                               :class="[getError('requester_name') ? 'border-rose-500 bg-rose-50' : 'bg-gray-50 border-gray-50']"
                               class="w-full border-2 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                        <p v-if="getError('requester_name')" class="text-[10px] text-rose-600 font-bold ml-1">{{ getError('requester_name') }}</p>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Your Email</label>
                        <input v-model="form.requester_email" type="text" required placeholder="john.doe@example.com"
                               :class="[getError('requester_email') || emailFormatError ? 'border-rose-500 bg-rose-50' : 'bg-gray-50 border-gray-50']"
                               class="w-full border-2 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                        <p v-if="emailFormatError" class="text-[10px] text-rose-600 font-bold ml-1">{{ emailFormatError }}</p>
                        <p v-else-if="getError('requester_email')" class="text-[10px] text-rose-600 font-bold ml-1">{{ getError('requester_email') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Company</label>
                        <select v-model="form.company_id" required 
                                :class="[getError('company_id') ? 'border-rose-500 bg-rose-50' : 'bg-gray-50 border-gray-50']"
                                class="w-full border-2 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                            <option value="" disabled>Select Company</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="getError('company_id')" class="text-[10px] text-rose-600 font-bold ml-1">{{ getError('company_id') }}</p>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">POS Request Type</label>
                        <select v-model="form.request_type_id" required 
                                :class="[getError('request_type_id') ? 'border-rose-500 bg-rose-50' : 'bg-gray-50 border-gray-50']"
                                class="w-full border-2 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                            <option value="" disabled>Select Type</option>
                            <option v-for="t in requestTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <p v-if="getError('request_type_id')" class="text-[10px] text-rose-600 font-bold ml-1">{{ getError('request_type_id') }}</p>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Launch Date</label>
                        <input v-model="form.launch_date" type="date" required 
                               :class="[getError('launch_date') ? 'border-rose-500 bg-rose-50' : 'bg-gray-50 border-gray-50']"
                               class="w-full border-2 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                        <p v-if="getError('launch_date')" class="text-[10px] text-rose-600 font-bold ml-1">{{ getError('launch_date') }}</p>
                    </div>
                </div>

                <!-- Optimized Store Selection -->
                <div class="mt-12 p-8 rounded-[2.5rem] border-2 transition-all" 
                     :class="[getError('stores_covered') ? 'bg-rose-50 border-rose-200' : 'bg-gray-50/50 border-gray-100']">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h3 class="text-sm font-black uppercase tracking-widest" :class="getError('stores_covered') ? 'text-rose-900' : 'text-gray-900'">Stores Covered</h3>
                            <p v-if="getError('stores_covered')" class="text-[10px] text-rose-600 font-black uppercase mt-1 animate-pulse">{{ getError('stores_covered') }}</p>
                            <p v-else class="text-[10px] text-gray-400 font-bold uppercase mt-1 italic">Tag specific stores or apply to all</p>
                        </div>
                        <button type="button" @click="toggleAllStores" 
                                :class="isAllStores ? 'bg-indigo-600 text-white shadow-indigo-200' : 'bg-white text-gray-600 border border-gray-200'"
                                class="px-8 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg">
                            {{ isAllStores ? 'Selected: All Stores' : 'Apply to All Stores' }}
                        </button>
                    </div>

                    <div v-if="!isAllStores" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1 mb-2">Filter By Cluster</label>
                                <select v-model="selectedCluster"
                                        class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none shadow-sm">
                                    <option value="">All Clusters</option>
                                    <option v-for="cluster in clusterOptions" :key="cluster" :value="cluster">{{ cluster }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1 mb-2">Filter By Brand</label>
                                <select v-model="selectedBrand"
                                        class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none shadow-sm">
                                    <option value="">All Brands</option>
                                    <option v-for="brand in brandOptions" :key="brand" :value="brand">{{ brand }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row gap-6">
                            <div class="flex-1 relative">
                                <svg class="absolute left-5 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input v-model="storeSearch" type="text" placeholder="Search stores by code, name, cluster, or brand..."
                                       class="w-full bg-white border-2 border-gray-100 rounded-2xl pl-14 pr-6 py-4 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none shadow-sm">
                            </div>
                            
                            <div class="flex-[2] bg-white border-2 rounded-2xl p-4 min-h-[60px] flex flex-wrap gap-2 shadow-sm transition-colors"
                                 :class="getError('stores_covered') ? 'border-rose-300' : 'border-gray-100'">
                                <span v-if="form.stores_covered.length === 0" class="text-xs font-bold text-gray-300 italic flex items-center px-2">No stores selected yet...</span>
                                <button v-for="code in visibleSelectedStoreCodes" :key="code" type="button" @click="removeStoreTag(code)"
                                        class="group inline-flex items-center px-4 py-2 bg-indigo-50 text-indigo-700 rounded-xl text-xs font-black transition-all hover:bg-indigo-600 hover:text-white">
                                    {{ code }}
                                    <svg class="w-3 h-3 ml-2 opacity-50 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <span v-if="hiddenSelectedStoreCount > 0"
                                      class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-600 rounded-xl text-xs font-black uppercase tracking-widest">
                                    +{{ hiddenSelectedStoreCount }}
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                                {{ (selectedCluster || selectedBrand) ? 'Auto-selected' : 'Showing' }} {{ filteredStores.length }} store{{ filteredStores.length !== 1 ? 's' : '' }}
                            </p>
                            <button type="button" @click="clearStoreFilters"
                                    class="px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-white text-gray-500 border border-gray-200 hover:border-indigo-300 hover:text-indigo-600 transition-all shadow-sm">
                                Clear Filters
                            </button>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 max-h-48 overflow-y-auto pr-2 custom-scrollbar">
                            <button v-for="s in filteredStores" :key="s.id" type="button"
                                    @click="toggleStore(s.code)"
                                    :class="form.stores_covered.includes(s.code) ? 'bg-indigo-600 text-white ring-4 ring-indigo-100' : 'bg-white text-gray-500 hover:border-indigo-300'"
                                    class="px-4 py-3 rounded-xl text-[10px] font-black uppercase text-left transition-all border border-gray-100 shadow-sm truncate">
                                {{ s.code }}
                            </button>
                        </div>
                        <p v-if="filteredStores.length === 0" class="text-center text-xs font-bold text-gray-400 italic py-6">
                            No stores matched the selected cluster, brand, and search filters.
                        </p>
                    </div>
                    <div v-else class="text-center py-12 bg-white rounded-3xl border-2 border-dashed border-indigo-100">
                        <span class="text-indigo-600 font-black text-2xl uppercase italic tracking-tighter opacity-40">Matrix applies to every operational store</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Card — Schema-driven -->
        <div v-if="form.request_type_id && useSchema" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
            <div class="flex items-center mb-8">
                <span class="w-12 h-12 bg-emerald-500 rounded-2xl flex items-center justify-center mr-4 shadow-lg shadow-emerald-200">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                </span>
                <div>
                    <h2 class="text-2xl font-black text-gray-900 tracking-tight">{{ selectedRequestType?.name }} Details</h2>
                    <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mt-1">Dynamic fields configured by admin</p>
                </div>
            </div>
            <DynamicFormRenderer
                :fields="schemaFields"
                v-model="form.form_data"
                :items="form.details"
                :items-columns="schemaItemsColumns"
                :item-label="schemaItemLabel"
                :has-items="schemaHasItems"
                :errors="form.errors"
                @update:items="val => form.details = val"
            />
        </div>

        <!-- Details Card — Hard-coded fallback (shown when no schema is defined) -->
        <div v-else-if="form.request_type_id" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
            <div class="flex items-center justify-between mb-10">
                <div class="flex items-center">
                    <span class="w-12 h-12 bg-emerald-500 rounded-2xl flex items-center justify-center mr-4 shadow-lg shadow-emerald-200">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                        </svg>
                    </span>
                    <div>
                        <h2 class="text-3xl font-black text-gray-900 tracking-tight">Line Item Details</h2>
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mt-1">Configure product pricing and technical specs</p>
                    </div>
                </div>
                <button type="button" @click="addRow" class="px-8 py-4 bg-indigo-600 text-white text-sm font-black rounded-2xl hover:bg-indigo-700 shadow-xl shadow-indigo-100 transition-all flex items-center transform hover:scale-105 active:scale-95">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add New Product
                </button>
            </div>

            <div class="space-y-6">
                <transition-group 
                    enter-active-class="duration-500 ease-out"
                    enter-from-class="opacity-0 translate-y-8"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="duration-300 ease-in position-absolute"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div v-for="(row, index) in form.details" :key="index" 
                         :class="[
                             Object.keys(form.errors).some(k => k.startsWith(`details.${index}`))
                             ? 'border-rose-200 bg-rose-50/20' 
                             : 'border-transparent bg-gray-50/50'
                         ]"
                         class="relative rounded-[2rem] p-8 border-2 hover:border-indigo-100 hover:bg-white hover:shadow-2xl hover:shadow-gray-200/40 transition-all group">
                        
                        <div class="flex justify-between items-start mb-8">
                            <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div class="space-y-2">
                                    <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Product Name</label>
                                    <input v-model="row.product_name" required placeholder="Enter full product name..."
                                           :class="[getError(`details.${index}.product_name`) ? 'border-rose-500 bg-rose-50' : 'border-gray-100 bg-white']"
                                           class="w-full border-2 rounded-xl px-5 py-3.5 text-sm font-bold focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 transition-all outline-none shadow-sm">
                                    <p v-if="getError(`details.${index}.product_name`)" class="text-[9px] text-rose-600 font-bold ml-1">Required</p>
                                </div>
                                <div class="space-y-2">
                                    <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">POS Display Name (Alias)</label>
                                    <input v-model="row.pos_name" required placeholder="Short name for POS..."
                                           :class="[getError(`details.${index}.pos_name`) ? 'border-rose-500 bg-rose-50' : 'border-gray-100 bg-white']"
                                           class="w-full border-2 rounded-xl px-5 py-3.5 text-sm font-bold focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 transition-all outline-none shadow-sm">
                                    <p v-if="getError(`details.${index}.pos_name`)" class="text-[9px] text-rose-600 font-bold ml-1">Required</p>
                                </div>
                                <div class="space-y-2">
                                    <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Item Code</label>
                                    <input v-model="row.item_code" placeholder="SKU/SAP Code..."
                                           class="w-full bg-white border-2 border-gray-100 rounded-xl px-5 py-3.5 text-xs font-mono font-black focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 transition-all outline-none shadow-sm">
                                </div>
                            </div>
                            <button v-if="form.details.length > 1" type="button" @click="removeRow(index)" 
                                    class="ml-6 p-3 text-gray-300 hover:text-rose-600 transition-all rounded-xl hover:bg-rose-50 transform hover:rotate-90">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-5 gap-6 mb-8 p-6 bg-white/50 rounded-3xl border border-gray-100">
                            <div class="space-y-2">
                                <label class="block text-[9px] font-black text-indigo-400 uppercase tracking-[0.2em] ml-1">Price Type</label>
                                <select v-model="row.price_type" required 
                                        class="w-full bg-white border-2 border-gray-100 rounded-xl px-4 py-3 text-[10px] font-black focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                                    <option v-for="pt in priceTypes" :key="pt" :value="pt">{{ pt }}</option>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-[9px] font-black text-emerald-400 uppercase tracking-[0.2em] ml-1">Amount</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold text-xs">₱</span>
                                    <input v-model="row.price_amount" type="number" step="0.01" placeholder="0.00"
                                           class="w-full bg-white border-2 border-gray-100 rounded-xl pl-8 pr-4 py-3 text-sm font-black focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 transition-all outline-none">
                                </div>
                            </div>
                            <div class="space-y-2 text-center">
                                <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">SC</label>
                                <input v-model="row.sc" class="w-full bg-white border-2 border-gray-100 rounded-xl px-4 py-3 text-sm font-bold text-center focus:border-indigo-500 focus:ring-0">
                            </div>
                            <div class="space-y-2 text-center">
                                <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Tax %</label>
                                <input v-model="row.local_tax" class="w-full bg-white border-2 border-gray-100 rounded-xl px-4 py-3 text-sm font-bold text-center focus:border-indigo-500 focus:ring-0">
                            </div>
                            <div class="space-y-2 text-center flex flex-col items-center">
                                <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em]">Mgr's Meal</label>
                                <div class="mt-1 flex flex-col items-center">
                                    <button type="button" 
                                            @click="row.mgr_meal = !row.mgr_meal"
                                            :class="row.mgr_meal ? 'bg-indigo-600 shadow-indigo-200' : 'bg-gray-200'"
                                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-all duration-300 ease-in-out focus:outline-none shadow-sm">
                                        <span :class="row.mgr_meal ? 'translate-x-5' : 'translate-x-0'"
                                              class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-md ring-0 transition duration-300 ease-in-out"></span>
                                    </button>
                                    <span class="block text-[9px] font-black mt-1.5 transition-colors duration-300" :class="row.mgr_meal ? 'text-indigo-600' : 'text-gray-400'">
                                        {{ row.mgr_meal ? 'YES' : 'NO' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                            <div class="space-y-2 relative">
                                <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Category</label>
                                <input v-model="row.category" placeholder="Search or type category..."
                                       @focus="activeSuggest = { index, type: 'category' }"
                                       class="w-full bg-white border-2 border-gray-100 rounded-xl px-5 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 outline-none">
                                <div v-if="activeSuggest.index === index && activeSuggest.type === 'category' && filterSuggestions('category', row.category).length" 
                                     class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-gray-100 shadow-2xl rounded-xl overflow-hidden">
                                    <button v-for="s in filterSuggestions('category', row.category)" :key="s" type="button"
                                            @click="selectSuggest(index, 'category', s)"
                                            class="w-full text-left px-5 py-3 text-sm font-bold hover:bg-indigo-50 transition-colors border-b border-gray-50 last:border-0">
                                        {{ s }}
                                    </button>
                                </div>
                            </div>
                            <div class="space-y-2 relative">
                                <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Sub Category</label>
                                <input v-model="row.sub_category" placeholder="Search or type sub category..."
                                       @focus="activeSuggest = { index, type: 'sub_category' }"
                                       class="w-full bg-white border-2 border-gray-100 rounded-xl px-5 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 outline-none">
                                <div v-if="activeSuggest.index === index && activeSuggest.type === 'sub_category' && filterSuggestions('sub_category', row.sub_category).length" 
                                     class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-gray-100 shadow-2xl rounded-xl overflow-hidden">
                                    <button v-for="s in filterSuggestions('sub_category', row.sub_category)" :key="s" type="button"
                                            @click="selectSuggest(index, 'sub_category', s)"
                                            class="w-full text-left px-5 py-3 text-sm font-bold hover:bg-indigo-50 transition-colors border-b border-gray-50 last:border-0">
                                        {{ s }}
                                    </button>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-[9px] font-black text-rose-400 uppercase tracking-[0.2em] ml-1">Validity Date</label>
                                <input v-model="row.validity_date" type="date" 
                                       class="w-full bg-white border-2 border-gray-100 rounded-xl px-5 py-2.5 text-sm font-bold focus:border-indigo-500 focus:ring-0 outline-none">
                            </div>
                            <div class="space-y-2">
                                <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Printer Selection</label>
                                <input v-model="row.printer" placeholder="Assign printer name..."
                                       class="w-full bg-white border-2 border-gray-100 rounded-xl px-5 py-3 text-sm font-bold focus:border-indigo-500 focus:ring-0 outline-none">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-[9px] font-black text-gray-400 uppercase tracking-[0.2em] ml-1">Remarks & Mechanics</label>
                            <textarea v-model="row.remarks_mechanics" rows="2" placeholder="Describe mechanics..."
                                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-medium focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 transition-all outline-none shadow-sm"></textarea>
                        </div>

                        <div class="absolute -left-4 top-1/2 -translate-y-1/2 w-8 h-8 bg-black text-white text-[10px] font-black flex items-center justify-center rounded-full shadow-xl border-4 border-white">
                            {{ index + 1 }}
                        </div>
                    </div>
                </transition-group>
            </div>

            <div class="mt-10 flex justify-center">
                <button type="button" @click="addRow" class="group flex items-center px-10 py-4 bg-gray-50 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-2xl text-xs font-black uppercase tracking-widest transition-all border-2 border-dashed border-gray-200 hover:border-indigo-200">
                    <svg class="w-5 h-5 mr-3 group-hover:scale-125 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" />
                    </svg>
                    Click to append another record
                </button>
            </div>
        </div><!-- end v-else hard-coded Details Card -->

        <!-- Actions -->
        <div class="flex justify-end items-center space-x-8 pb-12">
            <Link v-if="!isPublic" :href="route('pos-requests.index')" class="text-xs font-black text-gray-400 uppercase tracking-[0.3em] hover:text-gray-900 transition-all underline decoration-2 decoration-transparent hover:decoration-indigo-500 underline-offset-8">Discard Request</Link>
            <button type="submit" :disabled="form.processing || !canSubmit"
                    :title="!canSubmit ? 'Please add at least 1 line item before submitting.' : ''"
                    class="px-16 py-6 bg-black text-white text-base font-black rounded-[2rem] hover:bg-indigo-600 shadow-2xl shadow-indigo-200/50 transition-all transform hover:-translate-y-2 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-black disabled:hover:translate-y-0 flex items-center">
                {{ form.processing ? 'Processing Transaction...' : 'Confirm & Submit POS Request' }}
                <svg class="w-5 h-5 ml-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </div>

        <!-- Inline Confirmation Dialog -->
        <Teleport to="body">
            <div v-if="showConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="onConfirmNo"></div>
                <div class="relative bg-white rounded-3xl shadow-2xl w-full max-w-md p-8 border border-gray-100">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="w-12 h-12 bg-indigo-100 rounded-2xl flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-black text-gray-900">Submit POS Request</h3>
                            <p class="text-sm text-gray-500 mt-0.5">Please review all details before confirming.</p>
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 mb-8">Are you sure you want to submit this POS request? Once submitted, it will be sent for processing.</p>
                    <div class="flex gap-3">
                        <button type="button" @click="onConfirmNo"
                                class="flex-1 px-6 py-3 text-sm font-bold text-gray-600 bg-gray-100 rounded-2xl hover:bg-gray-200 transition-all">
                            Cancel
                        </button>
                        <button type="button" @click="onConfirmYes"
                                class="flex-[2] px-6 py-3 bg-indigo-600 text-white text-sm font-black rounded-2xl hover:bg-indigo-700 shadow-lg transition-all">
                            Yes, Submit Request
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </form>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    height: 8px;
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: #f8fafc;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #cbd5e1;
}
</style>
