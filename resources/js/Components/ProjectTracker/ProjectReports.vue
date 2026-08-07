<script setup>
import { ArrowRightIcon } from '@heroicons/vue/24/outline';

/**
 * Department accountability for THIS project. Every row is clickable and jumps
 * to the Gantt filtered to that department, scrolled to its first activity.
 */
defineProps({
    reports: { type: Object, default: () => ({ departments: [], totals: {}, unattributed: 0 }) },
});

const emit = defineEmits(['open-department']);

const completionTone = (value) => {
    if (value >= 75) return 'text-emerald-600 dark:text-emerald-400';
    if (value >= 40) return 'text-amber-600 dark:text-amber-400';
    return 'text-rose-600 dark:text-rose-400';
};
</script>

<template>
    <div class="space-y-5">
        <div>
            <h3 class="text-lg font-black tracking-tight text-gray-900 dark:text-gray-100">Reports</h3>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Department accountability for this project. Click a department to open its activities on the Gantt chart.
            </p>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h4 class="text-base font-black text-gray-900 dark:text-gray-100">Department Accountability</h4>
            </div>

            <div v-if="reports.departments.length" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">Department</th>
                            <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-wider text-gray-500">Assignments</th>
                            <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-wider text-gray-500">Completed</th>
                            <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-wider text-gray-500">Overdue</th>
                            <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-wider text-gray-500">Completion</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr
                            v-for="dept in reports.departments"
                            :key="dept.name"
                            @click="emit('open-department', dept.name)"
                            class="group cursor-pointer transition hover:bg-gray-50 dark:hover:bg-gray-700/40"
                            :title="`Open ${dept.name} on the Gantt chart`"
                        >
                            <td class="px-5 py-3 font-semibold text-blue-600 group-hover:underline dark:text-blue-400">
                                {{ dept.name }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ dept.assignments }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ dept.completed }}</td>
                            <td :class="['px-5 py-3 text-right font-semibold tabular-nums', dept.overdue > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-400']">
                                {{ dept.overdue }}
                            </td>
                            <td :class="['px-5 py-3 text-right font-black tabular-nums', completionTone(dept.completion)]">
                                {{ dept.completion }}%
                            </td>
                            <td class="px-5 py-3 text-right">
                                <ArrowRightIcon class="ml-auto h-4 w-4 text-gray-300 opacity-0 transition group-hover:opacity-100" />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40">
                            <td class="px-5 py-3 text-xs font-black uppercase tracking-wider text-gray-500">All activities</td>
                            <td class="px-5 py-3 text-right font-bold tabular-nums text-gray-700 dark:text-gray-300">{{ reports.totals.assignments }}</td>
                            <td class="px-5 py-3 text-right font-bold tabular-nums text-gray-700 dark:text-gray-300">{{ reports.totals.completed }}</td>
                            <td class="px-5 py-3 text-right font-bold tabular-nums text-gray-700 dark:text-gray-300">{{ reports.totals.overdue }}</td>
                            <td class="px-5 py-3 text-right font-black tabular-nums text-gray-700 dark:text-gray-300">{{ reports.totals.completion }}%</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p v-else class="p-8 text-center text-sm text-gray-400">
                No activity on this project has a department yet.
            </p>
        </div>

        <p v-if="reports.unattributed > 0" class="rounded-lg border border-dashed border-amber-300 bg-amber-50/50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            <span class="font-bold">{{ reports.unattributed }}</span>
            {{ reports.unattributed === 1 ? 'activity has' : 'activities have' }}
            no department and no assignee, so they are not counted above. Set a department on the activity
            template, or assign the rows to a user.
        </p>
    </div>
</template>
