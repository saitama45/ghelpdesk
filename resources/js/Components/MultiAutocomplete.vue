<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, reactive, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    options: {
        type: Array,
        default: () => [],
    },
    labelKey: {
        type: String,
        default: 'label',
    },
    valueKey: {
        type: String,
        default: 'value',
    },
    placeholder: {
        type: String,
        default: 'Select multiple...',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    limit: {
        type: Number,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const isExpanded = ref(false);
const searchQuery = ref('');
const containerRef = ref(null);
const inputRef = ref(null);
const uniqueId = Math.random().toString(36).substr(2, 9);

const dropdownStyle = reactive({
    top: '0px',
    left: '0px',
    width: '0px',
    maxHeight: '300px',
});

const selectedOptions = computed(() => {
    return props.options.filter(opt => {
        const val = typeof opt === 'object' ? opt[props.valueKey] : opt;
        return props.modelValue.includes(val);
    });
});

const displayedSelectedOptions = computed(() => {
    if (!props.limit || isExpanded.value || selectedOptions.value.length <= props.limit) {
        return selectedOptions.value;
    }
    return selectedOptions.value.slice(0, props.limit);
});

const remainingCount = computed(() => {
    return selectedOptions.value.length - displayedSelectedOptions.value.length;
});

const filteredOptions = computed(() => {
    const query = searchQuery.value.toLowerCase();
    return props.options.filter(opt => {
        const label = typeof opt === 'object' ? opt[props.labelKey] : opt;
        const val = typeof opt === 'object' ? opt[props.valueKey] : opt;
        
        // Filter by search query AND exclude already selected items
        const matchesQuery = String(label).toLowerCase().includes(query);
        const isSelected = props.modelValue.includes(val);
        
        return matchesQuery && !isSelected;
    });
});

const updatePosition = () => {
    if (!containerRef.value) return;
    
    const rect = containerRef.value.getBoundingClientRect();
    const viewportHeight = window.innerHeight;
    const spaceBelow = viewportHeight - rect.bottom;
    const spaceAbove = rect.top;
    const preferredHeight = 300;
    
    if (spaceBelow < 200 && spaceAbove > spaceBelow) {
        dropdownStyle.top = 'auto';
        dropdownStyle.bottom = `${viewportHeight - rect.top + 4}px`;
        dropdownStyle.maxHeight = `${Math.min(preferredHeight, spaceAbove - 20)}px`;
    } else {
        dropdownStyle.top = `${rect.bottom + 4}px`;
        dropdownStyle.bottom = 'auto';
        dropdownStyle.maxHeight = `${Math.min(preferredHeight, spaceBelow - 20)}px`;
    }
    
    dropdownStyle.left = `${rect.left}px`;
    dropdownStyle.width = `${rect.width}px`;
};

const openDropdown = async () => {
    if (props.disabled) return;
    isOpen.value = true;
    await nextTick();
    updatePosition();
    inputRef.value?.focus();
};

const closeDropdown = () => {
    isOpen.value = false;
    searchQuery.value = '';
};

const toggleOption = (option) => {
    const val = typeof option === 'object' ? option[props.valueKey] : option;
    const newValue = [...props.modelValue];
    const index = newValue.indexOf(val);
    
    if (index === -1) {
        newValue.push(val);
    } else {
        newValue.splice(index, 1);
    }
    
    emit('update:modelValue', newValue);
    searchQuery.value = '';
    // Keep open for multi-select
};

const removeOption = (val) => {
    const newValue = props.modelValue.filter(v => v !== val);
    emit('update:modelValue', newValue);
};

const handleClickOutside = (event) => {
    const isTrigger = containerRef.value?.contains(event.target);
    const dropdownEl = document.getElementById('multi-autocomplete-dropdown-' + uniqueId);
    const isDropdown = dropdownEl?.contains(event.target);

    if (!isTrigger && !isDropdown) {
        closeDropdown();
    }
};

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside);
    window.addEventListener('resize', updatePosition);
});

onUnmounted(() => {
    document.removeEventListener('mousedown', handleClickOutside);
    window.removeEventListener('resize', updatePosition);
});
</script>

<template>
    <div ref="containerRef" class="relative">
        <div
            @click="openDropdown"
            class="w-full bg-white border border-gray-300 rounded-lg shadow-sm p-1.5 text-left cursor-text focus-within:ring-2 focus-within:ring-blue-500/20 focus-within:border-blue-500 transition-all duration-200 min-h-[42px]"
            :class="{ 'bg-gray-50 text-gray-400 cursor-not-allowed': disabled, 'hover:border-blue-400': !disabled }"
        >
            <div class="flex flex-wrap gap-1">
                <span 
                    v-for="opt in displayedSelectedOptions" 
                    :key="typeof opt === 'object' ? opt[valueKey] : opt"
                    class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-bold border border-blue-200"
                >
                    {{ typeof opt === 'object' ? opt[labelKey] : opt }}
                    <button 
                        @click.stop="removeOption(typeof opt === 'object' ? opt[valueKey] : opt)"
                        class="hover:text-blue-900"
                    >
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </span>

                <!-- View All / More Badge -->
                <button
                    v-if="limit && !isExpanded && selectedOptions.length > limit"
                    @click.stop="isExpanded = true"
                    class="inline-flex items-center px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-black border border-gray-200 hover:bg-gray-200 transition-colors uppercase tracking-tighter"
                >
                    +{{ remainingCount }} more
                </button>

                <!-- Collapse Button -->
                <button
                    v-if="limit && isExpanded"
                    @click.stop="isExpanded = false"
                    class="inline-flex items-center px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-black border border-gray-200 hover:bg-gray-200 transition-colors uppercase tracking-tighter"
                >
                    Show Less
                </button>
                
                <input
                    ref="inputRef"
                    v-model="searchQuery"
                    type="text"
                    class="flex-1 min-w-[60px] p-1 border-none focus:ring-0 text-sm bg-transparent"
                    :placeholder="modelValue.length === 0 ? placeholder : ''"
                    @keydown.esc="closeDropdown"
                    @keydown.backspace="searchQuery === '' && modelValue.length > 0 && removeOption(modelValue[modelValue.length-1])"
                />
            </div>

            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 3a1 1 0 01.707.293l3 3a1 1 0 01-1.414 1.414L10 5.414 7.707 7.707a1 1 0 01-1.414-1.414l3-3A1 1 0 0110 3zm-3.707 9.293a1 1 0 011.414 0L10 14.586l2.293-2.293a1 1 0 011.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </span>
        </div>

        <Teleport to="body">
            <transition
                leave-active-class="transition ease-in duration-100"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div 
                    v-if="isOpen" 
                    :id="'multi-autocomplete-dropdown-' + uniqueId"
                    class="fixed z-[10000] bg-white shadow-2xl border border-gray-200 rounded-lg overflow-hidden flex flex-col"
                    :style="dropdownStyle"
                >
                    <div class="overflow-y-auto overscroll-contain flex-grow custom-scrollbar">
                        <ul class="py-1">
                            <li
                                v-for="(option, index) in filteredOptions"
                                :key="index"
                                class="text-gray-900 cursor-pointer select-none relative py-2.5 pl-3 pr-9 hover:bg-blue-50 transition-colors text-sm"
                                @click="toggleOption(option)"
                            >
                                <span class="block truncate">
                                    {{ typeof option === 'object' ? option[labelKey] : option }}
                                </span>
                            </li>
                            <li v-if="filteredOptions.length === 0" class="px-3 py-4 text-sm text-gray-500 text-center italic">
                                {{ searchQuery === '' ? 'All options selected' : 'No results found for "' + searchQuery + '"' }}
                            </li>
                        </ul>
                    </div>
                </div>
            </transition>
        </Teleport>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #cbd5e1;
}
</style>