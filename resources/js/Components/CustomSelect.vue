<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, reactive } from 'vue';

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
        default: 'Select an option',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const containerRef = ref(null);
const uniqueId = Math.random().toString(36).substr(2, 9);

const dropdownStyle = reactive({
    top: '0px',
    left: '0px',
    width: '0px',
    maxHeight: '350px', // Increased to fit ~9-10 items comfortably before scrolling
});

const selectedOption = computed(() => {
    return props.options.find(opt => {
        const val = typeof opt === 'object' ? opt[props.valueKey] : opt;
        return val == props.modelValue;
    });
});

const updatePosition = () => {
    if (!containerRef.value) return;
    
    const rect = containerRef.value.getBoundingClientRect();
    const viewportHeight = window.innerHeight;
    const spaceBelow = viewportHeight - rect.bottom;
    const spaceAbove = rect.top;
    const preferredHeight = 350; // Our target max height
    
    // Position logic
    if (spaceBelow < 200 && spaceAbove > spaceBelow) {
        // Not enough space below, flip to above
        dropdownStyle.top = 'auto';
        dropdownStyle.bottom = `${viewportHeight - rect.top + 4}px`;
        dropdownStyle.maxHeight = `${Math.min(preferredHeight, spaceAbove - 20)}px`;
    } else {
        // Position below
        dropdownStyle.top = `${rect.bottom + 4}px`;
        dropdownStyle.bottom = 'auto';
        dropdownStyle.maxHeight = `${Math.min(preferredHeight, spaceBelow - 20)}px`;
    }
    
    dropdownStyle.left = `${rect.left}px`;
    dropdownStyle.width = `${rect.width}px`;
};

const toggle = async () => {
    if (props.disabled) return;
    
    if (!isOpen.value) {
        isOpen.value = true;
        await nextTick();
        updatePosition();
        // Second pass to ensure everything is settled
        requestAnimationFrame(updatePosition);
    } else {
        isOpen.value = false;
    }
};

const close = () => {
    isOpen.value = false;
};

const select = (option) => {
    const val = typeof option === 'object' ? option[props.valueKey] : option;
    emit('update:modelValue', val);
    close();
};

const handleClickOutside = (event) => {
    const isTrigger = containerRef.value?.contains(event.target);
    const dropdownEl = document.getElementById('custom-select-dropdown-' + uniqueId);
    const isDropdown = dropdownEl?.contains(event.target);

    if (!isTrigger && !isDropdown) {
        close();
    }
};

const handleGlobalEvents = () => {
    if (isOpen.value) updatePosition();
};

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside);
    window.addEventListener('scroll', handleGlobalEvents, true);
    window.addEventListener('resize', handleGlobalEvents);
});

onUnmounted(() => {
    document.removeEventListener('mousedown', handleClickOutside);
    window.removeEventListener('scroll', handleGlobalEvents, true);
    window.removeEventListener('resize', handleGlobalEvents);
});
</script>

<template>
    <div ref="containerRef" class="relative">
        <button
            type="button"
            @click="toggle"
            :disabled="disabled"
            class="w-full bg-white border border-gray-300 rounded-lg shadow-sm pl-3 pr-10 py-2.5 text-left cursor-default focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 sm:text-sm transition-all duration-200"
            :class="{ 'bg-gray-50 text-gray-400 cursor-not-allowed': disabled, 'hover:border-blue-400': !disabled }"
        >
            <span class="block truncate">
                <slot name="trigger" :selected="selectedOption" :placeholder="placeholder">
                    <span v-if="selectedOption" class="text-gray-900">
                        {{ typeof selectedOption === 'object' ? selectedOption[labelKey] : selectedOption }}
                    </span>
                    <span v-else class="text-gray-400">{{ placeholder }}</span>
                </slot>
            </span>
            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 3a1 1 0 01.707.293l3 3a1 1 0 01-1.414 1.414L10 5.414 7.707 7.707a1 1 0 01-1.414-1.414l3-3A1 1 0 0110 3zm-3.707 9.293a1 1 0 011.414 0L10 14.586l2.293-2.293a1 1 0 011.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </span>
        </button>

        <Teleport to="body">
            <transition
                leave-active-class="transition ease-in duration-100"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div 
                    v-if="isOpen" 
                    :id="'custom-select-dropdown-' + uniqueId"
                    class="fixed z-[9999] bg-white shadow-2xl border border-gray-200 rounded-lg overflow-hidden flex flex-col"
                    :style="dropdownStyle"
                >
                    <div class="overflow-y-auto overscroll-contain flex-grow custom-scrollbar">
                        <ul class="py-1">
                            <li
                                v-for="(option, index) in options"
                                :key="index"
                                class="text-gray-900 cursor-pointer select-none relative py-2.5 pl-3 pr-9 hover:bg-blue-50 transition-colors"
                                @click="select(option)"
                            >
                                <slot name="option" :option="option" :selected="(typeof option === 'object' ? option[valueKey] : option) == modelValue">
                                    <span class="block truncate" :class="{ 'font-bold text-blue-700': (typeof option === 'object' ? option[valueKey] : option) == modelValue }">
                                        {{ typeof option === 'object' ? option[labelKey] : option }}
                                    </span>
                                </slot>

                                <span v-if="(typeof option === 'object' ? option[valueKey] : option) == modelValue" class="text-blue-600 absolute inset-y-0 right-0 flex items-center pr-3">
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            </li>
                            <li v-if="options.length === 0" class="px-3 py-4 text-sm text-gray-500 text-center italic">
                                No options available
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