<template>
    <div>
        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">
            {{ label }} <span v-if="required" class="text-red-500">*</span>
        </label>
        <slot />
    </div>
</template>

<script setup>
defineProps({
    label: { type: String, required: true },
    required: { type: Boolean, default: false },
})
</script>
