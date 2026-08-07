<script setup>
import ProjectStatCards from './ProjectStatCards.vue';
import ProjectTaskStatusPill from './ProjectTaskStatusPill.vue';

/**
 * Early-intervention warnings for THIS project: overdue rows, blocked rows,
 * dependency contradictions and outstanding compliance gates.
 *
 * "Dependencies to validate" lists only genuine contradictions — a requisite
 * that finishes after its successor starts, a row running ahead of an unfinished
 * requisite, or a requisite sitting in a later milestone. Rows that simply
 * follow the previous row are normal and are not flagged.
 */
defineProps({
    monitoring: { type: Object, default: () => ({ cards: [], dependencies: [], overdue: [], permits: [] }) },
});

const emit = defineEmits(['open-department']);
</script>

<template>
    <div class="space-y-5">
        <div>
            <h3 class="text-lg font-black tracking-tight text-gray-900 dark:text-gray-100">Monitoring</h3>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Early-intervention warnings for this project: overdue activities, blocked work,
                dependency validation and missing permits.
            </p>
        </div>

        <ProjectStatCards :cards="monitoring.cards" />

        <!-- Dependency contradictions -->
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h4 class="text-base font-black text-gray-900 dark:text-gray-100">Dependency validation required</h4>

            <div v-if="monitoring.dependencies.length" class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                <button
                    v-for="item in monitoring.dependencies"
                    :key="item.id"
                    type="button"
                    @click="emit('open-department', item.department)"
                    class="flex w-full items-start justify-between gap-4 py-3 text-left transition hover:opacity-70"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">
                            <span class="font-mono text-xs text-gray-500">{{ item.wbs }}</span>
                            {{ item.name }}
                            <span v-if="item.department" class="text-blue-600 dark:text-blue-400"> · {{ item.department }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ item.reason }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-amber-700 ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800">
                        Validation Required
                    </span>
                </button>
            </div>

            <p v-else class="mt-3 rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600">
                No dependency contradictions. Every row with a named requisite is scheduled consistently with it.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Overdue -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h4 class="text-base font-black text-gray-900 dark:text-gray-100">Overdue activities</h4>

                <div v-if="monitoring.overdue.length" class="mt-3 max-h-80 divide-y divide-gray-100 overflow-auto dark:divide-gray-700">
                    <div v-for="row in monitoring.overdue" :key="row.id" class="flex items-start justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-gray-800 dark:text-gray-200">
                                <span class="font-mono text-xs text-gray-500">{{ row.wbs }}</span> {{ row.name }}
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                Due {{ row.finish }}<span v-if="row.department"> · {{ row.department }}</span>
                            </p>
                        </div>
                        <span class="shrink-0 text-sm font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ row.progress }}%</span>
                    </div>
                </div>

                <p v-else class="mt-3 text-sm text-gray-400">Nothing is overdue.</p>
            </div>

            <!-- Compliance gates -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h4 class="text-base font-black text-gray-900 dark:text-gray-100">Outstanding permits &amp; clearances</h4>
                <p class="mt-0.5 text-xs text-gray-400">
                    Activities whose name mentions a permit, clearance, licence or occupancy and are not yet complete.
                </p>

                <div v-if="monitoring.permits.length" class="mt-3 max-h-72 divide-y divide-gray-100 overflow-auto dark:divide-gray-700">
                    <div v-for="row in monitoring.permits" :key="row.id" class="flex items-start justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-gray-800 dark:text-gray-200">
                                <span class="font-mono text-xs text-gray-500">{{ row.wbs }}</span> {{ row.name }}
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ row.finish ? 'Due ' + row.finish : 'No date' }}
                            </p>
                        </div>
                        <ProjectTaskStatusPill :status="row.status" />
                    </div>
                </div>

                <p v-else class="mt-3 text-sm text-gray-400">No outstanding permit activities.</p>
            </div>
        </div>
    </div>
</template>
