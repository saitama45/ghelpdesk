<script setup>
import { computed } from 'vue';

/**
 * One pill for a task's display status. Covers both the progress-derived states
 * (Completed / In Progress / Not Started) and the manually-set ones stored in
 * project_tasks.manual_status (Blocked, For Approval, and anything else added to
 * reference_options later — unknown values fall back to neutral styling).
 */
const props = defineProps({
    status: { type: String, default: '' },
});

const styles = {
    'completed':    'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800',
    'in progress':  'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:ring-blue-800',
    'not started':  'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600',
    'blocked':      'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-900/30 dark:text-rose-300 dark:ring-rose-800',
    'for approval': 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800',
};

const pillClass = computed(
    () => styles[(props.status || '').toLowerCase()] || styles['not started']
);
</script>

<template>
    <span :class="['inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ring-1', pillClass]">
        {{ status || 'Not Started' }}
    </span>
</template>
