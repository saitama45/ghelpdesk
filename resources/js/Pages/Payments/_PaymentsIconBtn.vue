<template>
    <button
        type="button"
        @click="$emit('click', $event)"
        :title="title"
        :class="['p-2 rounded-full transition-colors', tone.text, tone.hoverText, tone.hoverBg]"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <!-- submit / paper-airplane -->
            <path v-if="kind === 'submit'" stroke-linecap="round" stroke-linejoin="round"
                  d="M3.4 20.6 21 12 3.4 3.4l3 7.6h9l-9 0z" />
            <!-- edit / pencil -->
            <path v-else-if="kind === 'edit'" stroke-linecap="round" stroke-linejoin="round"
                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            <!-- delete / trash -->
            <path v-else-if="kind === 'delete'" stroke-linecap="round" stroke-linejoin="round"
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            <!-- approve / check-circle -->
            <path v-else-if="kind === 'approve'" stroke-linecap="round" stroke-linejoin="round"
                  d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            <!-- reject / x-circle -->
            <path v-else-if="kind === 'reject'" stroke-linecap="round" stroke-linejoin="round"
                  d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            <!-- mark paid / banknote -->
            <path v-else-if="kind === 'paid'" stroke-linecap="round" stroke-linejoin="round"
                  d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
            <!-- view / external-link arrow -->
            <path v-else-if="kind === 'view'" stroke-linecap="round" stroke-linejoin="round"
                  d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
        </svg>
    </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    kind: { type: String, required: true }, // submit | edit | delete | approve | reject | paid
    title: { type: String, default: '' },
})
defineEmits(['click'])

const TONES = {
    submit:  { text: 'text-green-600',  hoverText: 'hover:text-green-900',  hoverBg: 'hover:bg-green-50' },
    edit:    { text: 'text-blue-600',   hoverText: 'hover:text-blue-900',   hoverBg: 'hover:bg-blue-50' },
    delete:  { text: 'text-red-600',    hoverText: 'hover:text-red-900',    hoverBg: 'hover:bg-red-50' },
    approve: { text: 'text-green-600',  hoverText: 'hover:text-green-900',  hoverBg: 'hover:bg-green-50' },
    reject:  { text: 'text-red-600',    hoverText: 'hover:text-red-900',    hoverBg: 'hover:bg-red-50' },
    paid:    { text: 'text-indigo-600', hoverText: 'hover:text-indigo-900', hoverBg: 'hover:bg-indigo-50' },
    view:    { text: 'text-purple-600', hoverText: 'hover:text-purple-900', hoverBg: 'hover:bg-purple-50' },
}
const tone = computed(() => TONES[props.kind] || TONES.edit)
</script>
