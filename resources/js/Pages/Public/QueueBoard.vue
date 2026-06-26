<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'

const props = defineProps({
    board: { type: Object, required: true },
    token: { type: String, required: true },
    refreshSeconds: { type: Number, default: 7 },
    orgName: { type: String, default: 'Support Queue' },
})

const route = window.route
const board = ref(props.board)
const now = ref(new Date())
let pollTimer = null
let clockTimer = null

const lanes = computed(() => board.value?.lanes || [])

const clock = computed(() =>
    now.value.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true })
)
const today = computed(() =>
    now.value.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })
)

const priorityClass = (p) => ({
    urgent: 'bg-red-500/20 text-red-300 ring-red-500/40',
    high: 'bg-orange-500/20 text-orange-300 ring-orange-500/40',
    medium: 'bg-amber-500/20 text-amber-200 ring-amber-500/40',
    low: 'bg-slate-500/20 text-slate-300 ring-slate-500/40',
}[String(p || '').toLowerCase()] || 'bg-slate-500/20 text-slate-300 ring-slate-500/40')

const fetchBoard = async () => {
    try {
        const res = await fetch(route('public.queue.board.data', props.token), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        })
        if (res.ok) board.value = await res.json()
    } catch (e) { /* keep last good data */ }
}

onMounted(() => {
    fetchBoard()
    pollTimer = setInterval(fetchBoard, Math.max(3, props.refreshSeconds) * 1000)
    clockTimer = setInterval(() => { now.value = new Date() }, 1000)
})
onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer)
    if (clockTimer) clearInterval(clockTimer)
})
</script>

<template>
    <Head title="Queue Board" />

    <div class="min-h-screen bg-slate-950 text-white flex flex-col">
        <!-- Header -->
        <header class="flex items-center justify-between px-8 py-5 border-b border-white/10 bg-slate-900/60">
            <div class="flex items-center gap-4">
                <div class="h-12 px-3 bg-white rounded-lg flex items-center justify-center shadow-sm" style="background-color: white !important;">
                    <img src="/images/company_logo.png" alt="Company Logo" class="h-8 w-auto object-contain">
                </div>
                <h1 class="text-2xl font-black tracking-tight">{{ orgName }}</h1>
            </div>
            <div class="text-right">
                <p class="text-3xl font-bold tabular-nums leading-none">{{ clock }}</p>
                <p class="text-sm text-slate-400">{{ today }}</p>
            </div>
        </header>

        <!-- Lanes -->
        <main class="flex-1 p-6 grid gap-6"
              :class="lanes.length >= 3 ? '2xl:grid-cols-3 md:grid-cols-2' : 'md:grid-cols-2'">
            <section v-for="lane in lanes" :key="lane.key"
                     class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 flex flex-col overflow-hidden">
                <!-- Lane title -->
                <div class="px-6 py-4 bg-gradient-to-r from-blue-600/30 to-transparent border-b border-white/10 flex items-center justify-between">
                    <h2 class="text-xl font-bold uppercase tracking-wide">{{ lane.name }}</h2>
                    <div class="text-right text-sm text-slate-300">
                        <span class="font-bold text-white">{{ lane.counts.waiting }}</span> waiting
                        <span v-if="lane.avg_wait_minutes !== null" class="text-slate-400"> · ~{{ lane.avg_wait_minutes }}m</span>
                    </div>
                </div>

                <!-- Now serving -->
                <div class="px-6 py-5 border-b border-white/10">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-400 mb-2">Now Serving</p>
                    <div v-if="lane.now_serving.length" class="flex flex-wrap gap-x-8 gap-y-2">
                        <p v-for="c in lane.now_serving" :key="c.ticket_key"
                           class="font-mono font-black text-4xl xl:text-5xl text-emerald-300 tabular-nums leading-none">
                            {{ c.ticket_key }}
                        </p>
                    </div>
                    <p v-else class="font-mono font-black text-4xl xl:text-5xl text-slate-600 leading-none">—</p>
                </div>

                <!-- Next up -->
                <div class="flex-1 px-4 py-3 overflow-hidden">
                    <p class="px-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-400 mb-2">Next Up</p>
                    <p v-if="!lane.waiting.length" class="px-2 text-slate-500 text-lg py-6 text-center">Queue is clear ✓</p>
                    <ol v-else class="space-y-1.5">
                        <li v-for="c in lane.waiting.slice(0, 7)" :key="c.ticket_key"
                            class="flex items-center gap-4 rounded-xl px-3 py-2.5"
                            :class="c.is_breached ? 'bg-red-500/10 ring-1 ring-red-500/30' : 'bg-white/5'">
                            <span class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-500/20 text-blue-200 text-xl font-black flex items-center justify-center tabular-nums">{{ c.position }}</span>
                            <span class="font-mono font-bold text-2xl xl:text-3xl tracking-tight flex-1 tabular-nums">{{ c.ticket_key }}</span>
                            <span class="text-[11px] px-2 py-0.5 rounded-full font-bold uppercase ring-1" :class="priorityClass(c.priority)">{{ c.priority }}</span>
                            <span class="text-right text-base font-semibold tabular-nums" :class="c.is_breached ? 'text-red-300' : 'text-slate-300'">
                                {{ c.eta_label || '—' }}
                            </span>
                        </li>
                    </ol>
                    <p v-if="lane.waiting.length > 7" class="px-2 pt-2 text-sm text-slate-500">
                        + {{ lane.waiting.length - 7 }} more in line
                    </p>
                </div>
            </section>
        </main>

        <footer class="px-8 py-3 border-t border-white/10 bg-slate-900/60 flex items-center justify-between text-xs text-slate-500">
            <span>Tickets are served by SLA priority. Please watch for your ticket number.</span>
            <span>Auto-updates every {{ refreshSeconds }}s · {{ board.generated_at }}</span>
        </footer>
    </div>
</template>
