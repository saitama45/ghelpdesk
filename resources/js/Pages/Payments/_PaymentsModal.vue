<template>
    <div class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4 py-6">
            <div class="fixed inset-0 bg-black/30 backdrop-blur-sm" @click="$emit('close')"></div>
            <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-3xl p-6 border border-gray-100">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-900">{{ title }}</h3>
                    <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({ title: { type: String, default: '' } })
defineEmits(['close'])
</script>
