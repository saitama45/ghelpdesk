<script setup>
import { ref, computed, watch, markRaw } from 'vue'
import { useForm } from '@inertiajs/vue3'
import DynamicFormRenderer from '@/Components/DynamicFormRenderer.vue'
import NewItemRequestForm from './Forms/NewItemRequestForm.vue'
import NewVendorRequestForm from './Forms/NewVendorRequestForm.vue'
import StoreCodeRequestForm from './Forms/StoreCodeRequestForm.vue'
import NewCustomerRequestForm from './Forms/NewCustomerRequestForm.vue'
import AddExistingVendorForm from './Forms/AddExistingVendorForm.vue'
import AddUOMConfigForm from './Forms/AddUOMConfigForm.vue'
import AddExistingSKUForm from './Forms/AddExistingSKUForm.vue'
import NewBOMForm from './Forms/NewBOMForm.vue'

const props = defineProps({
    companies: { type: Array, default: () => [] },
    requestTypes: { type: Array, default: () => [] },
    sapRequest: { type: Object, default: null },
    isPublic: { type: Boolean, default: false },
    submitRoute: { type: String, required: true },
    method: { type: String, default: 'post' },
    initialRequestTypeId: { type: [String, Number], default: '' },
})

const FORM_MAP = {
    // New Item Creation
    'New Item Request': markRaw(NewItemRequestForm),
    'New Item Creation': markRaw(NewItemRequestForm),
    
    // Vendor
    'New Vendor Request': markRaw(NewVendorRequestForm),
    'Add Existing Vendor': markRaw(AddExistingVendorForm),
    
    // Store
    'Store Code Request': markRaw(StoreCodeRequestForm),
    
    // Customer
    'New Customer Request': markRaw(NewCustomerRequestForm),
    
    // UOM / SKU
    'Add UOM Config to Existing SAP': markRaw(AddUOMConfigForm),
    'Add Existing SAP SKU': markRaw(AddExistingSKUForm),
    'Add Existing SKU': markRaw(AddExistingSKUForm),
    
    // BOM
    'New BOM': markRaw(NewBOMForm),
}

const form = useForm({
    requester_name: props.sapRequest?.requester_name ?? '',
    requester_email: props.sapRequest?.requester_email ?? '',
    company_id: props.sapRequest?.company_id ?? '',
    request_type_id: props.sapRequest?.request_type_id ?? props.initialRequestTypeId ?? '',
    form_data: props.sapRequest?.form_data ?? {},
    items: props.sapRequest?.items?.map(i => i.item_data) ?? [],
})

const selectedRequestType = computed(() =>
    props.requestTypes.find(rt => rt.id == form.request_type_id)
)
const selectedCompany = computed(() =>
    props.companies.find(c => c.id == form.company_id)
)
const activeFormComponent = computed(() => {
    if (!selectedRequestType.value) return null
    return FORM_MAP[selectedRequestType.value.name] ?? null
})

// Initialize items if request type changes to a type that has items
// We use oldVal to ensure we only reset when the user MANUALLY changes the type,
// and not during the initial load of an existing request.
watch(() => form.request_type_id, (newVal, oldVal) => {
    if (oldVal !== undefined && oldVal !== null && oldVal !== '') {
        form.form_data = {}
        form.items = []
    }
})

function submit() {
    if (props.method === 'put') {
        form.put(props.submitRoute, {
            onSuccess: () => form.reset(),
        })
    } else {
        form.post(props.submitRoute, {
            onSuccess: () => form.reset(),
        })
    }
}

// ── Schema-driven vs hard-coded fallback ──────────────────────────────────────
const schemaFields = computed(() => selectedRequestType.value?.form_schema?.fields ?? [])
const schemaItemsColumns = computed(() => selectedRequestType.value?.form_schema?.items_columns ?? [])
const schemaItemLabel = computed(() => selectedRequestType.value?.form_schema?.item_label ?? 'Row')
const schemaHasItems = computed(() => !!selectedRequestType.value?.form_schema?.has_items)
const useSchema = computed(() => schemaFields.value.length > 0 || schemaHasItems.value)

// Ensure at least one blank row when switching to a schema-based items form
watch(schemaHasItems, (val) => {
    if (val && form.items.length === 0) {
        const blank = {}
        schemaItemsColumns.value.forEach(c => { blank[c.key] = '' })
        form.items = [blank]
    }
})

// Forms that use items table (tabular rows) — for hard-coded fallback
const HAS_ITEMS = ['New Item Request', 'New BOM', 'New Item Creation']
const hasItems = computed(() =>
    schemaHasItems.value ||
    (selectedRequestType.value && HAS_ITEMS.includes(selectedRequestType.value.name))
)

// Whether the form section is ready to show (schema takes priority; fallback to hard-coded map)
const formReady = computed(() => useSchema.value || !!activeFormComponent.value)
</script>

<template>
    <div class="space-y-8">
        <!-- Step 1: Request Type Selection (if not selected) -->
        <div v-if="!form.request_type_id" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <button v-for="rt in requestTypes" :key="rt.id" type="button"
                @click="form.request_type_id = rt.id"
                class="bg-white p-6 rounded-[2rem] shadow-xl shadow-gray-100/50 border border-gray-100 text-left hover:border-teal-500 hover:shadow-teal-100/50 transition-all group">
                <div class="w-12 h-12 bg-teal-50 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-teal-600 group-hover:text-white transition-all">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h4 class="text-sm font-black text-gray-900 mb-1">{{ rt.name }}</h4>
                <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">
                    {{ rt.approval_levels > 0 ? `${rt.approval_levels} Approval Steps` : 'No Approval Required' }}
                </p>
            </button>
        </div>

        <form v-else @submit.prevent="submit" class="space-y-8">
            <!-- Selected Type Info & Back Button -->
            <div class="flex items-center justify-between bg-white rounded-[2rem] shadow-xl shadow-gray-100/50 p-6 border border-gray-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-teal-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-teal-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-gray-900">{{ selectedRequestType?.name }}</h3>
                        <p class="text-xs text-teal-600 font-bold uppercase tracking-wider">Form is now ready</p>
                    </div>
                </div>
                <button type="button" @click="form.request_type_id = ''"
                    class="px-4 py-2 text-xs font-black text-gray-400 hover:text-gray-600 hover:bg-gray-50 rounded-xl transition-all uppercase tracking-widest">
                    Change Type
                </button>
            </div>

            <!-- Requester Info (public only) -->
            <div v-if="isPublic" class="bg-white rounded-[2rem] shadow-xl shadow-gray-100/50 p-8 border border-gray-100">
                <h3 class="text-base font-black text-gray-800 mb-6 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-teal-600 text-white flex items-center justify-center text-xs font-black">1</span>
                    Your Information
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Full Name <span class="text-rose-500">*</span></label>
                        <input v-model="form.requester_name" type="text"
                            :class="form.errors.requester_name ? 'border-rose-400' : 'border-slate-200'"
                            class="w-full bg-white border-2 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                        <p v-if="form.errors.requester_name" class="mt-1 text-xs text-rose-500 font-bold">{{ form.errors.requester_name }}</p>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Email Address <span class="text-rose-500">*</span></label>
                        <input v-model="form.requester_email" type="email"
                            :class="form.errors.requester_email ? 'border-rose-400' : 'border-slate-200'"
                            class="w-full bg-white border-2 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                        <p v-if="form.errors.requester_email" class="mt-1 text-xs text-rose-500 font-bold">{{ form.errors.requester_email }}</p>
                    </div>
                </div>
            </div>

            <!-- Request Classification (Entity Only now) -->
            <div class="bg-white rounded-[2rem] shadow-xl shadow-gray-100/50 p-8 border border-gray-100">
                <h3 class="text-base font-black text-gray-800 mb-6 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-teal-600 text-white flex items-center justify-center text-xs font-black">{{ isPublic ? '2' : '1' }}</span>
                    Entity Selection
                </h3>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Entity / Company <span class="text-rose-500">*</span></label>
                    <select v-model="form.company_id"
                        :class="form.errors.company_id ? 'border-rose-400' : 'border-slate-200'"
                        class="w-full bg-white border-2 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select entity...</option>
                        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <p v-if="form.errors.company_id" class="mt-1 text-xs text-rose-500 font-bold">{{ form.errors.company_id }}</p>
                </div>

                <!-- Approval info badge -->
                <div v-if="selectedRequestType" class="mt-5 flex items-center gap-3 px-5 py-3 rounded-2xl"
                    :class="selectedRequestType.approval_levels == 0 ? 'bg-emerald-50 border border-emerald-100' : 'bg-blue-50 border border-blue-100'">
                    <svg class="w-4 h-4 flex-shrink-0" :class="selectedRequestType.approval_levels == 0 ? 'text-emerald-600' : 'text-blue-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-xs font-bold" :class="selectedRequestType.approval_levels == 0 ? 'text-emerald-700' : 'text-blue-700'">
                        <template v-if="selectedRequestType.approval_levels == 0">
                            No approval required — this request will go directly to the SAP Data Officer for encoding.
                        </template>
                        <template v-else>
                            Requires {{ selectedRequestType.approval_levels }} approval {{ selectedRequestType.approval_levels == 1 ? 'step' : 'steps' }} before encoding. SLA: 1–3 days.
                        </template>
                    </span>
                </div>
            </div>

            <!-- Dynamic Form Section -->
            <div v-if="formReady" class="bg-white rounded-[2rem] shadow-xl shadow-gray-100/50 p-8 border border-gray-100">
                <h3 class="text-base font-black text-gray-800 mb-6 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-teal-600 text-white flex items-center justify-center text-xs font-black">{{ isPublic ? '3' : '2' }}</span>
                    {{ selectedRequestType?.name }} Details
                </h3>

                <!-- Schema-driven renderer -->
                <DynamicFormRenderer
                    v-if="useSchema"
                    :fields="schemaFields"
                    v-model="form.form_data"
                    :items="form.items"
                    :items-columns="schemaItemsColumns"
                    :item-label="schemaItemLabel"
                    :has-items="schemaHasItems"
                    :errors="form.errors"
                    :context="{ companyName: selectedCompany?.name ?? '' }"
                    @update:items="val => form.items = val"
                />

                <!-- Hard-coded fallback for request types without a schema -->
                <component
                    v-else-if="activeFormComponent"
                    :is="activeFormComponent"
                    v-model="form.form_data"
                    :items="form.items"
                    :company-name="selectedCompany?.name ?? ''"
                    :companies="companies"
                    :errors="form.errors"
                    @update:items="val => form.items = val"
                />

                <p v-if="form.errors.form_data" class="mt-2 text-xs text-rose-500 font-bold">{{ form.errors.form_data }}</p>
            </div>

            <!-- Submit -->
            <div class="flex items-center justify-between gap-4">
                <p class="text-xs text-gray-400 font-medium">Fields marked with <span class="text-rose-500 font-black">*</span> are required.</p>
                <button type="submit" :disabled="form.processing || !formReady"
                    class="px-10 py-4 bg-teal-600 text-white rounded-2xl font-black text-sm uppercase tracking-[0.15em] shadow-2xl shadow-teal-100 hover:bg-teal-700 transform hover:-translate-y-0.5 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none flex items-center gap-3">
                    <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    {{ form.processing ? 'Submitting...' : (method === 'put' ? 'Update Request' : 'Submit SAP Request') }}
                </button>
            </div>
        </form>
    </div>
</template>
