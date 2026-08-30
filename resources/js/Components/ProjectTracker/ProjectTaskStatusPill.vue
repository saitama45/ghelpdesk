<script setup>
import { computed } from 'vue';

/**
 * One pill for a task's display status.
 *
 * Three vocabularies reach this component for what are only three states, so all
 * three are mapped rather than left to fall through to the neutral default:
 *
 *   - `project_tasks.status` as stored — Done / Ongoing / Pending. These are what
 *     the weekly timeline and the monitoring tab actually pass, and they were the
 *     ones missing: every row rendered grey regardless of its real state.
 *   - the progress-derived labels — Completed / In Progress / Not Started.
 *   - `project_tasks.manual_status` from reference_options — Blocked, For
 *     Approval, and anything added later; unknown values stay neutral.
 *
 * The colours are the Gantt chart's (emerald / sky / amber), so one task reads the
 * same on whichever tab it is looked at.
 */
const props = defineProps({
    status: { type: String, default: '' },
});

const DONE = 'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800';
const ONGOING = 'bg-sky-100 text-sky-700 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-300 dark:ring-sky-800';
const PENDING = 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800';
const NEUTRAL = 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600';

const styles = {
    'done':         DONE,
    'ongoing':      ONGOING,
    'pending':      PENDING,

    'completed':    DONE,
    'in progress':  ONGOING,
    'not started':  NEUTRAL,

    'blocked':      'bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-900/30 dark:text-rose-300 dark:ring-rose-800',
    'for approval': 'bg-violet-100 text-violet-700 ring-violet-200 dark:bg-violet-900/30 dark:text-violet-300 dark:ring-violet-800',
};

const pillClass = computed(
    () => styles[(props.status || '').trim().toLowerCase()] || NEUTRAL
);
</script>

<template>
    <span :class="['inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ring-1', pillClass]">
        {{ status || 'Not Started' }}
    </span>
</template>
