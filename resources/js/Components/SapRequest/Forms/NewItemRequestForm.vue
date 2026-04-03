<script setup>
import { computed } from 'vue'

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    companyName: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue', 'update:items'])

const form = computed({
    get: () => props.modelValue,
    set: val => emit('update:modelValue', val),
})

const itemList = computed({
    get: () => props.items.length ? props.items : [defaultItem()],
    set: val => emit('update:items', val),
})

const isGsi = computed(() => props.companyName?.toLowerCase().includes('gsi'))

function defaultItem() {
    return {
        item_name: '', item_type: '', sap_default_whse_location: '', gsi_picking_category: '',
        storage_location: '', uom_config: '', currency: '', purchase_cost: '',
        sales_tax_group: '', gl_account: '', withholding_tax_liable: '', manage_type: '',
    }
}

function addItem() {
    emit('update:items', [...itemList.value, defaultItem()])
}
function removeItem(i) {
    if (itemList.value.length > 1) {
        const updated = [...itemList.value]
        updated.splice(i, 1)
        emit('update:items', updated)
    }
}
function updateItem(i, key, val) {
    const updated = itemList.value.map((it, idx) => idx === i ? { ...it, [key]: val } : it)
    emit('update:items', updated)
}
function updateField(key, val) {
    emit('update:modelValue', { ...props.modelValue, [key]: val })
}

const ITEM_TYPES = ['Sale Item', 'Inventory Item', 'Purchasing Item', 'Service Item']
const WHSE_LOCATIONS = ['02-Control', '03-Distribution', 'Big Whse', 'Chiller', 'Small Whse', 'Freezer', 'None GSI Item']
const PICKING_CATEGORIES = ['Cakes', 'Bakery', 'Gourmet', 'Chiller', 'Freezer', 'Small Whse', 'Big Whse', 'None GSI Item']
const STORAGE_LOCATIONS = ['Pasig Whse', 'Pulilan Whse', 'Dropship', 'None']
const CURRENCIES = ['PHP', 'HK$', 'JPY', 'USD', 'EURO', 'SGD', 'Other']
const TAX_GROUPS = [
    'OVAT-E (Output VAT - Exempt)',
    'OVAT-N (Output VAT - Non Capital Goods)',
    'OVAT-S (Output VAT - Services)',
    'OVAT-Z (Output VAT - Zero Rated)',
]
const GL_ACCOUNTS = ['Food', 'Fresh', 'Capex', 'Service', 'Non Food', 'Finished Product']
const MANAGE_TYPES = ['None', 'Serial', 'Batch/Expiry']
</script>

<template>
    <div class="space-y-6">
        <!-- SKU Mode -->
        <div>
            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2">Single or Multiple SKU? <span class="text-rose-500">*</span></label>
            <div class="flex gap-3">
                <button v-for="opt in ['Single', 'Multiple']" :key="opt" type="button"
                    @click="updateField('sku_mode', opt)"
                    :class="form.sku_mode === opt ? 'bg-teal-600 text-white shadow-lg shadow-teal-100' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-5 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ opt }}
                </button>
            </div>
        </div>

        <!-- Items -->
        <div v-for="(item, i) in itemList" :key="i" class="bg-slate-50 rounded-2xl p-6 border border-slate-100 relative">
            <div class="flex items-center justify-between mb-4">
                <span class="text-xs font-black text-teal-600 uppercase tracking-widest">SKU #{{ i + 1 }}</span>
                <button v-if="itemList.length > 1" type="button" @click="removeItem(i)"
                    class="text-rose-400 hover:text-rose-600 text-xs font-bold px-3 py-1 rounded-lg hover:bg-rose-50 transition-all">
                    Remove
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Item Name <span class="text-rose-500">*</span></label>
                    <input :value="item.item_name" @input="updateItem(i, 'item_name', $event.target.value)" type="text"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Item Type <span class="text-rose-500">*</span></label>
                    <select :value="item.item_type" @change="updateItem(i, 'item_type', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select type...</option>
                        <option v-for="t in ITEM_TYPES" :key="t">{{ t }}</option>
                    </select>
                </div>
                <div v-if="isGsi">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">SAP Default Whse Location <span class="text-rose-500">*</span></label>
                    <select :value="item.sap_default_whse_location" @change="updateItem(i, 'sap_default_whse_location', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select location...</option>
                        <option v-for="l in WHSE_LOCATIONS" :key="l">{{ l }}</option>
                    </select>
                </div>
                <div v-if="isGsi">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">GSI Picking Category <span class="text-rose-500">*</span></label>
                    <select :value="item.gsi_picking_category" @change="updateItem(i, 'gsi_picking_category', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select category...</option>
                        <option v-for="c in PICKING_CATEGORIES" :key="c">{{ c }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Storage Location <span class="text-rose-500">*</span></label>
                    <select :value="item.storage_location" @change="updateItem(i, 'storage_location', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select location...</option>
                        <option v-for="s in STORAGE_LOCATIONS" :key="s">{{ s }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">UOM Configuration <span class="text-rose-500">*</span></label>
                    <input :value="item.uom_config" @input="updateItem(i, 'uom_config', $event.target.value)" type="text"
                        placeholder="e.g. 1 Case x 24 Bot x 1 Lit"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Currency <span class="text-rose-500">*</span></label>
                    <select :value="item.currency" @change="updateItem(i, 'currency', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select currency...</option>
                        <option v-for="c in CURRENCIES" :key="c">{{ c }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Purchase Cost <span class="text-rose-500">*</span></label>
                    <input :value="item.purchase_cost" @input="updateItem(i, 'purchase_cost', $event.target.value)" type="number" step="0.01"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Sales Tax Group <span class="text-rose-500">*</span></label>
                    <select :value="item.sales_tax_group" @change="updateItem(i, 'sales_tax_group', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select tax group...</option>
                        <option v-for="t in TAX_GROUPS" :key="t">{{ t }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">G/L Account / Item Group <span class="text-rose-500">*</span></label>
                    <select :value="item.gl_account" @change="updateItem(i, 'gl_account', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select account...</option>
                        <option v-for="g in GL_ACCOUNTS" :key="g">{{ g }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Withholding Tax Liable <span class="text-rose-500">*</span></label>
                    <div class="flex gap-3">
                        <button v-for="opt in ['Yes', 'No']" :key="opt" type="button"
                            @click="updateItem(i, 'withholding_tax_liable', opt)"
                            :class="item.withholding_tax_liable === opt ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                            class="px-4 py-2 rounded-xl text-sm font-bold transition-all">
                            {{ opt }}
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">SAP Item Manage Type <span class="text-rose-500">*</span></label>
                    <select :value="item.manage_type" @change="updateItem(i, 'manage_type', $event.target.value)"
                        class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">Select type...</option>
                        <option v-for="m in MANAGE_TYPES" :key="m">{{ m }}</option>
                    </select>
                </div>
            </div>
        </div>

        <button v-if="form.sku_mode === 'Multiple'" type="button" @click="addItem"
            class="flex items-center gap-2 px-5 py-3 border-2 border-dashed border-teal-300 rounded-2xl text-teal-600 font-bold text-sm hover:bg-teal-50 transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
            Add Another SKU
        </button>
    </div>
</template>
