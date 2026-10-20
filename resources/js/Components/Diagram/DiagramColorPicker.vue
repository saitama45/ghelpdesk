<script setup>
// Swatch row + "none" + a custom colour input. Emits a hex string, or null for "none".
defineProps({
    modelValue: { type: String, default: null },
    colors: { type: Array, required: true },
    allowNone: { type: Boolean, default: false },
    label: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <div class="mb-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ label }}</div>
        <div class="flex flex-wrap items-center gap-1">
            <button
                v-if="allowNone"
                type="button"
                :title="`${label}: none`"
                :aria-label="`${label}: none`"
                class="relative h-5 w-5 overflow-hidden rounded border border-gray-300 bg-white dark:border-gray-600"
                :class="!modelValue ? 'ring-2 ring-blue-500 ring-offset-1 dark:ring-offset-gray-900' : ''"
                @click="emit('update:modelValue', null)"
            >
                <span class="absolute left-1/2 top-[-2px] h-[26px] w-px -translate-x-1/2 rotate-45 bg-red-500"></span>
            </button>
            <button
                v-for="color in colors"
                :key="color"
                type="button"
                :title="color"
                :aria-label="`${label} ${color}`"
                class="h-5 w-5 rounded border border-gray-300 dark:border-gray-600"
                :class="modelValue?.toLowerCase() === color.toLowerCase() ? 'ring-2 ring-blue-500 ring-offset-1 dark:ring-offset-gray-900' : ''"
                :style="{ backgroundColor: color }"
                @click="emit('update:modelValue', color)"
            ></button>
            <label class="relative h-5 w-5 cursor-pointer overflow-hidden rounded border border-dashed border-gray-400" :title="`${label}: custom colour`">
                <span class="absolute inset-0 flex items-center justify-center text-[10px] font-bold text-gray-500">+</span>
                <input
                    type="color"
                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    :value="modelValue || '#ffffff'"
                    :aria-label="`${label}: custom colour`"
                    @change="emit('update:modelValue', $event.target.value)"
                >
            </label>
        </div>
    </div>
</template>
