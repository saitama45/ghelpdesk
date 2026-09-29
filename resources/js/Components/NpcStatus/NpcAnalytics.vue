<template>
    <section ref="rootRef" class="relative rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3 p-4" :class="{ 'border-b border-gray-200 dark:border-gray-700': !collapsed }">
            <div class="min-w-0">
                <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ year }} NPC compliance overview</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-300">
                    How far the {{ total }} {{ total === 1 ? 'entity' : 'entities' }} in this list have come. Hidden entities are left out; the status tabs and search below do not change these figures.
                </p>
            </div>
            <button
                type="button"
                @click="toggleCollapsed"
                :aria-expanded="!collapsed"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-bold text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
            >
                {{ collapsed ? 'Show analytics' : 'Hide analytics' }}
            </button>
        </div>

        <div v-if="!collapsed" class="space-y-4 p-4">
            <!-- KPI row: one hero figure, then four entity shares on the same denominator -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-lg border border-blue-200 bg-blue-50/60 p-4 sm:col-span-2 xl:col-span-1 dark:border-blue-900 dark:bg-blue-950/30">
                    <div class="text-xs font-bold text-gray-600 dark:text-gray-300">Overall completion</div>
                    <div class="mt-1 text-5xl font-semibold leading-none text-gray-900 dark:text-gray-50">{{ analytics.overall_progress }}%</div>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded bg-blue-100 dark:bg-blue-900/50">
                        <div class="h-2 rounded-r bg-[#2563eb] dark:bg-[#3b82f6]" :style="{ width: `${analytics.overall_progress}%` }"></div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Average workflow progress, counting entities with no record as 0%</div>
                </div>

                <div
                    v-for="kpi in kpis"
                    :key="kpi.key"
                    class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                >
                    <div class="text-xs font-bold text-gray-600 dark:text-gray-300">{{ kpi.label }}</div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-3xl font-semibold text-gray-900 dark:text-gray-50">{{ pct(kpi.value, total) }}%</span>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ kpi.value }} of {{ total }}</span>
                    </div>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded bg-blue-100 dark:bg-blue-900/50">
                        <div class="h-2 rounded-r bg-[#2563eb] dark:bg-[#3b82f6]" :style="{ width: `${pct(kpi.value, total)}%` }"></div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ kpi.hint }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <!-- Renewal status: part-to-whole stacked bar + legend that doubles as the table view -->
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Renewal status</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Validity against today, per entity</p>

                    <div class="mt-4 flex h-6 w-full gap-[2px]" role="img" :aria-label="renewalAriaLabel">
                        <div
                            v-for="(segment, index) in renewalSegments"
                            :key="segment.status"
                            tabindex="0"
                            class="h-full outline-none focus-visible:ring-2 focus-visible:ring-gray-900 dark:focus-visible:ring-white"
                            :class="[
                                statusStyle[segment.status].fill,
                                index === 0 ? 'rounded-l' : '',
                                index === renewalSegments.length - 1 ? 'rounded-r' : '',
                            ]"
                            :style="{ width: `${(segment.count / total) * 100}%` }"
                            @mouseenter="showTip($event, statusLabel(segment.status), [`${segment.count} of ${total} entities (${pct(segment.count, total)}%)`], segment.entities)"
                            @mousemove="moveTip"
                            @mouseleave="hideTip"
                            @focus="showTip($event, statusLabel(segment.status), [`${segment.count} of ${total} entities (${pct(segment.count, total)}%)`], segment.entities)"
                            @blur="hideTip"
                        ></div>
                        <div v-if="!renewalSegments.length" class="h-full w-full rounded bg-gray-100 dark:bg-gray-700"></div>
                    </div>

                    <ul class="mt-4 space-y-1.5">
                        <li v-for="row in analytics.renewal" :key="row.status" class="flex items-center gap-2 text-sm">
                            <span class="h-3 w-3 flex-none rounded-sm" :class="statusStyle[row.status].fill" aria-hidden="true"></span>
                            <span class="flex-1 text-gray-700 dark:text-gray-200">{{ statusLabel(row.status) }}</span>
                            <span class="tabular-nums font-semibold text-gray-900 dark:text-gray-100">{{ row.count }}</span>
                            <span class="w-10 text-right tabular-nums text-xs text-gray-500 dark:text-gray-400">{{ pct(row.count, total) }}%</span>
                        </li>
                    </ul>
                </div>

                <!-- Workflow: share of entities through each step -->
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Workflow progress by step</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Entities that have completed each step</p>

                    <ul class="mt-4 space-y-3">
                        <li
                            v-for="(step, index) in analytics.steps"
                            :key="step.key"
                            tabindex="0"
                            class="rounded outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                            @mouseenter="showTip($event, `Step ${index + 1}: ${step.label}`, [`${step.done} of ${total} done (${pct(step.done, total)}%)`], step.pending, 'Not done yet')"
                            @mousemove="moveTip"
                            @mouseleave="hideTip"
                            @focus="showTip($event, `Step ${index + 1}: ${step.label}`, [`${step.done} of ${total} done (${pct(step.done, total)}%)`], step.pending, 'Not done yet')"
                            @blur="hideTip"
                        >
                            <div class="flex items-baseline justify-between gap-2 text-xs">
                                <span class="truncate font-medium text-gray-700 dark:text-gray-200">{{ index + 1 }}. {{ step.label }}</span>
                                <span class="flex-none tabular-nums text-gray-500 dark:text-gray-400"><span class="font-semibold text-gray-900 dark:text-gray-100">{{ pct(step.done, total) }}%</span> · {{ step.done }}/{{ total }}</span>
                            </div>
                            <div class="mt-1 h-3 w-full overflow-hidden rounded bg-blue-100 dark:bg-blue-900/50">
                                <div class="h-3 rounded-r bg-[#2563eb] dark:bg-[#3b82f6]" :style="{ width: `${pct(step.done, total)}%` }"></div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Store rollout: share of assigned stores through each seal stage -->
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Store seal rollout</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ analytics.stores.assigned }} assigned {{ analytics.stores.assigned === 1 ? 'store' : 'stores' }}; a store counts once every seal it receives is through the stage
                    </p>

                    <ul v-if="analytics.stores.assigned" class="mt-4 space-y-3">
                        <li
                            v-for="stage in storeStages"
                            :key="stage.key"
                            tabindex="0"
                            class="rounded outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                            @mouseenter="showTip($event, stage.label, [`${stage.value} of ${analytics.stores.assigned} stores (${pct(stage.value, analytics.stores.assigned)}%)`, `${analytics.stores.assigned - stage.value} still to go`])"
                            @mousemove="moveTip"
                            @mouseleave="hideTip"
                            @focus="showTip($event, stage.label, [`${stage.value} of ${analytics.stores.assigned} stores (${pct(stage.value, analytics.stores.assigned)}%)`, `${analytics.stores.assigned - stage.value} still to go`])"
                            @blur="hideTip"
                        >
                            <div class="flex items-baseline justify-between gap-2 text-xs">
                                <span class="truncate font-medium text-gray-700 dark:text-gray-200">{{ stage.label }}</span>
                                <span class="flex-none tabular-nums text-gray-500 dark:text-gray-400"><span class="font-semibold text-gray-900 dark:text-gray-100">{{ pct(stage.value, analytics.stores.assigned) }}%</span> · {{ stage.value }}/{{ analytics.stores.assigned }}</span>
                            </div>
                            <div class="mt-1 h-3 w-full overflow-hidden rounded bg-blue-100 dark:bg-blue-900/50">
                                <div class="h-3 rounded-r bg-[#2563eb] dark:bg-[#3b82f6]" :style="{ width: `${pct(stage.value, analytics.stores.assigned)}%` }"></div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">No stores are assigned to a {{ year }} record yet.</p>
                </div>
            </div>
        </div>

        <!-- Shared hover/focus tooltip -->
        <div
            v-if="tip.show"
            class="pointer-events-none absolute z-30 max-w-xs rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs shadow-lg dark:border-gray-600 dark:bg-gray-900"
            :style="{ left: `${tip.x}px`, top: `${tip.y}px` }"
            role="tooltip"
        >
            <div class="font-bold text-gray-900 dark:text-gray-100">{{ tip.title }}</div>
            <div v-for="line in tip.lines" :key="line" class="text-gray-600 dark:text-gray-300">{{ line }}</div>
            <template v-if="tip.names.length">
                <div class="mt-1.5 font-semibold text-gray-700 dark:text-gray-200">{{ tip.namesLabel }}</div>
                <div v-for="name in tip.names.slice(0, 8)" :key="name" class="truncate text-gray-600 dark:text-gray-300">{{ name }}</div>
                <div v-if="tip.names.length > 8" class="text-gray-500 dark:text-gray-400">+{{ tip.names.length - 8 }} more</div>
            </template>
        </div>
    </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'

// Monitoring dashboard for /npc-statuses (payload: NpcStatusController::analyticsPayload).
// Colors: meters are one blue on a lighter blue track; renewal statuses reuse
// the list's badge hues, re-stepped and validated for both modes (dataviz skill).
const props = defineProps({
    analytics: { type: Object, required: true },
    year: { type: Number, required: true },
})

const STORAGE_KEY = 'npc-analytics-collapsed'
const readCollapsed = () => {
    try { return window.localStorage.getItem(STORAGE_KEY) === '1' } catch { return false }
}
const collapsed = ref(readCollapsed())
const toggleCollapsed = () => {
    collapsed.value = !collapsed.value
    try { window.localStorage.setItem(STORAGE_KEY, collapsed.value ? '1' : '0') } catch { /* private mode */ }
}

const total = computed(() => props.analytics.total_entities || 0)
const pct = (value, of) => (of > 0 ? Math.round((value / of) * 100) : 0)

const kpis = computed(() => [
    { key: 'with_record', label: `${props.year} record started`, value: props.analytics.kpis.with_record, hint: 'Entities with a record for this year' },
    { key: 'approved', label: 'NPC approved', value: props.analytics.kpis.approved, hint: 'Approval status set to Approved' },
    { key: 'seals_released', label: 'Seals released', value: props.analytics.kpis.seals_released, hint: 'All seals uploaded for stores to download' },
    { key: 'workflow_complete', label: 'Workflow complete', value: props.analytics.kpis.workflow_complete, hint: 'All six steps checked' },
])

const statusStyle = {
    Active: { fill: 'bg-[#16a34a]' },
    'Renewal Window': { fill: 'bg-[#2563eb] dark:bg-[#3b82f6]' },
    'Critical Renewal': { fill: 'bg-[#d97706]' },
    Overdue: { fill: 'bg-[#e11d48]' },
    'No Record': { fill: 'bg-gray-300 dark:bg-gray-600' },
}
const statusLabel = (status) => ({
    'Critical Renewal': 'Critical / due today',
    'No Record': `No ${props.year} record`,
}[status] || status)

const renewalSegments = computed(() => (props.analytics.renewal || []).filter((row) => row.count > 0))
const renewalAriaLabel = computed(() => 'Renewal status: ' + (props.analytics.renewal || [])
    .map((row) => `${statusLabel(row.status)} ${row.count}`)
    .join(', '))

const storeStages = computed(() => [
    { key: 'downloaded', label: 'Downloaded all seals', value: props.analytics.stores.downloaded },
    { key: 'proof', label: 'Uploaded proof for all seals', value: props.analytics.stores.proof },
    { key: 'confirmed', label: 'Confirmed by admin', value: props.analytics.stores.confirmed },
])

// ── Tooltip ──────────────────────────────────────────────────────────────
const rootRef = ref(null)
const tip = reactive({ show: false, x: 0, y: 0, title: '', lines: [], names: [], namesLabel: '' })

const placeTip = (event) => {
    const root = rootRef.value?.getBoundingClientRect()
    if (!root) return
    // Keyboard focus has no pointer; anchor to the element instead.
    const anchor = event.clientX === undefined ? event.target.getBoundingClientRect() : null
    const x = (anchor ? anchor.left + anchor.width / 2 : event.clientX) - root.left
    const y = (anchor ? anchor.bottom : event.clientY) - root.top
    tip.x = Math.max(8, Math.min(x + 12, root.width - 280))
    tip.y = y + 14
}

const showTip = (event, title, lines, names = [], namesLabel = 'Entities') => {
    tip.title = title
    tip.lines = lines
    tip.names = names || []
    tip.namesLabel = namesLabel
    tip.show = true
    placeTip(event)
}
const moveTip = (event) => { if (tip.show) placeTip(event) }
const hideTip = () => { tip.show = false }
</script>
