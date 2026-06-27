<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useConfirm } from '@/Composables/useConfirm'

const props = defineProps({
    board: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    canOperate: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
    settings: { type: Object, default: null },
    companies: { type: Array, default: () => [] },
})

const route = window.route
const board = ref(props.board)
const lastUpdated = ref(props.board?.generated_at || null)
const callingLane = ref(null)
const { confirm } = useConfirm()
let pollTimer = null

// --- chime on new "now serving" -------------------------------------------
const MUTE_KEY = 'ghelpdesk.queueMuted'
const muted = ref(false)
let knownServing = new Set()
let primed = false

const collectServing = (b) => {
    const set = new Set()
    ;(b?.lanes || []).forEach(l => (l.now_serving || []).forEach(c => set.add(c.ticket_key)))
    return set
}
const beep = () => {
    if (muted.value) return
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)()
        const osc = ctx.createOscillator()
        const gain = ctx.createGain()
        osc.connect(gain); gain.connect(ctx.destination)
        osc.type = 'sine'; osc.frequency.value = 880
        gain.gain.setValueAtTime(0.001, ctx.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02)
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4)
        osc.start(); osc.stop(ctx.currentTime + 0.42)
    } catch (e) { /* audio unavailable */ }
}
const detectNewServing = (b) => {
    const current = collectServing(b)
    if (!primed) { knownServing = current; primed = true; return }
    for (const key of current) {
        if (!knownServing.has(key)) { beep(); break }
    }
    knownServing = current
}
const toggleMute = () => {
    muted.value = !muted.value
    try { localStorage.setItem(MUTE_KEY, muted.value ? '1' : '0') } catch (e) {}
}

// Inertia reloads (e.g. after Call Next) refresh the prop — mirror it locally.
watch(() => props.board, (val) => {
    if (val) { board.value = val; lastUpdated.value = val.generated_at; detectNewServing(val) }
})

const lanes = computed(() => board.value?.lanes || [])
const refreshMs = computed(() => Math.max(3, Number(props.config?.refresh_seconds || 7)) * 1000)

const priorityClass = (p) => ({
    urgent: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    low: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
}[String(p || '').toLowerCase()] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')

const channelLabel = (c) => ({ walk_in: 'Walk-in', web: 'Web', email: 'Email', phone: 'Phone' }[c] || null)

const fetchBoard = async () => {
    try {
        const res = await fetch(route('queue.data'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        })
        if (!res.ok) return
        const data = await res.json()
        board.value = data
        lastUpdated.value = data.generated_at
        detectNewServing(data)
    } catch (e) { /* keep last good data */ }
}

const restartPolling = () => {
    if (pollTimer) clearInterval(pollTimer)
    pollTimer = setInterval(fetchBoard, refreshMs.value)
}

const callNext = async (lane) => {
    if (!props.canOperate || callingLane.value) return

    const confirmed = await confirm({
        title: 'Call Next Ticket',
        message: `Call the next waiting ticket for ${lane.name}? The ticket will be assigned to you and marked as Now Serving.`,
        confirmLabel: 'Call Next',
        cancelLabel: 'Cancel',
        variant: 'info',
    })

    if (!confirmed || callingLane.value) return

    callingLane.value = lane.key
    router.post(route('queue.call-next'), { lane: lane.key }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { callingLane.value = null; fetchBoard() },
    })
}

// --- settings modal --------------------------------------------------------
const showSettings = ref(false)
const parseLaneCodes = (raw) => {
    try { const a = JSON.parse(raw); return Array.isArray(a) ? a.join(', ') : 'SO, CS' }
    catch (e) { return raw || 'SO, CS' }
}
const settingsForm = useForm({
    queue_board_title: props.settings?.queue_board_title ?? 'Support Queue',
    queue_refresh_seconds: props.settings?.queue_refresh_seconds ?? 7,
    queue_lane_codes: parseLaneCodes(props.settings?.queue_lane_nodes),
    queue_walkin_company_id: props.settings?.queue_walkin_company_id ?? null,
    queue_walkin_priority_floor: props.settings?.queue_walkin_priority_floor ?? 'medium',
    queue_kiosk_require_email: !!props.settings?.queue_kiosk_require_email,
})
const companyOptions = computed(() => [{ label: '— Use store’s company —', value: null }, ...props.companies.map(c => ({ label: c.name, value: c.id }))])
const priorityFloorOptions = [
    { label: 'Low', value: 'low' }, { label: 'Medium', value: 'medium' },
    { label: 'High', value: 'high' }, { label: 'Urgent', value: 'urgent' },
]
const saveSettings = () => {
    router.put(route('settings.update'), {
        queue_board_title: settingsForm.queue_board_title,
        queue_refresh_seconds: Math.max(3, Number(settingsForm.queue_refresh_seconds) || 7),
        queue_lane_nodes: settingsForm.queue_lane_codes.split(',').map(s => s.trim()).filter(Boolean),
        queue_walkin_company_id: settingsForm.queue_walkin_company_id,
        queue_walkin_priority_floor: settingsForm.queue_walkin_priority_floor,
        queue_kiosk_require_email: settingsForm.queue_kiosk_require_email ? 1 : 0,
    }, { preserveScroll: true, onSuccess: () => { showSettings.value = false } })
}
const regenerate = (type) => {
    router.post(route('queue.regenerate-token'), { type }, { preserveScroll: true })
}
const copy = (url) => { if (url) navigator.clipboard?.writeText(url) }

onMounted(() => {
    try { muted.value = localStorage.getItem(MUTE_KEY) === '1' } catch (e) {}
    detectNewServing(board.value)
    restartPolling()
})
watch(refreshMs, restartPolling)
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
    <Head title="Queue Monitor" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h10M4 18h10" />
                </svg>
                <span>Queue Monitor</span>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Live ticket queue ordered by SLA due time.
                    <span v-if="board.served_today != null" class="font-semibold text-emerald-600">{{ board.served_today }} served today</span>
                    · updated <span class="font-medium text-gray-700 dark:text-gray-300">{{ lastUpdated }}</span>
                </p>
                <div class="flex items-center gap-2">
                    <button @click="toggleMute" :title="muted ? 'Unmute call chime' : 'Mute call chime'"
                            class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700">
                        <svg v-if="!muted" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.536 8.464a5 5 0 010 7.072M12 6.5L7.5 10H4v4h3.5L12 17.5v-11z" /></svg>
                        <svg v-else class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9l-4 4m0-4l4 4M12 6.5L7.5 10H4v4h3.5L12 17.5v-11z" /></svg>
                    </button>
                    <a v-if="config.public_board_url" :href="config.public_board_url" target="_blank"
                       class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-gray-800 text-white hover:bg-gray-900 whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        Lobby Board
                    </a>
                    <a v-if="config.kiosk_url" :href="config.kiosk_url" target="_blank"
                       class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        Walk-in Kiosk
                    </a>
                    <button v-if="canManage" @click="showSettings = true" title="Queue settings"
                            class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    </button>
                </div>
            </div>

            <!-- Lanes -->
            <div class="grid gap-4" :class="lanes.length >= 3 ? 'xl:grid-cols-3 md:grid-cols-2' : 'lg:grid-cols-2'">
                <div v-for="lane in lanes" :key="lane.key"
                     class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                    <!-- Lane header -->
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between"
                         :class="lane.is_triage ? 'bg-gray-50 dark:bg-gray-900/40' : 'bg-blue-50/60 dark:bg-blue-900/20'">
                        <div>
                            <h3 class="font-bold text-gray-900 dark:text-gray-100">{{ lane.name }}</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ lane.counts.waiting }} waiting · {{ lane.counts.serving }} serving
                                <span v-if="lane.avg_wait_minutes !== null"> · avg wait {{ lane.avg_wait_minutes }}m</span>
                            </p>
                        </div>
                        <button v-if="canOperate" @click="callNext(lane)" :disabled="callingLane === lane.key || lane.counts.waiting === 0"
                                class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg text-white disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap"
                                :class="lane.is_triage ? 'bg-gray-700 hover:bg-gray-800' : 'bg-green-600 hover:bg-green-700'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7" /></svg>
                            {{ lane.is_triage ? 'Take Next' : 'Call Next' }}
                        </button>
                    </div>

                    <!-- Now serving -->
                    <div v-if="lane.now_serving.length" class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                        <p class="text-[11px] font-bold uppercase tracking-widest text-green-600 mb-2">Now Serving</p>
                        <div class="space-y-2">
                            <div v-for="c in lane.now_serving" :key="c.id"
                                 class="flex items-center justify-between rounded-lg bg-green-50 px-3 py-2 dark:bg-green-900/20">
                                <div class="min-w-0">
                                    <p class="font-mono font-bold text-green-700 dark:text-green-300">{{ c.ticket_key }}</p>
                                    <p class="text-xs text-gray-600 truncate dark:text-gray-400">{{ c.title }}</p>
                                </div>
                                <div class="text-right shrink-0 ml-3">
                                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ c.assignee?.name || '—' }}</p>
                                    <p class="text-[11px] text-gray-400">since {{ c.called_at || '—' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Waiting list -->
                    <div class="px-2 py-2 max-h-[46vh] overflow-y-auto">
                        <p v-if="!lane.waiting.length" class="text-center text-sm text-gray-400 py-6">No one waiting 🎉</p>
                        <ol v-else class="space-y-1">
                            <li v-for="c in lane.waiting" :key="c.id"
                                class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="flex-shrink-0 w-7 h-7 rounded-full bg-gray-100 text-gray-700 text-sm font-bold flex items-center justify-center dark:bg-gray-700 dark:text-gray-200">{{ c.position }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-semibold text-gray-900 text-sm dark:text-gray-100">{{ c.ticket_key }}</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold uppercase" :class="priorityClass(c.priority)">{{ c.priority }}</span>
                                        <span v-if="channelLabel(c.channel)" class="text-[10px] px-1.5 py-0.5 rounded-full bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-300">{{ channelLabel(c.channel) }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate dark:text-gray-400">{{ c.title }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-xs font-medium" :class="c.is_breached ? 'text-red-600' : 'text-gray-600 dark:text-gray-300'">
                                        {{ c.eta_label || 'No SLA' }}
                                    </p>
                                    <p class="text-[11px] text-gray-400">waited {{ c.waiting_minutes }}m</p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <!-- On hold -->
                    <div v-if="lane.on_hold && lane.on_hold.length" class="px-4 py-2 border-t border-gray-100 bg-gray-50/60 dark:border-gray-700 dark:bg-gray-900/30">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">On hold / scheduled ({{ lane.on_hold.length }})</p>
                        <div class="flex flex-wrap gap-1.5 mt-1">
                            <span v-for="c in lane.on_hold" :key="c.id" class="font-mono text-[11px] text-gray-500 bg-white border border-gray-200 rounded px-1.5 py-0.5 dark:bg-gray-800 dark:border-gray-600">{{ c.ticket_key }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings modal -->
        <div v-if="showSettings" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/40" @click.self="showSettings = false">
            <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden dark:bg-gray-800 max-h-[90vh] overflow-y-auto">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Queue Settings</h3>
                    <button @click="showSettings = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Board title</label>
                        <input v-model="settingsForm.queue_board_title" type="text" class="w-full px-3 py-2 border border-gray-200 rounded-lg dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Refresh (seconds)</label>
                            <input v-model="settingsForm.queue_refresh_seconds" type="number" min="3" class="w-full px-3 py-2 border border-gray-200 rounded-lg dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Walk-in priority floor</label>
                            <Autocomplete v-model="settingsForm.queue_walkin_priority_floor" :options="priorityFloorOptions" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Lane department codes</label>
                        <input v-model="settingsForm.queue_lane_codes" type="text" placeholder="SO, CS" class="w-full px-3 py-2 border border-gray-200 rounded-lg dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200" />
                        <p class="text-xs text-gray-400 mt-1">Comma-separated DepartmentNode codes shown as lanes.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Walk-in default company</label>
                        <Autocomplete v-model="settingsForm.queue_walkin_company_id" :options="companyOptions" placeholder="Use store's company" />
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="settingsForm.queue_kiosk_require_email" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Require email at the walk-in kiosk</span>
                    </label>

                    <div class="pt-2 border-t border-gray-100 dark:border-gray-700 space-y-2">
                        <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Public links</p>
                        <div class="flex items-center gap-2">
                            <input :value="config.public_board_url" readonly class="flex-1 px-3 py-2 text-xs border border-gray-200 rounded-lg bg-gray-50 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300" />
                            <button @click="copy(config.public_board_url)" class="px-2 py-2 text-xs rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-700">Copy</button>
                            <button @click="regenerate('board')" class="px-2 py-2 text-xs rounded-lg bg-amber-100 text-amber-700 hover:bg-amber-200">Reset</button>
                        </div>
                        <div class="flex items-center gap-2">
                            <input :value="config.kiosk_url" readonly class="flex-1 px-3 py-2 text-xs border border-gray-200 rounded-lg bg-gray-50 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300" />
                            <button @click="copy(config.kiosk_url)" class="px-2 py-2 text-xs rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-700">Copy</button>
                            <button @click="regenerate('kiosk')" class="px-2 py-2 text-xs rounded-lg bg-amber-100 text-amber-700 hover:bg-amber-200">Reset</button>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-2">
                    <button @click="showSettings = false" class="px-4 py-2 text-sm rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">Cancel</button>
                    <button @click="saveSettings" class="px-4 py-2 text-sm font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700">Save settings</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
