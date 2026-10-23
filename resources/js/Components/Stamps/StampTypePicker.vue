<script setup>
/**
 * Regular / Holiday choice for a stamp being added. Only shown for a program
 * that reserves holiday slots; the server (StampAllocator) decides where the
 * stamp lands and turns a Holiday stamp into a Regular one once every holiday
 * slot the member can hold is filled, so this is the cashier's statement of
 * what was bought, not a guarantee of the slot.
 */
defineProps({
    modelValue: { type: String, default: 'regular' },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const options = [
    { value: 'regular', label: 'Regular', hint: 'Any drink or item' },
    { value: 'holiday', label: 'Holiday', hint: 'Holiday drink' },
]
</script>

<template>
    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Stamp type">
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === o.value"
            :disabled="disabled"
            @click="emit('update:modelValue', o.value)"
            :class="[
                'rounded-lg border px-3 py-2 text-left transition-colors disabled:opacity-50',
                modelValue === o.value
                    ? (o.value === 'holiday'
                        ? 'border-rose-500 bg-rose-50 text-rose-800 ring-1 ring-rose-500 dark:bg-rose-500/10 dark:text-rose-200'
                        : 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-1 ring-emerald-500 dark:bg-emerald-500/10 dark:text-emerald-200')
                    : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700',
            ]"
        >
            <span class="block text-sm font-semibold">{{ o.label }}</span>
            <span class="block text-xs opacity-75">{{ o.hint }}</span>
        </button>
    </div>
</template>
