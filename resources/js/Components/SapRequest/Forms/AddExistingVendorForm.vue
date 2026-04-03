<script setup>
const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    companies: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
function update(key, val) { emit('update:modelValue', { ...props.modelValue, [key]: val }) }

const ENTITIES = ['TGI', 'GSI', 'EDI', 'H63', 'S7S']

function toggleToEntity(entity) {
    const current = props.modelValue.copy_to_entities ?? []
    const updated = current.includes(entity) ? current.filter(e => e !== entity) : [...current, entity]
    update('copy_to_entities', updated)
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Vendor Name <span class="text-rose-500">*</span></label>
            <input :value="modelValue.vendor_name" @input="update('vendor_name', $event.target.value)" type="text"
                class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Copy FROM what Entity? <span class="text-rose-500">*</span> <span class="text-gray-400 font-normal normal-case">(one only)</span></label>
            <div class="flex flex-wrap gap-2">
                <button v-for="e in ENTITIES" :key="e" type="button"
                    @click="update('copy_from_entity', e)"
                    :class="modelValue.copy_from_entity === e ? 'bg-teal-600 text-white shadow-lg' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-4 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ e }}
                </button>
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Copy TO what Entity? <span class="text-rose-500">*</span> <span class="text-gray-400 font-normal normal-case">(can choose multiple)</span></label>
            <div class="flex flex-wrap gap-2">
                <button v-for="e in ENTITIES" :key="e" type="button"
                    @click="toggleToEntity(e)"
                    :class="(modelValue.copy_to_entities ?? []).includes(e) ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-4 py-2 rounded-xl text-sm font-bold transition-all">
                    {{ e }}
                </button>
            </div>
        </div>
    </div>
</template>
