<template>
    <div class="space-y-5">
        <!-- Headline tallies -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button v-for="tile in tiles" :key="tile.label" @click="$emit('go', tile.go)"
                    class="rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400">{{ tile.label }}</div>
                <div class="mt-1 text-2xl font-bold" :class="tile.tone">{{ tile.value }}</div>
                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ tile.hint }}</div>
            </button>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Per-department scoreboard -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Progress by department</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    A reviewer's answer is the department's answer; a tester's stands in until then.
                </p>

                <p v-if="!participantProgress.length" class="mt-4 text-sm italic text-gray-400">
                    No testers yet.
                </p>

                <div v-else class="mt-4 space-y-3">
                    <div v-for="row in participantProgress" :key="row.key">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ row.label }}</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ row.answered }} / {{ row.total }}</span>
                        </div>
                        <div class="mt-1 flex h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="bg-emerald-500" :style="{ width: bar(row.passed, row.total) }"></div>
                            <div class="bg-rose-500" :style="{ width: bar(row.failed, row.total) }"></div>
                            <div class="bg-amber-500" :style="{ width: bar(row.blocked, row.total) }"></div>
                            <div class="bg-blue-500" :style="{ width: bar(row.ongoing, row.total) }"></div>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                            <span v-for="m in row.members" :key="m.id">
                                {{ m.name }} <span class="opacity-70">({{ m.role }})</span> — {{ m.answered }}/{{ m.total }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- What is standing in the way -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">What is standing in the way</h3>

                <div v-if="nothingBlocking" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                    Nothing. Every gated case has a verdict and no severe finding is open.
                </div>

                <div v-else class="mt-4 space-y-4">
                    <div v-if="outstanding.length">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                            {{ outstanding.length }} case(s) not passing
                        </h4>
                        <ul class="mt-2 space-y-1">
                            <li v-for="c in outstanding.slice(0, 6)" :key="c.case_key"
                                class="flex items-center gap-2 text-sm">
                                <span class="font-mono text-xs text-gray-400">{{ c.case_key }}</span>
                                <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200">{{ c.title }}</span>
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold" :class="verdict(c.verdict).chip">
                                    {{ verdict(c.verdict).short }}
                                </span>
                            </li>
                        </ul>
                        <button v-if="outstanding.length > 6" @click="$emit('go', 'matrix')"
                                class="mt-2 text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                            and {{ outstanding.length - 6 }} more →
                        </button>
                    </div>

                    <div v-if="(readiness.blocking_findings || []).length">
                        <h4 class="text-sm font-semibold text-rose-700 dark:text-rose-300">
                            {{ readiness.blocking_findings.length }} finding(s) blocking sign-off
                        </h4>
                        <ul class="mt-2 space-y-1">
                            <li v-for="f in readiness.blocking_findings" :key="f.id" class="flex items-center gap-2 text-sm">
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="SEVERITY_CHIPS[f.severity]">
                                    {{ f.severity }}
                                </span>
                                <span class="font-mono text-xs text-gray-400">{{ f.reference }}</span>
                                <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200">{{ f.title }}</span>
                            </li>
                        </ul>
                        <button @click="$emit('go', 'findings')"
                                class="mt-2 text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                            Open the defect register →
                        </button>
                    </div>
                </div>

                <!-- Waived items stay on the page: accepted is not the same as fixed -->
                <div v-if="(readiness.waived_findings || []).length"
                     class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                    <h4 class="text-sm font-bold text-amber-800 dark:text-amber-200">
                        {{ readiness.waived_findings.length }} accepted under waiver
                    </h4>
                    <ul class="mt-1 space-y-0.5 text-xs text-amber-900 dark:text-amber-100">
                        <li v-for="f in readiness.waived_findings" :key="f.id">
                            <span class="font-mono">{{ f.reference }}</span> {{ f.title }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Section breakdown -->
        <div v-if="sections.length" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">By section</h3>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="section in sections" :key="section.id"
                     class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <span class="truncate font-semibold text-gray-800 dark:text-gray-100">{{ section.name }}</span>
                        <span v-if="!section.is_critical" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                            non-critical
                        </span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full bg-emerald-500" :style="{ width: pct(progress(section.id).rate) }"></div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ progress(section.id).passed }} of {{ progress(section.id).total }} passed
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { verdict, SEVERITY_CHIPS, pct } from '../qatVerdict'

const props = defineProps({
    cycle: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
    statistics: { type: Object, default: () => ({}) },
    participantProgress: { type: Array, default: () => [] },
    sectionProgress: { type: Object, default: () => ({}) },
    readiness: { type: Object, default: () => ({}) },
    findings: { type: Array, default: () => [] },
})

defineEmits(['go'])

const tiles = computed(() => [
    {
        label: 'Test cases', value: props.statistics.total_cases || 0,
        hint: `${props.statistics.executed || 0} executed`, tone: 'text-gray-900 dark:text-gray-100', go: 'matrix',
    },
    {
        label: 'Pass rate', value: pct(props.statistics.pass_rate),
        hint: `${props.statistics.passed || 0} passed, ${props.statistics.failed || 0} failed`,
        tone: 'text-emerald-600 dark:text-emerald-400', go: 'matrix',
    },
    {
        label: 'Open findings',
        value: props.findings.filter(f => ['open', 'in_progress', 'for_retest'].includes(f.status)).length,
        hint: `${(props.readiness.blocking_findings || []).length} blocking sign-off`,
        tone: 'text-rose-600 dark:text-rose-400', go: 'findings',
    },
    {
        label: 'Critical outstanding', value: props.statistics.critical_outstanding || 0,
        hint: `of ${props.statistics.critical_total || 0} critical cases`,
        tone: 'text-amber-600 dark:text-amber-400', go: 'matrix',
    },
])

/** Everything not yet passing — unanswered and failing together, for the summary. */
const outstanding = computed(() => [
    ...(props.readiness.unanswered_cases || []),
    ...(props.readiness.failing_cases || []),
])

const nothingBlocking = computed(() =>
    !outstanding.value.length && !(props.readiness.blocking_findings || []).length
)

const bar = (value, total) => (total > 0 ? `${((value / total) * 100).toFixed(2)}%` : '0%')

const progress = (sectionId) =>
    props.sectionProgress?.[sectionId] || { total: 0, passed: 0, outstanding: 0, rate: 0 }
</script>
