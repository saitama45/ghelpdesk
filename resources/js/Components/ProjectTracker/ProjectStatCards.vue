<script setup>
/**
 * The four-across metric strip used by the Workspace and Monitoring panels.
 * Values arrive pre-formatted from ProjectWorkspaceService — this only styles them.
 */
defineProps({
    cards: { type: Array, default: () => [] },
});

const toneClass = {
    slate:   'text-gray-900 dark:text-gray-100',
    amber:   'text-amber-600 dark:text-amber-400',
    rose:    'text-rose-600 dark:text-rose-400',
    indigo:  'text-indigo-600 dark:text-indigo-400',
    emerald: 'text-emerald-600 dark:text-emerald-400',
};
</script>

<template>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div
            v-for="card in cards"
            :key="card.key"
            class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800"
        >
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ card.label }}</p>
            <p :class="['mt-1 text-4xl font-black tabular-nums leading-none', toneClass[card.tone] || toneClass.slate]">
                {{ card.value }}
            </p>
        </div>
    </div>
</template>
