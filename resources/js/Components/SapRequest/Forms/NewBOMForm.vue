<script setup>
import { computed } from 'vue'

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue', 'update:items'])

function update(key, val) { emit('update:modelValue', { ...props.modelValue, [key]: val }) }

const bomRows = computed({
    get: () => props.items.length ? props.items : [defaultRow()],
    set: val => emit('update:items', val),
})

function defaultRow() {
    return { fin_prod_name: '', fin_prod_uom: '', sap_raw_mats_sku: '', sap_raw_mats_name: '', uom: '', qty: '' }
}
function addRow() { emit('update:items', [...bomRows.value, defaultRow()]) }
function removeRow(i) {
    if (bomRows.value.length > 1) {
        const updated = [...bomRows.value]; updated.splice(i, 1); emit('update:items', updated)
    }
}
function updateRow(i, key, val) {
    const updated = bomRows.value.map((r, idx) => idx === i ? { ...r, [key]: val } : r)
    emit('update:items', updated)
}

const WHSE_LOCATIONS = ['01', '02', '03', 'Big Whse', 'Small Whse', 'Chiller', 'Freezer']
</script>

<template>
    <div class="space-y-6">
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Warehouse Location <span class="text-rose-500">*</span></label>
            <select :value="modelValue.warehouse_location" @change="update('warehouse_location', $event.target.value)"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                <option value="">Select location...</option>
                <option v-for="l in WHSE_LOCATIONS" :key="l">{{ l }}</option>
            </select>
        </div>

        <!-- BOM Table -->
        <div>
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-black text-gray-800">BOM Line Items</h4>
                <button type="button" @click="addRow"
                    class="flex items-center gap-1 px-4 py-2 bg-teal-50 text-teal-600 rounded-xl font-bold text-xs hover:bg-teal-100 transition-all">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/></svg>
                    Add Row
                </button>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-teal-50 text-teal-700 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-3 py-3 text-left">#</th>
                            <th class="px-3 py-3 text-left">Fin Prod Name</th>
                            <th class="px-3 py-3 text-left">Fin Prod UOM</th>
                            <th class="px-3 py-3 text-left">SAP Raw Mats SKU</th>
                            <th class="px-3 py-3 text-left">SAP Raw Mats Name</th>
                            <th class="px-3 py-3 text-left">UOM</th>
                            <th class="px-3 py-3 text-left">Qty</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in bomRows" :key="i" class="border-t border-slate-100 hover:bg-slate-50 transition-colors">
                            <td class="px-3 py-2 text-gray-400 font-bold">{{ i + 1 }}</td>
                            <td class="px-2 py-2"><input :value="row.fin_prod_name" @input="updateRow(i, 'fin_prod_name', $event.target.value)" type="text" class="w-full min-w-[130px] border border-slate-200 rounded-lg px-2 py-1.5 text-xs focus:border-teal-400 focus:ring-0" /></td>
                            <td class="px-2 py-2"><input :value="row.fin_prod_uom" @input="updateRow(i, 'fin_prod_uom', $event.target.value)" type="text" placeholder="WHOLE" class="w-full min-w-[80px] border border-slate-200 rounded-lg px-2 py-1.5 text-xs focus:border-teal-400 focus:ring-0" /></td>
                            <td class="px-2 py-2"><input :value="row.sap_raw_mats_sku" @input="updateRow(i, 'sap_raw_mats_sku', $event.target.value)" type="text" placeholder="e.g. 260A2A" class="w-full min-w-[100px] border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono focus:border-teal-400 focus:ring-0" /></td>
                            <td class="px-2 py-2"><input :value="row.sap_raw_mats_name" @input="updateRow(i, 'sap_raw_mats_name', $event.target.value)" type="text" class="w-full min-w-[150px] border border-slate-200 rounded-lg px-2 py-1.5 text-xs focus:border-teal-400 focus:ring-0" /></td>
                            <td class="px-2 py-2"><input :value="row.uom" @input="updateRow(i, 'uom', $event.target.value)" type="text" placeholder="KG" class="w-full min-w-[60px] border border-slate-200 rounded-lg px-2 py-1.5 text-xs focus:border-teal-400 focus:ring-0" /></td>
                            <td class="px-2 py-2"><input :value="row.qty" @input="updateRow(i, 'qty', $event.target.value)" type="number" step="0.0001" class="w-full min-w-[80px] border border-slate-200 rounded-lg px-2 py-1.5 text-xs focus:border-teal-400 focus:ring-0" /></td>
                            <td class="px-2 py-2">
                                <button v-if="bomRows.length > 1" type="button" @click="removeRow(i)" class="text-rose-400 hover:text-rose-600 p-1 rounded-lg hover:bg-rose-50 transition-all">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
