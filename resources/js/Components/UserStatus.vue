<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: {
        type: String,
        default: 'offline'
    },
    size: {
        type: String,
        default: 'md' // sm, md, lg, xl
    }
});

const statusClasses = computed(() => {
    switch (props.status) {
        case 'online':
            return 'bg-green-500 shadow-[0_0_6px_rgba(34,197,94,0.6)]';
        case 'idle':
            return 'bg-orange-500 shadow-[0_0_6px_rgba(249,115,22,0.6)]';
        case 'dnd':
            return 'bg-red-500 shadow-[0_0_6px_rgba(239,68,68,0.6)]';
        case 'offline':
        default:
            return 'bg-gray-400';
    }
});

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'h-2.5 w-2.5 border'; // Small badge
        case 'md':
            return 'h-3.5 w-3.5 border-2'; // Standard inline
        case 'lg':
            return 'h-4 w-4 border-2'; // Large (for emphasized visibility)
        case 'xl':
            return 'h-5 w-5 border-2'; // Extra Large
        default:
            return 'h-3.5 w-3.5 border-2';
    }
});
</script>

<template>
    <span 
        :class="[statusClasses, sizeClasses]" 
        class="inline-block rounded-full border-white"
        :title="status"
    ></span>
</template>