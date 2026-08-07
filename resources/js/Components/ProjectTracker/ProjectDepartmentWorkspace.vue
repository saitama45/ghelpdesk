<script setup>
import ProjectStatCards from './ProjectStatCards.vue';
import ProjectTaskStatusPill from './ProjectTaskStatusPill.vue';

/**
 * "What this project owes my department" — the workspace strip at the top of the
 * project Overview tab. Scoped to the viewer's department via the "I belong to"
 * axis, so switching department in the strip above re-scopes it on the next load.
 */
defineProps({
    workspace: { type: Object, default: () => ({ cards: [], rows: [], department: null, has_department: false }) },
});
</script>

<template>
    <div class="space-y-4">
        <div>
            <h3 class="text-lg font-black tracking-tight text-gray-900 dark:text-gray-100">
                Collaboration Workspace
            </h3>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                <template v-if="workspace.has_department">
                    Activities this project owes <span class="font-semibold">{{ workspace.department }}</span>.
                    Change "I belong to" in the strip above to switch.
                </template>
                <template v-else>
                    You have no department set, so there is nothing to scope this workspace to.
                </template>
            </p>
        </div>

        <ProjectStatCards :cards="workspace.cards" />

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div v-if="workspace.rows.length" class="max-h-[28rem] overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-gray-800">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">WBS</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">Activity</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">Milestone</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">Forecast finish</th>
                            <th class="px-4 py-3 text-right text-[10px] font-black uppercase tracking-wider text-gray-500">%</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="row in workspace.rows" :key="row.id">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ row.wbs }}</td>
                            <td class="px-4 py-3 text-gray-800 dark:text-gray-200">
                                <span v-if="row.is_sub_task" class="mr-1 text-[10px] font-black uppercase text-gray-400">Sub</span>
                                {{ row.name }}
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ row.milestone || '—' }}</td>
                            <td :class="['whitespace-nowrap px-4 py-3 text-xs', row.is_overdue ? 'font-bold text-rose-600 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400']">
                                {{ row.finish || 'No date' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-gray-700 dark:text-gray-300">
                                {{ row.progress }}%
                            </td>
                            <td class="px-4 py-3">
                                <ProjectTaskStatusPill :status="row.status" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="p-8 text-center text-sm text-gray-400">
                <template v-if="workspace.has_department">
                    No activity on this project is attributed to {{ workspace.department }}.
                </template>
                <template v-else>
                    Set a department on your profile to use this workspace.
                </template>
            </p>
        </div>
    </div>
</template>
