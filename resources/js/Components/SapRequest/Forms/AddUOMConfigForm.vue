<script setup>
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
function update(key, val) { emit('update:modelValue', { ...props.modelValue, [key]: val }) }
</script>

<template>
    <div class="space-y-5">
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">SAP Code <span class="text-rose-500">*</span></label>
            <input :value="modelValue.sap_code" @input="update('sap_code', $event.target.value)" type="text"
                placeholder="Existing SAP item code"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">New Packaging Info / UOM Conversion <span class="text-rose-500">*</span></label>
            <input :value="modelValue.uom_conversion" @input="update('uom_conversion', $event.target.value)" type="text"
                placeholder="e.g. 1 Case x 24 Bot x 1 Lit"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
    </div>
</template>
