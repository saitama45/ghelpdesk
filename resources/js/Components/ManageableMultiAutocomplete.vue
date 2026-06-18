<script setup>
import { ref, computed, reactive, nextTick, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { PlusIcon, PencilSquareIcon, TrashIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    modelValue: { type: Array, default: () => [] }, // array of selected values (strings)
    options: { type: Array, default: () => [] },     // [{ id, value, label, sort_order }]
    optionType: { type: String, required: true },
    placeholder: { type: String, default: 'Select one or more...' },
    disabled: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
    canEdit:   { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'options-changed'])

const isOpen       = ref(false)
const searchQuery  = ref('')
const containerRef = ref(null)
const searchRef    = ref(null)
const localOptions = ref([...props.options])

const mode          = ref(null)   // null | 'add' | 'edit'
const inlineLabel   = ref('')
const editingOption = ref(null)
const saving        = ref(false)
const inlineError   = ref('')
const inlineLabelRef = ref(null)

const dropdownStyle = reactive({ top: '0px', left: '0px', width: '0px', maxHeight: '260px', bottom: 'auto' })

const selectedValues = computed(() => new Set(props.modelValue || []))

const selectedChips = computed(() =>
    (props.modelValue || []).map(val => {
        const opt = localOptions.value.find(o => o.value === val)
        return { value: val, label: opt ? opt.label : val }
    })
)

const filteredOptions = computed(() => {
    const q = searchQuery.value.toLowerCase().trim()
    if (!q) return localOptions.value
    return localOptions.value.filter(o =>
        o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q)
    )
})

const isSelected = (option) => selectedValues.value.has(option.value)

const updatePosition = () => {
    if (!containerRef.value) return
    const rect = containerRef.value.getBoundingClientRect()
    const spaceBelow = window.innerHeight - rect.bottom
    const spaceAbove = rect.top

    if (spaceBelow < 220 && spaceAbove > spaceBelow) {
        dropdownStyle.top = 'auto'
        dropdownStyle.bottom = `${window.innerHeight - rect.top + 4}px`
        dropdownStyle.maxHeight = `${Math.min(320, spaceAbove - 16)}px`
    } else {
        dropdownStyle.top = `${rect.bottom + window.scrollY + 4}px`
        dropdownStyle.bottom = 'auto'
        dropdownStyle.maxHeight = `${Math.min(320, spaceBelow - 16)}px`
    }
    dropdownStyle.left = `${rect.left + window.scrollX}px`
    dropdownStyle.width = `${rect.width}px`
}

const openDropdown = async () => {
    if (props.disabled) return
    isOpen.value = true
    mode.value = null
    inlineLabel.value = ''
    inlineError.value = ''
    searchQuery.value = ''
    await nextTick()
    updatePosition()
    searchRef.value?.focus()
}

const closeDropdown = () => {
    isOpen.value = false
    mode.value = null
    inlineLabel.value = ''
    inlineError.value = ''
    editingOption.value = null
    searchQuery.value = ''
}

const handleClickOutside = (e) => {
    if (containerRef.value && !containerRef.value.contains(e.target)) {
        closeDropdown()
    }
}

onMounted(() => document.addEventListener('mousedown', handleClickOutside))
onUnmounted(() => document.removeEventListener('mousedown', handleClickOutside))

// toggle selection (keep dropdown open for multi-select)
const toggle = (option) => {
    const next = new Set(props.modelValue || [])
    if (next.has(option.value)) next.delete(option.value)
    else next.add(option.value)
    emit('update:modelValue', Array.from(next))
}

const removeChip = (value) => {
    emit('update:modelValue', (props.modelValue || []).filter(v => v !== value))
}

const startAdd = async () => {
    mode.value = 'add'
    inlineLabel.value = searchQuery.value
    inlineError.value = ''
    await nextTick()
    inlineLabelRef.value?.focus()
}

const startEdit = async (option, e) => {
    e.stopPropagation()
    mode.value = 'edit'
    editingOption.value = option
    inlineLabel.value = option.label
    inlineError.value = ''
    await nextTick()
    inlineLabelRef.value?.focus()
}

const cancelInline = () => {
    mode.value = null
    inlineLabel.value = ''
    inlineError.value = ''
    editingOption.value = null
}

const saveOption = async () => {
    const label = inlineLabel.value.trim()
    if (!label) { inlineError.value = 'Label is required.'; return }

    saving.value = true
    inlineError.value = ''

    try {
        if (mode.value === 'add') {
            const { data } = await axios.post(route('reference-options.store'), {
                type: props.optionType,
                value: label,
                label,
            })
            localOptions.value.push(data)
            emit('options-changed', [...localOptions.value])
            // auto-select the newly created option
            emit('update:modelValue', Array.from(new Set([...(props.modelValue || []), data.value])))
        } else {
            const { data } = await axios.put(route('reference-options.update', editingOption.value.id), {
                label,
                sort_order: editingOption.value.sort_order,
            })
            const idx = localOptions.value.findIndex(o => o.id === data.id)
            if (idx !== -1) localOptions.value[idx] = data
            emit('options-changed', [...localOptions.value])
        }
        cancelInline()
    } catch (err) {
        inlineError.value = err.response?.data?.message || 'Failed to save.'
    } finally {
        saving.value = false
    }
}

const deleteOption = async (option, e) => {
    e.stopPropagation()
    try {
        await axios.delete(route('reference-options.destroy', option.id))
        localOptions.value = localOptions.value.filter(o => o.id !== option.id)
        emit('options-changed', [...localOptions.value])
        if (selectedValues.value.has(option.value)) {
            emit('update:modelValue', (props.modelValue || []).filter(v => v !== option.value))
        }
    } catch (err) {
        alert(err.response?.data?.message || 'Cannot delete this option.')
    }
}
</script>

<template>
    <div ref="containerRef" class="relative w-full">
        <!-- Trigger -->
        <button
            type="button"
            :disabled="disabled"
            @click="openDropdown"
            class="w-full flex items-center justify-between gap-2 bg-white border border-gray-300 rounded-md shadow-sm px-3 py-2 text-left text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 transition-all duration-150 dark:bg-gray-800 dark:border-gray-600"
            :class="disabled ? 'bg-gray-50 text-gray-400 cursor-not-allowed' : 'hover:border-blue-400 cursor-pointer'"
        >
            <span v-if="selectedChips.length === 0" class="text-gray-400 dark:text-gray-400">{{ placeholder }}</span>
            <span v-else class="flex flex-wrap gap-1">
                <span
                    v-for="chip in selectedChips"
                    :key="chip.value"
                    class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-50 text-blue-700 rounded-md text-xs font-semibold border border-blue-100 dark:border-blue-400/30 dark:bg-blue-500/15 dark:text-blue-200"
                >
                    {{ chip.label }}
                    <span v-if="!disabled" @click.stop="removeChip(chip.value)" class="text-blue-400 hover:text-red-600 cursor-pointer">
                        <XMarkIcon class="w-3 h-3" />
                    </span>
                </span>
            </span>
            <svg class="h-4 w-4 text-gray-400 shrink-0 ml-auto dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
        </button>

        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="isOpen"
                class="fixed z-[9999] bg-white border border-gray-200 rounded-xl shadow-2xl flex flex-col overflow-hidden dark:bg-gray-800 dark:border-gray-700"
                :style="{ top: dropdownStyle.top, left: dropdownStyle.left, width: dropdownStyle.width, maxHeight: dropdownStyle.maxHeight, bottom: dropdownStyle.bottom }"
            >
                <div class="px-2 pt-2 pb-1 border-b border-gray-100 dark:border-gray-700">
                    <input
                        ref="searchRef"
                        v-model="searchQuery"
                        type="text"
                        class="w-full text-xs border-gray-200 rounded-lg px-2.5 py-1.5 focus:ring-blue-500 focus:border-blue-500 placeholder-gray-300 dark:border-gray-700"
                        placeholder="Search..."
                        @keydown.esc="closeDropdown"
                    />
                </div>

                <ul class="overflow-y-auto flex-1 py-1 custom-scrollbar">
                    <li
                        v-for="option in filteredOptions"
                        :key="option.id"
                        class="group flex items-center px-1 hover:bg-blue-50 transition-colors cursor-pointer dark:hover:bg-blue-500/15"
                        @click="toggle(option)"
                    >
                        <span class="w-5 flex justify-center shrink-0">
                            <CheckIcon v-if="isSelected(option)" class="w-4 h-4 text-blue-600" />
                        </span>
                        <span
                            class="flex-1 px-2 py-2 text-sm truncate"
                            :class="isSelected(option) ? 'font-bold text-blue-700' : 'text-gray-800'"
                        >
                            {{ option.label }}
                        </span>
                        <div v-if="canEdit || canDelete" class="hidden group-hover:flex items-center gap-0.5 pr-1" @click.stop>
                            <button v-if="canEdit" type="button" @click="startEdit(option, $event)"
                                class="p-1 rounded text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors dark:text-slate-300 dark:hover:bg-blue-500/15 dark:hover:text-blue-200" title="Edit">
                                <PencilSquareIcon class="w-3.5 h-3.5" />
                            </button>
                            <button v-if="canDelete" type="button" @click="deleteOption(option, $event)"
                                class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors dark:text-slate-300 dark:hover:bg-red-500/15 dark:hover:text-red-200" title="Delete">
                                <TrashIcon class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </li>
                    <li v-if="filteredOptions.length === 0 && mode !== 'add'" class="px-3 py-3 text-xs text-gray-400 text-center italic dark:text-gray-400">
                        No options found
                    </li>
                </ul>

                <!-- Inline edit -->
                <div v-if="mode === 'edit'" class="px-2 py-2 border-t border-gray-100 bg-blue-50/50 dark:border-slate-700 dark:bg-blue-500/10">
                    <p class="text-[10px] font-black uppercase tracking-widest text-blue-500 mb-1.5">Edit Option</p>
                    <div class="flex items-center gap-1.5">
                        <input ref="inlineLabelRef" v-model="inlineLabel" type="text"
                            class="flex-1 text-xs border-gray-200 rounded-lg px-2.5 py-1.5 focus:ring-blue-500 focus:border-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            placeholder="Option label..." @keydown.enter.prevent="saveOption" @keydown.esc="cancelInline" />
                        <button type="button" @click="saveOption" :disabled="saving"
                            class="p-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
                            <CheckIcon class="w-3.5 h-3.5" />
                        </button>
                        <button type="button" @click="cancelInline"
                            class="p-1.5 bg-gray-100 text-gray-500 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            <XMarkIcon class="w-3.5 h-3.5" />
                        </button>
                    </div>
                    <p v-if="inlineError" class="text-[10px] text-red-500 mt-1">{{ inlineError }}</p>
                </div>

                <!-- Inline add -->
                <div v-else-if="mode === 'add'" class="px-2 py-2 border-t border-gray-100 bg-green-50/50 dark:border-slate-700 dark:bg-green-500/10">
                    <p class="text-[10px] font-black uppercase tracking-widest text-green-600 mb-1.5">Add New Option</p>
                    <div class="flex items-center gap-1.5">
                        <input ref="inlineLabelRef" v-model="inlineLabel" type="text"
                            class="flex-1 text-xs border-gray-200 rounded-lg px-2.5 py-1.5 focus:ring-green-500 focus:border-green-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            placeholder="New option label..." @keydown.enter.prevent="saveOption" @keydown.esc="cancelInline" />
                        <button type="button" @click="saveOption" :disabled="saving"
                            class="p-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors">
                            <CheckIcon class="w-3.5 h-3.5" />
                        </button>
                        <button type="button" @click="cancelInline"
                            class="p-1.5 bg-gray-100 text-gray-500 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            <XMarkIcon class="w-3.5 h-3.5" />
                        </button>
                    </div>
                    <p v-if="inlineError" class="text-[10px] text-red-500 mt-1">{{ inlineError }}</p>
                </div>

                <!-- Add new button -->
                <div v-else-if="canCreate" class="border-t border-gray-100 dark:border-gray-700">
                    <button type="button" @click="startAdd"
                        class="w-full flex items-center gap-2 px-3 py-2 text-xs font-bold text-blue-600 hover:bg-blue-50 transition-colors dark:text-blue-300 dark:hover:bg-blue-500/15">
                        <PlusIcon class="w-3.5 h-3.5" />
                        Add new option
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>
