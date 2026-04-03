<script setup>
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
function update(key, val) { emit('update:modelValue', { ...props.modelValue, [key]: val }) }
function onFile(key, e) { update(key, e.target.files[0]?.name ?? '') }

const STORE_GROUPS = ["CBTL", "Nono's"]
</script>

<template>
    <div class="space-y-5">
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Approved Store Code <span class="text-rose-500">*</span></label>
            <input :value="modelValue.store_code" @input="update('store_code', $event.target.value)" type="text"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Store Group <span class="text-rose-500">*</span></label>
            <div class="flex gap-3">
                <button v-for="g in STORE_GROUPS" :key="g" type="button"
                    @click="update('store_group', g)"
                    :class="modelValue.store_group === g ? 'bg-teal-600 text-white shadow-lg' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-5 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ g }}
                </button>
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">COR Attachment <span class="text-rose-500">*</span></label>
            <input type="file" @change="onFile('cor_attachment', $event)"
                class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
            <p class="text-xs font-bold text-amber-700">
                <span class="font-black">Note:</span> SAP Pricelist Group, Consolidating Business Partner, GL Category, and Cost Center will be filled in by the 1st Approver (Accounting Receivables).
            </p>
        </div>
    </div>
</template>
