<script setup>
import { ref, computed, watch, markRaw } from 'vue'
import { useForm } from '@inertiajs/vue3'
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
})

const FORM_MAP = {
    'New Item Request': markRaw(NewItemRequestForm),
    'New Vendor Request': markRaw(NewVendorRequestForm),
    'Store Code Request': markRaw(StoreCodeRequestForm),
    'New Customer Request': markRaw(NewCustomerRequestForm),
    'Add Existing Vendor': markRaw(AddExistingVendorForm),
    'Add UOM Config to Existing SAP': markRaw(AddUOMConfigForm),
    'Add Existing SAP SKU': markRaw(AddExistingSKUForm),
    'New BOM': markRaw(NewBOMForm),
}

const form = useForm({
    requester_name: props.sapRequest?.requester_name ?? '',
    requester_email: props.sapRequest?.requester_email ?? '',
    company_id: props.sapRequest?.company_id ?? '',
    request_type_id: props.sapRequest?.request_type_id ?? '',
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

// Reset form_data and items when request type changes
watch(() => form.request_type_id, () => {
    form.form_data = {}
    form.items = []
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

// Forms that use items table (tabular rows)
const HAS_ITEMS = ['New Item Request', 'New BOM']
const hasItems = computed(() => selectedRequestType.value && HAS_ITEMS.includes(selectedRequestType.value.name))
</script>

<template>
    <form @submit.prevent="submit" class="space-y-8">

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

        <!-- Request Classification -->
        <div class="bg-white rounded-[2rem] shadow-xl shadow-gray-100/50 p-8 border border-gray-100">
            <h3 class="text-base font-black text-gray-800 mb-6 flex items-center gap-2">
                <span class="w-6 h-6 rounded-lg bg-teal-600 text-white flex items-center justify-center text-xs font-black">{{ isPublic ? '2' : '1' }}</span>
                Request Classification
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
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
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">SAP Request Type <span class="text-rose-500">*</span></label>
                    <select v-model="form.request_type_id"
                        :class="form.errors.request_type_id ? 'border-rose-400' : 'border-slate-200'"
                        class="w-full bg-white border-2 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select request type...</option>
                        <option v-for="rt in requestTypes" :key="rt.id" :value="rt.id">{{ rt.name }}</option>
                    </select>
                    <p v-if="form.errors.request_type_id" class="mt-1 text-xs text-rose-500 font-bold">{{ form.errors.request_type_id }}</p>
                </div>
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
        <div v-if="activeFormComponent" class="bg-white rounded-[2rem] shadow-xl shadow-gray-100/50 p-8 border border-gray-100">
            <h3 class="text-base font-black text-gray-800 mb-6 flex items-center gap-2">
                <span class="w-6 h-6 rounded-lg bg-teal-600 text-white flex items-center justify-center text-xs font-black">{{ isPublic ? '3' : '2' }}</span>
                {{ selectedRequestType?.name }} Details
            </h3>

            <component
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

        <!-- Placeholder when no type selected -->
        <div v-else-if="form.company_id && !form.request_type_id"
            class="bg-slate-50 rounded-[2rem] border-2 border-dashed border-slate-200 p-12 text-center">
            <svg class="w-12 h-12 text-slate-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-sm font-bold text-slate-400">Select a request type above to see the required fields.</p>
        </div>

        <!-- Submit -->
        <div class="flex items-center justify-between gap-4">
            <p class="text-xs text-gray-400 font-medium">Fields marked with <span class="text-rose-500 font-black">*</span> are required.</p>
            <button type="submit" :disabled="form.processing || !activeFormComponent"
                class="px-10 py-4 bg-teal-600 text-white rounded-2xl font-black text-sm uppercase tracking-[0.15em] shadow-2xl shadow-teal-100 hover:bg-teal-700 transform hover:-translate-y-0.5 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none flex items-center gap-3">
                <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                {{ form.processing ? 'Submitting...' : (method === 'put' ? 'Update Request' : 'Submit SAP Request') }}
            </button>
        </div>
    </form>
</template>
