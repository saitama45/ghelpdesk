<template>
    <div class="flex justify-end space-x-3 pt-4 border-t mt-4">
        <button type="button" @click="$emit('cancel')"
                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
            Cancel
        </button>
        <button type="submit"
                class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-sm">
            {{ submitLabel }}
        </button>
    </div>
</template>

<script setup>
defineProps({ submitLabel: { type: String, default: 'Save' } })
defineEmits(['cancel'])
</script>
