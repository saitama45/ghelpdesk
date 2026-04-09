<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, reactive, watch } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
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
        default: 'Search or select...',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    size: {
        type: String,
        default: 'md', // md, sm
    }
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
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

const selectedOption = computed(() => {
    return props.options.find(opt => {
        const val = typeof opt === 'object' ? opt[props.valueKey] : opt;
        return val == props.modelValue;
    });
});

const filteredOptions = computed(() => {
    if (!searchQuery.value) return props.options;
    
    const query = searchQuery.value.toLowerCase();
    return props.options.filter(opt => {
        const label = typeof opt === 'object' ? opt[props.labelKey] : opt;
        return String(label).toLowerCase().includes(query);
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
    inputRef.value?.focus({ preventScroll: true });
};

const closeDropdown = () => {
    isOpen.value = false;
    searchQuery.value = '';
};

const select = (option) => {
    const actualValue = typeof option === 'object' ? option[props.valueKey] : option;
    emit('update:modelValue', actualValue);
    closeDropdown();
};

const handleClickOutside = (event) => {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
        closeDropdown();
    }
};

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('mousedown', handleClickOutside);
});

// Sync searchQuery when selectedOption changes if dropdown is closed
watch(selectedOption, (newVal) => {
    if (!isOpen.value && newVal) {
        // We don't really need to sync here since we show selected label in trigger
    }
});
</script>

<template>
    <div ref="containerRef" class="relative w-full">
        <div
            @click="openDropdown"
            class="w-full bg-white border border-gray-300 rounded-lg shadow-sm pl-3 pr-10 py-2.5 text-left cursor-text focus-within:ring-2 focus-within:ring-blue-500/20 focus-within:border-blue-500 transition-all duration-200"
            :class="[
                disabled ? 'bg-gray-50 text-gray-400 cursor-not-allowed' : 'hover:border-blue-400',
                size === 'sm' ? 'text-xs' : 'sm:text-sm'
            ]"
        >
            <div v-if="!isOpen">
                <span v-if="selectedOption" class="text-gray-900 break-words line-clamp-2">
                    {{ typeof selectedOption === 'object' ? selectedOption[labelKey] : selectedOption }}
                </span>
                <span v-else class="text-gray-400">{{ placeholder }}</span>
            </div>
            
            <input
                v-if="isOpen"
                ref="inputRef"
                v-model="searchQuery"
                type="text"
                class="w-full p-0 border-none focus:ring-0 bg-transparent"
                :class="size === 'sm' ? 'text-xs' : 'text-sm'"
                :placeholder="selectedOption ? (typeof selectedOption === 'object' ? selectedOption[labelKey] : selectedOption) : placeholder"
                @keydown.esc="closeDropdown"
            />

            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 3a1 1 0 01.707.293l3 3a1 1 0 01-1.414 1.414L10 5.414 7.707 7.707a1 1 0 01-1.414-1.414l3-3A1 1 0 0110 3zm-3.707 9.293a1 1 0 011.414 0L10 14.586l2.293-2.293a1 1 0 011.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </span>
        </div>

        <transition
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div 
                v-if="isOpen" 
                class="absolute z-[100] mt-1 w-full bg-white shadow-2xl border border-gray-200 rounded-lg overflow-hidden flex flex-col max-h-[300px]"
            >
                <div class="overflow-y-auto overscroll-contain flex-grow custom-scrollbar">
                    <ul class="py-1">
                        <li
                            v-for="(option, index) in filteredOptions"
                            :key="index"
                            class="text-gray-900 cursor-pointer select-none relative py-2.5 pl-3 pr-9 hover:bg-blue-50 transition-colors"
                            :class="size === 'sm' ? 'text-xs' : 'text-sm'"
                            @click="select(option)"
                        >
                            <span class="block break-words" :class="{ 'font-bold text-blue-700': (typeof option === 'object' ? option[valueKey] : option) == modelValue }">
                                {{ typeof option === 'object' ? option[labelKey] : option }}
                            </span>

                            <span v-if="(typeof option === 'object' ? option[valueKey] : option) == modelValue" class="text-blue-600 absolute inset-y-0 right-0 flex items-center pr-3">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </li>
                        <li v-if="filteredOptions.length === 0" class="px-3 py-4 text-sm text-gray-500 text-center italic">
                            No results found for "{{ searchQuery }}"
                        </li>
                    </ul>
                </div>
            </div>
        </transition>
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