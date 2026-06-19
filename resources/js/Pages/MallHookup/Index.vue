<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import Modal from '@/Components/Modal.vue'
import { useToast } from '@/Composables/useToast'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    tab: String,
    filters: Object,
    statuses: Array,
    reasons: Array,
    hookupStatuses: Array,
    stores: Array,
    users: Array,
    years: Array,
    summary: Object,
    weeklyReport: Array,
    locations: Array,
    dailyBoard: Array,
    matrix: Object,
})

const { showSuccess, showError } = useToast()
const { hasPermission } = usePermission()
const can = (perm) => hasPermission(perm)

const activeTab = ref(props.tab || 'dashboard')
const tabs = [
    { key: 'dashboard', label: 'Dashboard' },
    { key: 'daily', label: 'Daily Monitoring' },
    { key: 'locations', label: 'Locations' },
    { key: 'matrix', label: 'Compliance Matrix' },
]

const reload = (extra = {}, only = null) => {
    router.get('/mall-hookups', { tab: activeTab.value, ...props.filters, ...extra }, {
        preserveState: true,
        preserveScroll: true,
        ...(only ? { only } : {}),
    })
}

// Tabs switch client-side only — all tab data is already loaded, so unsaved
// edits (e.g. the daily board) survive navigation between tabs.
const switchTab = (key) => {
    activeTab.value = key
    const url = new URL(window.location.href)
    url.searchParams.set('tab', key)
    window.history.replaceState({}, '', url)
}

/* ---------- helpers ---------- */
const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusLabel = (s) => ({ yes: 'Yes', no: 'No', na: 'N/A', for_accreditation: 'For Accr.' }[s] || '—')
const statusCellClass = (s) => ({
    yes: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    no: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    na: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300',
    for_accreditation: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
}[s] || '')

const reasonOptions = computed(() => (props.reasons || []).map(r => ({ label: r, value: r })))
const hookupStatusOptions = computed(() => (props.hookupStatuses || []).map(s => ({ label: s, value: s })))
const yearOptions = computed(() => (props.years || []).map(y => ({ label: String(y), value: y })))
const upsOptions = [{ label: 'Yes', value: 1 }, { label: 'No', value: 0 }]

/* =========================================================
   DASHBOARD — weekly report (table + combo chart)
   ========================================================= */
const onYearChange = (y) => reload({ year: y })

// Top "No" reasons within the currently loaded matrix range.
const topReasons = computed(() => {
    const tally = {}
    for (const row of props.matrix?.rows || []) {
        for (const d in row.cells) {
            const c = row.cells[d]
            if (c.status === 'no' && c.remark) tally[c.remark] = (tally[c.remark] || 0) + 1
        }
    }
    return Object.entries(tally).sort((a, b) => b[1] - a[1]).slice(0, 6)
})
const topReasonMax = computed(() => Math.max(1, ...topReasons.value.map(r => r[1])))

// SVG combo chart geometry
const chart = computed(() => {
    const data = props.weeklyReport || []
    const barW = 34, gap = 14, padL = 8, padR = 8, h = 240, top = 14, bottom = 26
    const innerH = h - top - bottom
    const maxTotal = Math.max(1, ...data.map(d => d.avg_total_pos))
    const width = padL + padR + data.length * (barW + gap)
    const yFor = (v) => top + innerH - (v / maxTotal) * innerH
    const yForPct = (v) => top + innerH - (v / 100) * innerH
    const bars = data.map((d, i) => {
        const x = padL + i * (barW + gap)
        const yesH = (d.yes / maxTotal) * innerH
        const noH = (d.no / maxTotal) * innerH
        return {
            x, barW, week: d.week,
            yesY: top + innerH - yesH, yesH,
            noY: top + innerH - yesH - noH, noH,
            yes: d.yes, no: d.no, pct: d.avg_sending_pct,
            cx: x + barW / 2,
            pctY: yForPct(d.avg_sending_pct),
        }
    })
    const linePts = bars.map(b => `${b.cx},${b.pctY}`).join(' ')
    return { data, width, h, bars, linePts, yFor }
})

/* =========================================================
   DAILY MONITORING
   ========================================================= */
const dailyDate = ref(props.filters?.date)
watch(dailyDate, (d) => { if (d && d !== props.filters?.date) reload({ date: d }, ['dailyBoard', 'filters']) })

// Local editable copy of the day's board.
const board = ref([])
watch(() => props.dailyBoard, (val) => {
    board.value = (val || []).map(r => reactive({ ...r }))
}, { immediate: true })

const setStatus = (row, s) => {
    row.status = row.status === s ? null : s
    if (row.status !== 'no') row.remark = null
}
const markAllYes = () => board.value.forEach(r => { r.status = 'yes'; r.remark = null })

const savingDaily = ref(false)
const saveDaily = () => {
    savingDaily.value = true
    router.post('/mall-hookups/daily', {
        date: dailyDate.value,
        entries: board.value.map(r => ({ mall_hookup_id: r.mall_hookup_id, status: r.status, remark: r.remark })),
    }, {
        preserveScroll: true,
        onFinish: () => { savingDaily.value = false },
    })
}

/* =========================================================
   LOCATIONS  (every store is listed automatically)
   ========================================================= */
const blankForm = () => ({
    id: null, developer: '', deployment_date: '', deployment_status: '',
    hookup_status: '', shouldered_facility: '', with_ups: null, costs: [],
})
const locModal = reactive({ open: false, store_code: '', area: '' })
const form = reactive(blankForm())

const openEdit = (loc) => {
    Object.assign(form, {
        id: loc.id, developer: loc.developer || '',
        deployment_date: loc.deployment_date || '', deployment_status: loc.deployment_status || '',
        hookup_status: loc.hookup_status || '', shouldered_facility: loc.shouldered_facility || '',
        with_ups: loc.with_ups === null ? null : (loc.with_ups ? 1 : 0),
        costs: (loc.costs || []).map(c => ({ year: c.year, amount: c.amount })),
    })
    locModal.store_code = loc.store_code
    locModal.area = loc.area || '—'
    locModal.open = true
}
const addCostRow = () => form.costs.push({ year: new Date().getFullYear(), amount: 0 })
const removeCostRow = (i) => form.costs.splice(i, 1)

const savingsPreview = computed(() => {
    const sorted = [...form.costs].filter(c => c.year).sort((a, b) => a.year - b.year)
    if (!sorted.length) return 0
    return Number(sorted[0].amount || 0) - Number(sorted[sorted.length - 1].amount || 0)
})

const saveLocation = () => {
    router.put(`/mall-hookups/locations/${form.id}`, { ...form }, {
        preserveScroll: true,
        onSuccess: () => { locModal.open = false },
        onError: () => showError('Please check the form fields.'),
    })
}

/* ---------- import ---------- */
const importing = ref(false)
const fileInput = ref(null)
const triggerImport = () => fileInput.value?.click()
const onFileChosen = async (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    const data = new FormData()
    data.append('file', file)
    importing.value = true
    try {
        const res = await axios.post('/mall-hookups/import', data, { headers: { 'Content-Type': 'multipart/form-data' } })
        const { created, updated, skipped } = res.data
        showSuccess(`Imported — ${created} created, ${updated} updated, ${skipped} skipped.`)
        router.reload({ only: ['summary', 'weeklyReport', 'dailyBoard', 'matrix'] })
    } catch (err) {
        const errs = err.response?.data?.errors || ['Import failed.']
        showError(errs.slice(0, 3).join(' '))
    } finally {
        importing.value = false
        if (fileInput.value) fileInput.value.value = ''
    }
}

/* =========================================================
   COMPLIANCE MATRIX
   ========================================================= */
const matrixFrom = ref(props.filters?.matrix_from)
const matrixTo = ref(props.filters?.matrix_to)
const applyMatrixRange = () => reload({ matrix_from: matrixFrom.value, matrix_to: matrixTo.value }, ['matrix', 'filters'])
const fmtDate = (d) => {
    const dt = new Date(d + 'T00:00:00')
    return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
</script>

<template>
    <AppLayout title="Mall Hookup" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6 space-y-6">
            <!-- Header + Tabs -->
            <div class="flex flex-col gap-4">
                <div>
                    <h1 class="text-2xl font-black text-gray-900 dark:text-gray-100">Mall Hookup</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">POS auto-sending compliance monitoring</p>
                </div>
                <div class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-700">
                    <button v-for="t in tabs" :key="t.key" @click="switchTab(t.key)"
                        :class="['px-4 py-2 text-sm font-bold rounded-t-lg transition-colors',
                            activeTab === t.key ? 'bg-white text-blue-600 border border-b-0 border-gray-200 dark:bg-gray-800 dark:text-blue-400 dark:border-gray-700'
                            : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200']">
                        {{ t.label }}
                    </button>
                </div>
            </div>

            <!-- ============ DASHBOARD ============ -->
            <div v-show="activeTab === 'dashboard'" class="space-y-6">
                <!-- KPI cards -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[10px] font-black uppercase tracking-wider text-blue-500">Sending %</p>
                        <p class="text-2xl font-black mt-1 text-gray-800 dark:text-gray-100">{{ summary.latest.sending_pct }}%</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400">Total POS</p>
                        <p class="text-2xl font-black mt-1 text-gray-800 dark:text-gray-100">{{ summary.latest.total }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[10px] font-black uppercase tracking-wider text-green-500">Sending</p>
                        <p class="text-2xl font-black mt-1 text-green-600 dark:text-green-400">{{ summary.latest.yes }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[10px] font-black uppercase tracking-wider text-red-500">Issues</p>
                        <p class="text-2xl font-black mt-1 text-red-600 dark:text-red-400">{{ summary.latest.no }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[10px] font-black uppercase tracking-wider text-emerald-600">Savings</p>
                        <p class="text-lg font-black mt-1 text-emerald-700 dark:text-emerald-400">{{ peso(summary.savings) }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[10px] font-black uppercase tracking-wider text-emerald-600">Decrease</p>
                        <p class="text-2xl font-black mt-1 text-emerald-700 dark:text-emerald-400">{{ summary.decrease_pct }}%</p>
                    </div>
                </div>

                <!-- Year filter -->
                <div class="flex items-center gap-3">
                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Report Year</label>
                    <div class="w-40">
                        <Autocomplete :model-value="filters.year" :options="yearOptions" @update:model-value="onYearChange" size="sm" />
                    </div>
                </div>

                <!-- Combo chart -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 dark:bg-gray-800 dark:border-gray-700">
                    <h3 class="text-lg font-black text-gray-700 dark:text-gray-200 mb-1">{{ filters.year }} ALTO Mall Hookup Weekly Status</h3>
                    <div class="flex items-center gap-4 text-xs text-gray-500 mb-3 dark:text-gray-400">
                        <span class="inline-flex items-center gap-1"><span class="w-6 h-0.5 bg-blue-500 inline-block"></span> Avg Auto Sending %</span>
                        <span class="inline-flex items-center gap-1"><span class="w-3 h-3 bg-red-300 inline-block rounded-sm"></span> No</span>
                        <span class="inline-flex items-center gap-1"><span class="w-3 h-3 bg-green-300 inline-block rounded-sm"></span> Yes</span>
                    </div>
                    <div v-if="(weeklyReport || []).length" class="overflow-x-auto">
                        <svg :viewBox="`0 0 ${chart.width} ${chart.h}`" :width="chart.width" :height="chart.h" class="max-w-full">
                            <g v-for="b in chart.bars" :key="b.week">
                                <rect :x="b.x" :y="b.yesY" :width="b.barW" :height="b.yesH" class="fill-green-300" />
                                <rect :x="b.x" :y="b.noY" :width="b.barW" :height="b.noH" class="fill-red-300" />
                                <text :x="b.cx" :y="chart.h - 16" text-anchor="middle" class="fill-gray-700 dark:fill-gray-300" font-size="10">{{ b.yes }}</text>
                                <text v-if="b.no" :x="b.cx" :y="b.noY - 3" text-anchor="middle" class="fill-gray-600 dark:fill-gray-400" font-size="9">{{ b.no }}</text>
                                <text :x="b.cx" :y="chart.h - 3" text-anchor="middle" class="fill-gray-400" font-size="9">{{ b.week }}</text>
                            </g>
                            <polyline :points="chart.linePts" fill="none" class="stroke-blue-500" stroke-width="2" />
                            <g v-for="b in chart.bars" :key="`p${b.week}`">
                                <circle :cx="b.cx" :cy="b.pctY" r="2.5" class="fill-blue-500" />
                                <text :x="b.cx" :y="b.pctY - 6" text-anchor="middle" class="fill-blue-600 dark:fill-blue-400" font-size="8">{{ b.pct }}%</text>
                            </g>
                        </svg>
                    </div>
                    <p v-else class="text-sm text-gray-400 italic py-8 text-center">No status logs for {{ filters.year }} yet.</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Weekly table -->
                    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-blue-900 text-white">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-bold">Week</th>
                                        <th class="px-3 py-2 text-center font-bold">Avg Auto Sending %</th>
                                        <th class="px-3 py-2 text-center font-bold">Yes</th>
                                        <th class="px-3 py-2 text-center font-bold">No</th>
                                        <th class="px-3 py-2 text-center font-bold">N/A</th>
                                        <th class="px-3 py-2 text-center font-bold">For Accreditation</th>
                                        <th class="px-3 py-2 text-center font-bold">Avg Total POS</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="w in weeklyReport" :key="w.week" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                        <td class="px-3 py-1.5 font-semibold text-gray-700 dark:text-gray-200">{{ w.week }}</td>
                                        <td class="px-3 py-1.5 text-center text-gray-700 dark:text-gray-200">{{ w.avg_sending_pct }}%</td>
                                        <td class="px-3 py-1.5 text-center text-green-700 dark:text-green-400">{{ w.yes }}</td>
                                        <td class="px-3 py-1.5 text-center text-red-700 dark:text-red-400">{{ w.no }}</td>
                                        <td class="px-3 py-1.5 text-center text-gray-500">{{ w.na || '' }}</td>
                                        <td class="px-3 py-1.5 text-center text-amber-700 dark:text-amber-400">{{ w.for_accreditation || '' }}</td>
                                        <td class="px-3 py-1.5 text-center text-gray-700 dark:text-gray-200">{{ w.avg_total_pos }}</td>
                                    </tr>
                                    <tr v-if="!(weeklyReport || []).length">
                                        <td colspan="7" class="px-3 py-6 text-center text-gray-400 italic">No data</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Top issue reasons -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 dark:bg-gray-800 dark:border-gray-700">
                        <h3 class="text-sm font-black text-gray-700 dark:text-gray-200 mb-3 uppercase tracking-wider">Top Issue Reasons</h3>
                        <p class="text-[11px] text-gray-400 mb-3">From {{ fmtDate(filters.matrix_from) }} – {{ fmtDate(filters.matrix_to) }}</p>
                        <div v-for="[reason, count] in topReasons" :key="reason" class="mb-2">
                            <div class="flex justify-between text-xs mb-0.5">
                                <span class="text-gray-600 dark:text-gray-300">{{ reason }}</span>
                                <span class="font-bold text-gray-700 dark:text-gray-200">{{ count }}</span>
                            </div>
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden dark:bg-gray-700">
                                <div class="h-full bg-red-400 rounded-full" :style="{ width: (count / topReasonMax * 100) + '%' }"></div>
                            </div>
                        </div>
                        <p v-if="!topReasons.length" class="text-sm text-gray-400 italic">No issues recorded in range.</p>
                    </div>
                </div>
            </div>

            <!-- ============ DAILY MONITORING ============ -->
            <div v-show="activeTab === 'daily'" class="space-y-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-wrap items-center gap-3 dark:bg-gray-800 dark:border-gray-700">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Date</label>
                        <input v-model="dailyDate" type="date" class="border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                    </div>
                    <div class="flex-1"></div>
                    <button v-if="can('mall_hookup.edit')" @click="markAllYes"
                        class="px-3 py-2 text-sm font-bold rounded-lg bg-green-50 text-green-700 hover:bg-green-100 whitespace-nowrap inline-flex items-center dark:bg-green-900/30 dark:text-green-300">
                        Mark all Yes
                    </button>
                    <button v-if="can('mall_hookup.edit')" @click="saveDaily" :disabled="savingDaily"
                        class="px-4 py-2 text-sm font-bold rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap inline-flex items-center">
                        {{ savingDaily ? 'Saving…' : 'Save Day' }}
                    </button>
                    <p class="w-full text-xs text-amber-600 dark:text-amber-400">Marks are not stored until you click <b>Save Day</b>. Saved statuses feed the Dashboard &amp; Compliance Matrix.</p>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto dark:bg-gray-800 dark:border-gray-700">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr class="text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">
                                <th class="px-4 py-3">Store</th>
                                <th class="px-4 py-3">Mall</th>
                                <th class="px-4 py-3">Area</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 w-64">Remark</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="row in board" :key="row.mall_hookup_id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-4 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ row.store_code }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ row.developer }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ row.area }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex gap-1">
                                        <button v-for="s in statuses" :key="s" @click="setStatus(row, s)"
                                            :class="['px-2.5 py-1 rounded-full text-xs font-bold transition-all',
                                                row.status === s ? statusCellClass(s) + ' ring-2 ring-offset-1 ring-current'
                                                : 'bg-gray-100 text-gray-400 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400']">
                                            {{ statusLabel(s) }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <Autocomplete v-if="row.status === 'no'" v-model="row.remark" :options="reasonOptions"
                                        placeholder="Select reason…" size="sm" allow-custom />
                                    <span v-else class="text-gray-300">—</span>
                                </td>
                            </tr>
                            <tr v-if="!board.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-400 italic">No active stores found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============ LOCATIONS ============ -->
            <div v-show="activeTab === 'locations'" class="space-y-4">
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="/mall-hookups/import-template"
                        class="px-3 py-2 text-sm font-bold rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 whitespace-nowrap inline-flex items-center dark:bg-gray-700 dark:text-gray-200">
                        Import Template
                    </a>
                    <button v-if="can('mall_hookup.create')" @click="triggerImport" :disabled="importing"
                        class="px-3 py-2 text-sm font-bold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50 whitespace-nowrap inline-flex items-center">
                        {{ importing ? 'Importing…' : 'Import History' }}
                    </button>
                    <input ref="fileInput" type="file" accept=".xlsx" class="hidden" @change="onFileChosen" />
                </div>
                <p class="text-xs text-gray-400">All active stores are listed automatically. Telco, bandwidth &amp; wiring come from the store's Payments connectivity record.</p>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto dark:bg-gray-800 dark:border-gray-700">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr class="text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">
                                <th class="px-4 py-3">Store</th>
                                <th class="px-4 py-3">Mall</th>
                                <th class="px-4 py-3">Area</th>
                                <th class="px-4 py-3">Hookup Status</th>
                                <th class="px-4 py-3">Primary Telco</th>
                                <th class="px-4 py-3">Mbps</th>
                                <th class="px-4 py-3">Wiring</th>
                                <th class="px-4 py-3">UPS</th>
                                <th class="px-4 py-3 text-right">Savings</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="loc in locations" :key="loc.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-4 py-2">
                                    <div class="font-semibold text-gray-700 dark:text-gray-200">{{ loc.store_code }}</div>
                                    <div class="text-xs text-gray-400">{{ loc.store_name }}</div>
                                </td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ loc.developer }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ loc.area }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">{{ loc.hookup_status || '—' }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ loc.primary_telco || '—' }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ loc.primary_bandwidth || '—' }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400 capitalize">{{ loc.wiring_type || '—' }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ loc.with_ups === null ? '—' : (loc.with_ups ? 'Yes' : 'No') }}</td>
                                <td class="px-4 py-2 text-right font-semibold text-emerald-700 dark:text-emerald-400">{{ peso(loc.savings) }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex justify-end space-x-1">
                                        <button v-if="can('mall_hookup.edit')" @click="openEdit(loc)" title="Edit"
                                            class="p-2 rounded-full transition-colors text-blue-600 hover:text-blue-900 hover:bg-blue-50 dark:hover:bg-blue-900/30">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!(locations || []).length">
                                <td colspan="10" class="px-4 py-8 text-center text-gray-400 italic">No active stores found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============ COMPLIANCE MATRIX ============ -->
            <div v-show="activeTab === 'matrix'" class="space-y-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-wrap items-end gap-3 dark:bg-gray-800 dark:border-gray-700">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">From</label>
                        <input v-model="matrixFrom" type="date" class="border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">To</label>
                        <input v-model="matrixTo" type="date" class="border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                    </div>
                    <button @click="applyMatrixRange"
                        class="px-4 py-2 text-sm font-bold rounded-lg bg-blue-600 text-white hover:bg-blue-700 whitespace-nowrap inline-flex items-center">Apply</button>
                    <div class="flex-1"></div>
                    <a :href="`/mall-hookups/export?matrix_from=${filters.matrix_from}&matrix_to=${filters.matrix_to}`"
                        class="px-3 py-2 text-sm font-bold rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 whitespace-nowrap inline-flex items-center dark:bg-gray-700 dark:text-gray-200">Export Excel</a>
                </div>

                <div v-if="!(matrix.dates || []).length" class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-sm text-gray-500 dark:text-gray-400">No daily statuses were recorded between these dates.</p>
                    <p class="text-xs text-gray-400 mt-1">Record statuses in <b>Daily Monitoring</b> (or use <b>Import History</b>), then they appear here as a color grid — one column per day, one row per store.</p>
                </div>
                <div v-else class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-auto max-h-[70vh] dark:bg-gray-800 dark:border-gray-700">
                    <table class="text-xs border-collapse">
                        <thead>
                            <tr>
                                <th class="sticky left-0 z-20 bg-gray-100 px-3 py-2 text-left font-bold text-gray-600 dark:bg-gray-900 dark:text-gray-300 min-w-[120px]">Store</th>
                                <th v-for="d in matrix.dates" :key="d" class="px-1 py-2 font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap min-w-[44px] text-center">{{ fmtDate(d) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in matrix.rows" :key="row.mall_hookup_id" class="border-t border-gray-100 dark:border-gray-700">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1 font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200 whitespace-nowrap">{{ row.store_code }}</td>
                                <td v-for="d in matrix.dates" :key="d" class="px-0.5 py-0.5 text-center">
                                    <span v-if="row.cells[d]"
                                        :title="row.cells[d].remark || statusLabel(row.cells[d].status)"
                                        :class="['inline-block w-full h-5 leading-5 rounded text-[10px] font-bold', statusCellClass(row.cells[d].status)]">
                                        {{ statusLabel(row.cells[d].status) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!(matrix.rows || []).length">
                                <td :colspan="(matrix.dates || []).length + 1" class="px-4 py-8 text-center text-gray-400 italic">No data in range.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Location modal -->
        <Modal :show="locModal.open" @close="locModal.open = false" max-width="2xl">
            <div class="p-6">
                <h3 class="text-lg font-black text-gray-800 dark:text-gray-100">Edit {{ locModal.store_code }}</h3>
                <p class="text-xs text-gray-400 mb-4">Area: {{ locModal.area }} &nbsp;·&nbsp; <span class="italic">from the store record</span></p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Developer / Mall</label>
                        <input v-model="form.developer" type="text" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Hookup Status</label>
                        <Autocomplete v-model="form.hookup_status" :options="hookupStatusOptions" placeholder="Select…" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">With UPS</label>
                        <Autocomplete v-model="form.with_ups" :options="upsOptions" placeholder="—" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Deployment Date</label>
                        <input v-model="form.deployment_date" type="date" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Deployment Status</label>
                        <input v-model="form.deployment_status" type="text" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Shouldered Mall — Telco Facility</label>
                        <input v-model="form.shouldered_facility" type="text" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                    </div>
                </div>

                <!-- Flexible per-year telco cost (savings = earliest year − latest year) -->
                <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Annual Telco Cost</label>
                        <button @click="addCostRow" type="button" class="text-[11px] font-black uppercase tracking-widest text-blue-600 hover:text-blue-700">+ Add Year</button>
                    </div>
                    <div v-if="!form.costs.length" class="text-xs text-gray-400 italic py-2">No cost rows. Add a year to compute savings.</div>
                    <div v-for="(c, i) in form.costs" :key="i" class="flex items-center gap-2 mb-2">
                        <input v-model.number="c.year" type="number" min="2000" max="2100" placeholder="Year"
                            class="w-28 border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                        <input v-model.number="c.amount" type="number" step="0.01" min="0" placeholder="Amount"
                            class="flex-1 border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                        <button @click="removeCostRow(i)" type="button" class="p-2 rounded-full text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30" title="Remove">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <p v-if="form.costs.length >= 2" class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold mt-1">Projected savings: {{ peso(savingsPreview) }}</p>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button @click="locModal.open = false" class="px-4 py-2 text-sm font-bold rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">Cancel</button>
                    <button @click="saveLocation" class="px-4 py-2 text-sm font-bold rounded-lg bg-blue-600 text-white hover:bg-blue-700">Save Changes</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
