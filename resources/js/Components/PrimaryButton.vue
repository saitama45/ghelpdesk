<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
    disabled: {
        type: Boolean,
        default: false,
    }
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        :class="[
            'inline-flex items-center justify-center rounded-lg border border-transparent px-4 py-2 text-sm font-semibold text-white transition-all duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2',
            disabled ? 'bg-gray-400 cursor-not-allowed opacity-60 grayscale' : ($attrs.class || 'bg-gray-800 hover:bg-gray-700 focus:bg-gray-700 focus:ring-gray-500 active:bg-gray-900')
        ]"
    >
        <slot />
    </button>
</template>
