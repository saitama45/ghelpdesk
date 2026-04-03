<script setup>
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
function update(key, val) { emit('update:modelValue', { ...props.modelValue, [key]: val }) }

const ENTITIES = ['TGI', 'GSI', 'EDI', 'H63', 'S7S']

function toggleToEntity(entity) {
    const current = props.modelValue.to_entities ?? []
    const updated = current.includes(entity) ? current.filter(e => e !== entity) : [...current, entity]
    update('to_entities', updated)
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2">Single or Multiple SKU? <span class="text-rose-500">*</span></label>
            <div class="flex gap-3">
                <button v-for="opt in ['Single', 'Multiple']" :key="opt" type="button"
                    @click="update('sku_mode', opt)"
                    :class="modelValue.sku_mode === opt ? 'bg-teal-600 text-white shadow-lg' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-5 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ opt }}
                </button>
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">SAP Item Name <span class="text-rose-500">*</span></label>
            <input :value="modelValue.sap_item_name" @input="update('sap_item_name', $event.target.value)" type="text"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">FROM what Entity? <span class="text-rose-500">*</span> <span class="text-gray-400 font-normal normal-case">(one only)</span></label>
            <div class="flex flex-wrap gap-2">
                <button v-for="e in ENTITIES" :key="e" type="button"
                    @click="update('from_entity', e)"
                    :class="modelValue.from_entity === e ? 'bg-teal-600 text-white shadow-lg' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-4 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ e }}
                </button>
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Which Entity to add to? <span class="text-rose-500">*</span> <span class="text-gray-400 font-normal normal-case">(can choose multiple)</span></label>
            <div class="flex flex-wrap gap-2">
                <button v-for="e in ENTITIES" :key="e" type="button"
                    @click="toggleToEntity(e)"
                    :class="(modelValue.to_entities ?? []).includes(e) ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-4 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ e }}
                </button>
            </div>
        </div>
    </div>
</template>
