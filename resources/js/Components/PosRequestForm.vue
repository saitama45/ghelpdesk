<script setup>
import { ref, reactive, computed, watch } from 'vue'
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

const isEditing = computed(() => !!props.posRequest)

const form = useForm({
    requester_name: props.posRequest?.requester_name ?? '',
    requester_email: props.posRequest?.requester_email ?? '',
    company_id: props.posRequest?.company_id ?? '',
    request_type_id: props.posRequest?.request_type_id ?? '',
    launch_date: props.posRequest?.launch_date ?? '',
    stores_covered: props.posRequest?.stores_covered ?? [],
    form_data: props.posRequest?.form_data ?? {},
    details: props.posRequest?.details ? props.posRequest.details.map(d => ({
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
    })) : [
        {
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
            mgr_meal: false,
            printer: ''
        }
    ]
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
const filteredStores = computed(() => {
    if (!storeSearch.value) return props.stores.slice(0, 50)
    const search = storeSearch.value.toLowerCase()
    return props.stores.filter(s => 
        s.code.toLowerCase().includes(search) || 
        s.name.toLowerCase().includes(search)
    ).slice(0, 100)
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

// Re-initialize detail rows when switching to a schema-driven tabular type
watch(useSchemaItems, (val) => {
    if (val && form.details.length === 1) {
        const blank = {}
        schemaItemsColumns.value.forEach(c => { blank[c.key] = '' })
        const existing = form.details[0]
        const hasData = Object.values(existing).some(v => v !== '' && v !== false && v !== null)
        if (!hasData) form.details = [blank]
    }
})

// Reset form_data when request type changes
watch(() => form.request_type_id, () => {
    form.form_data = {}
})

const submit = () => {
    form.clearErrors()
    
    if (props.isPublic) {
        form.post(route('public.pos-requests.store'), {
            onSuccess: () => {
                form.reset()
            },
            onError: (errors) => {
                const errorCount = Object.keys(errors).length
                showError(`Please fix ${errorCount} validation error(s) highlighted in red.`)
            }
        })
    } else if (isEditing.value) {
        form.put(route('pos-requests.update', props.posRequest.id), {
            onError: (errors) => {
                const errorCount = Object.keys(errors).length
                showError(`Please fix ${errorCount} validation error(s) highlighted in red.`)
            }
        })
    } else {
        form.post(route('pos-requests.store'), {
            onError: (errors) => {
                const errorCount = Object.keys(errors).length
                showError(`Please fix ${errorCount} validation error(s) highlighted in red.`)
            }
        })
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-8">
        
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
                        <input v-model="form.requester_email" type="email" required placeholder="john.doe@example.com"
                               :class="[getError('requester_email') ? 'border-rose-500 bg-rose-50' : 'bg-gray-50 border-gray-50']"
                               class="w-full border-2 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all outline-none">
                        <p v-if="getError('requester_email')" class="text-[10px] text-rose-600 font-bold ml-1">{{ getError('requester_email') }}</p>
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
                        <div class="flex flex-col md:flex-row gap-6">
                            <div class="flex-1 relative">
                                <svg class="absolute left-5 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input v-model="storeSearch" type="text" placeholder="Search stores by code or name..."
                                       class="w-full bg-white border-2 border-gray-100 rounded-2xl pl-14 pr-6 py-4 text-sm font-bold focus:border-indigo-500 focus:ring-0 transition-all outline-none shadow-sm">
                            </div>
                            
                            <div class="flex-[2] bg-white border-2 rounded-2xl p-4 min-h-[60px] flex flex-wrap gap-2 shadow-sm transition-colors"
                                 :class="getError('stores_covered') ? 'border-rose-300' : 'border-gray-100'">
                                <span v-if="form.stores_covered.length === 0" class="text-xs font-bold text-gray-300 italic flex items-center px-2">No stores selected yet...</span>
                                <button v-for="code in form.stores_covered" :key="code" type="button" @click="removeStoreTag(code)"
                                        class="group inline-flex items-center px-4 py-2 bg-indigo-50 text-indigo-700 rounded-xl text-xs font-black transition-all hover:bg-indigo-600 hover:text-white">
                                    {{ code }}
                                    <svg class="w-3 h-3 ml-2 opacity-50 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 max-h-48 overflow-y-auto pr-2 custom-scrollbar">
                            <button v-for="s in filteredStores" :key="s.id" type="button"
                                    @click="toggleStore(s.code)"
                                    :class="form.stores_covered.includes(s.code) ? 'bg-indigo-600 text-white ring-4 ring-indigo-100' : 'bg-white text-gray-500 hover:border-indigo-300'"
                                    class="px-4 py-3 rounded-xl text-[10px] font-black uppercase text-left transition-all border border-gray-100 shadow-sm truncate">
                                {{ s.code }}
                            </button>
                        </div>
                    </div>
                    <div v-else class="text-center py-12 bg-white rounded-3xl border-2 border-dashed border-indigo-100">
                        <span class="text-indigo-600 font-black text-2xl uppercase italic tracking-tighter opacity-40">Matrix applies to every operational store</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Card — Schema-driven -->
        <div v-if="useSchema" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
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
        <div v-else class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
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
            <button type="submit" :disabled="form.processing" 
                    class="px-16 py-6 bg-black text-white text-base font-black rounded-[2rem] hover:bg-indigo-600 shadow-2xl shadow-indigo-200/50 transition-all transform hover:-translate-y-2 active:scale-95 disabled:opacity-50 flex items-center">
                {{ form.processing ? 'Processing Transaction...' : 'Confirm & Submit POS Request' }}
                <svg class="w-5 h-5 ml-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </div>
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
