<script setup>
import { computed } from 'vue'

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])

const form = computed(() => props.modelValue)
function update(key, val) {
    emit('update:modelValue', { ...props.modelValue, [key]: val })
}

const VENDOR_TYPES = ['Employee', 'Local Trade', 'Interco', 'Imported']
const PAYMENT_TERMS = ['COD', '7 Days', '15 Days', '45 Days', '50DP/50FP', '50DP/FP', '50DP/COD', 'IMMEDIATE', 'PDC 7 Days', 'PDC 15 Days', 'PDC 30 Days', 'PDC 45 Days']
const TAX_STATUSES = ['Liable', 'Exempt']
const TAX_GROUPS = ['IVAT-E (Input VAT Exempt)', 'IVAT-N (Input VAT Goods)', 'IVAT-S (Input VAT Services)', 'IVAT-Z (Zero Rated)', 'N-VAT (Non-VAT Goods/Services)']

const isEmployee = computed(() => form.value.vendor_type === 'Employee')
const isVendor = computed(() => ['Local Trade', 'Interco', 'Imported'].includes(form.value.vendor_type))

function onFileChange(key, e, multiple = false) {
    const files = Array.from(e.target.files)
    update(key, multiple ? files.map(f => f.name) : files[0]?.name ?? '')
}
</script>

<template>
    <div class="space-y-6">
        <!-- Vendor Type -->
        <div>
            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2">Vendor Type <span class="text-rose-500">*</span></label>
            <div class="flex flex-wrap gap-3">
                <button v-for="t in VENDOR_TYPES" :key="t" type="button"
                    @click="update('vendor_type', t)"
                    :class="form.vendor_type === t ? 'bg-teal-600 text-white shadow-lg shadow-teal-100' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-5 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ t }}
                </button>
            </div>
        </div>

        <!-- Employee Fields -->
        <template v-if="isEmployee">
            <div class="bg-blue-50 rounded-2xl p-6 border border-blue-100 space-y-4">
                <p class="text-xs font-black text-blue-600 uppercase tracking-widest">Employee Information</p>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Employee Name <span class="text-rose-500">*</span></label>
                    <input :value="form.employee_name" @input="update('employee_name', $event.target.value)" type="text"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Employee ID (Attachment) <span class="text-rose-500">*</span></label>
                    <input type="file" @change="onFileChange('employee_id_file', $event)"
                        class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 transition-all" />
                </div>
            </div>
        </template>

        <!-- Vendor Fields -->
        <template v-if="isVendor">
            <div class="bg-slate-50 rounded-2xl p-6 border border-slate-100">
                <p class="text-xs font-black text-teal-600 uppercase tracking-widest mb-4">Vendor Information</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Vendor Name <span class="text-rose-500">*</span></label>
                        <input :value="form.vendor_name" @input="update('vendor_name', $event.target.value)" type="text"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Contact Person <span class="text-rose-500">*</span></label>
                        <input :value="form.contact_person" @input="update('contact_person', $event.target.value)" type="text"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">TIN # <span class="text-rose-500">*</span></label>
                        <input :value="form.tin" @input="update('tin', $event.target.value)" type="text"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Telephone Number <span class="text-rose-500">*</span></label>
                        <input :value="form.telephone" @input="update('telephone', $event.target.value)" type="text"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Phone Number <span class="text-rose-500">*</span></label>
                        <input :value="form.phone" @input="update('phone', $event.target.value)" type="text"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Currency <span class="text-rose-500">*</span></label>
                        <input :value="form.currency" @input="update('currency', $event.target.value)" type="text"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Address <span class="text-rose-500">*</span></label>
                        <textarea :value="form.address" @input="update('address', $event.target.value)" rows="2"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all"></textarea>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Payment Terms <span class="text-rose-500">*</span></label>
                        <select :value="form.payment_terms" @change="update('payment_terms', $event.target.value)"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                            <option value="">Select terms...</option>
                            <option v-for="t in PAYMENT_TERMS" :key="t">{{ t }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Subject to Withholding Tax <span class="text-rose-500">*</span></label>
                        <div class="flex gap-3">
                            <button v-for="opt in ['Yes', 'No']" :key="opt" type="button"
                                @click="update('subject_to_withholding_tax', opt)"
                                :class="form.subject_to_withholding_tax === opt ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                class="px-4 py-2 rounded-xl text-sm font-bold transition-all">
                                {{ opt }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tax Status <span class="text-rose-500">*</span></label>
                        <select :value="form.tax_status" @change="update('tax_status', $event.target.value)"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                            <option value="">Select status...</option>
                            <option v-for="s in TAX_STATUSES" :key="s">{{ s }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tax Group <span class="text-rose-500">*</span></label>
                        <select :value="form.tax_group" @change="update('tax_group', $event.target.value)"
                            class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                            <option value="">Select group...</option>
                            <option v-for="g in TAX_GROUPS" :key="g">{{ g }}</option>
                        </select>
                    </div>
                </div>

                <!-- Attachments -->
                <div class="mt-6 space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">COR / SEC (up to 10 files) <span class="text-rose-500">*</span></label>
                        <input type="file" multiple @change="onFileChange('cor_sec_files', $event, true)"
                            class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Blank Invoice (up to 5 files) <span class="text-rose-500">*</span></label>
                        <input type="file" multiple @change="onFileChange('blank_invoice_files', $event, true)"
                            class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Blank Receipt (1 file) <span class="text-rose-500">*</span></label>
                        <input type="file" @change="onFileChange('blank_receipt_file', $event)"
                            class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
