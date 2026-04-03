<script setup>
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
function update(key, val) { emit('update:modelValue', { ...props.modelValue, [key]: val }) }
function onFile(key, e) { update(key, e.target.files[0]?.name ?? '') }

const CUSTOMER_GROUPS = ['Imported', 'Intercompany', 'Local']
const CURRENCIES = ['AUD', 'PHP', 'HK$', 'JPY', 'SGD', 'USD', 'EURO', 'All Currencies', 'Other']
const PAYMENT_TERMS = ['COD', '7 Days', '15 Days', '45 Days', '50DP/50FP', '50DP/FP', '50DP/COD', 'IMMEDIATE', 'PDC 7 Days', 'PDC 15 Days', 'PDC 30 Days', 'PDC 45 Days']
const TAX_GROUPS = [
    'OVAT-N (Output VAT - Non Capital Goods)',
    'OVAT-S (Output VAT - Services)',
    'OVAT-Z (Output VAT - Zero Rated)',
    'OVAT-E (Output VAT - Exempt)',
    'X-VAT (Non-VAT Goods/Services)',
]
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Customer Name <span class="text-rose-500">*</span></label>
            <input :value="modelValue.customer_name" @input="update('customer_name', $event.target.value)" type="text"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Customer Group <span class="text-rose-500">*</span></label>
            <select :value="modelValue.customer_group" @change="update('customer_group', $event.target.value)"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                <option value="">Select group...</option>
                <option v-for="g in CUSTOMER_GROUPS" :key="g">{{ g }}</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Currency <span class="text-rose-500">*</span></label>
            <select :value="modelValue.currency" @change="update('currency', $event.target.value)"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                <option value="">Select currency...</option>
                <option v-for="c in CURRENCIES" :key="c">{{ c }}</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">TIN # <span class="text-rose-500">*</span></label>
            <input :value="modelValue.tin" @input="update('tin', $event.target.value)" type="text"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Contact Person <span class="text-rose-500">*</span></label>
            <input :value="modelValue.contact_person" @input="update('contact_person', $event.target.value)" type="text"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Telephone Number <span class="text-rose-500">*</span></label>
            <input :value="modelValue.telephone" @input="update('telephone', $event.target.value)" type="tel"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Cellphone Number <span class="text-rose-500">*</span></label>
            <input :value="modelValue.cellphone" @input="update('cellphone', $event.target.value)" type="tel"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Email Address <span class="text-rose-500">*</span></label>
            <input :value="modelValue.email_address" @input="update('email_address', $event.target.value)" type="email"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div class="md:col-span-2">
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Billing Address <span class="text-rose-500">*</span></label>
            <textarea :value="modelValue.billing_address" @input="update('billing_address', $event.target.value)" rows="2"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all"></textarea>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Subject to Withholding Tax <span class="text-rose-500">*</span></label>
            <div class="flex gap-3">
                <button v-for="opt in ['Yes', 'No']" :key="opt" type="button"
                    @click="update('subject_to_withholding_tax', opt)"
                    :class="modelValue.subject_to_withholding_tax === opt ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-4 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ opt }}
                </button>
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Payment Terms <span class="text-rose-500">*</span></label>
            <select :value="modelValue.payment_terms" @change="update('payment_terms', $event.target.value)"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                <option value="">Select terms...</option>
                <option v-for="t in PAYMENT_TERMS" :key="t">{{ t }}</option>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">SAP Tax Group <span class="text-rose-500">*</span></label>
            <select :value="modelValue.sap_tax_group" @change="update('sap_tax_group', $event.target.value)"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                <option value="">Select tax group...</option>
                <option v-for="t in TAX_GROUPS" :key="t">{{ t }}</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">COR Attachment <span class="text-rose-500">*</span></label>
            <input type="file" @change="onFile('cor_attachment', $event)"
                class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Signed Contract <span class="text-rose-500">*</span></label>
            <input type="file" @change="onFile('signed_contract', $event)"
                class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
        </div>
    </div>
</template>
