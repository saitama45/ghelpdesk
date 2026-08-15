<template>
    <button type="button" :title="title" :disabled="disabled" class="rounded-full p-2 transition-colors disabled:cursor-not-allowed disabled:opacity-40" :class="toneClass">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="path" />
        </svg>
    </button>
</template>

<script setup>
import { computed } from 'vue'

/**
 * Round icon button for row actions across the UAT tabs. Kinds map to the
 * project's standard tone palette: view grey, edit blue, delete/reject red,
 * approve/pass green, escalate indigo, retest amber.
 */
const props = defineProps({
    kind: { type: String, required: true },
    title: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
})

const ICONS = {
    view: 'M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
    edit: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
    delete: 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
    run: 'M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    ticket: 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z',
    link: 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1',
    unlink: 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101M18 6L6 18',
    copy: 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z',
    evidence: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
    add: 'M12 6v6m0 0v6m0-6h6m-6 0H6',
}

const TONES = {
    view: 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700',
    edit: 'text-blue-600 hover:bg-blue-50 hover:text-blue-900 dark:hover:bg-blue-900/30',
    delete: 'text-red-600 hover:bg-red-50 hover:text-red-900 dark:hover:bg-red-900/30',
    run: 'text-emerald-600 hover:bg-emerald-50 hover:text-emerald-900 dark:hover:bg-emerald-900/30',
    ticket: 'text-indigo-600 hover:bg-indigo-50 hover:text-indigo-900 dark:hover:bg-indigo-900/30',
    link: 'text-indigo-600 hover:bg-indigo-50 hover:text-indigo-900 dark:hover:bg-indigo-900/30',
    unlink: 'text-amber-600 hover:bg-amber-50 hover:text-amber-900 dark:hover:bg-amber-900/30',
    copy: 'text-amber-600 hover:bg-amber-50 hover:text-amber-900 dark:hover:bg-amber-900/30',
    evidence: 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700',
    add: 'text-emerald-600 hover:bg-emerald-50 hover:text-emerald-900 dark:hover:bg-emerald-900/30',
}

const path = computed(() => ICONS[props.kind] || ICONS.view)
const toneClass = computed(() => TONES[props.kind] || TONES.view)
</script>
