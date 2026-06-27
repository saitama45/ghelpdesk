<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'

const props = defineProps({
    token: { type: String, required: true },
    ticketKey: { type: String, required: true },
    info: { type: Object, required: true },
    refreshSeconds: { type: Number, default: 7 },
})

const route = window.route
const info = ref(props.info)
const ticketKey = ref(props.ticketKey)
let pollTimer = null

const state = computed(() => info.value?.state || 'waiting')

const theme = computed(() => ({
    waiting: { ring: 'ring-blue-200', dot: 'bg-blue-500', text: 'text-blue-700 dark:text-blue-300', soft: 'bg-blue-50 dark:bg-blue-950/40' },
    serving: { ring: 'ring-emerald-200', dot: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-300', soft: 'bg-emerald-50 dark:bg-emerald-950/40' },
    hold: { ring: 'ring-amber-200', dot: 'bg-amber-500', text: 'text-amber-700 dark:text-amber-300', soft: 'bg-amber-50 dark:bg-amber-950/40' },
    done: { ring: 'ring-gray-200', dot: 'bg-gray-400', text: 'text-gray-700 dark:text-gray-300', soft: 'bg-gray-50 dark:bg-gray-800' },
}[state.value] || { ring: 'ring-blue-200', dot: 'bg-blue-500', text: 'text-blue-700 dark:text-blue-300', soft: 'bg-blue-50 dark:bg-blue-950/40' }))

const headline = computed(() => ({
    waiting: 'You are in the queue',
    serving: 'You are being served now',
    hold: 'Your request is on hold',
    done: 'Your request is complete',
}[state.value] || 'You are in the queue'))

const fetchInfo = async () => {
    try {
        const res = await fetch(route('public.queue.track.data', props.token), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
        if (res.ok) {
            const data = await res.json()
            info.value = data.info
            ticketKey.value = data.ticketKey
        }
    } catch (e) { /* keep last good data */ }
}

onMounted(() => { pollTimer = setInterval(fetchInfo, Math.max(3, props.refreshSeconds) * 1000) })
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
    <Head title="Track My Ticket" />

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4 dark:bg-gray-900/50">
        <div class="max-w-md w-full bg-white rounded-3xl shadow-xl overflow-hidden border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
            <!-- Header -->
            <div class="p-6 text-center border-b border-gray-50 dark:border-gray-700" :class="theme.soft">
                <div class="mx-auto mb-4 flex h-16 w-fit items-center justify-center rounded-lg px-3 py-2 shadow-sm" style="background-color: #fff !important;">
                    <img src="/images/company_logo.png" alt="Logo" class="h-12 max-w-full object-contain" style="background-color: #fff !important;">
                </div>
                <p class="text-xs uppercase tracking-widest text-gray-500 dark:text-gray-300">Your ticket number</p>
                <p class="font-mono font-black text-4xl text-gray-900 mt-1 dark:text-gray-100">{{ ticketKey }}</p>
            </div>

            <!-- Body -->
            <div class="p-8 text-center space-y-6">
                <div class="flex items-center justify-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full animate-pulse" :class="theme.dot"></span>
                    <span class="text-sm font-bold uppercase tracking-wide" :class="theme.text">{{ info.status_label }}</span>
                </div>

                <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ headline }}</h1>

                <!-- Waiting position -->
                <div v-if="state === 'waiting'" class="space-y-1">
                    <p class="text-gray-500 text-sm dark:text-gray-400">Your position</p>
                    <p class="text-6xl font-black text-gray-900 leading-none dark:text-gray-100">
                        <span :class="theme.text">{{ info.position ?? '—' }}</span>
                        <span class="text-2xl text-gray-400 font-bold"> / {{ info.total_waiting ?? '—' }}</span>
                    </p>
                    <p v-if="info.lane" class="text-sm text-gray-500 dark:text-gray-400">in {{ info.lane }}</p>
                </div>

                <div v-else-if="state === 'serving'" class="py-2">
                    <p class="text-lg font-semibold text-emerald-600">A team member is handling your request.</p>
                </div>

                <!-- ETA -->
                <div v-if="info.eta_label && state !== 'done'" class="rounded-2xl bg-gray-50 px-4 py-4 dark:bg-gray-900/40">
                    <p class="text-xs uppercase tracking-widest text-gray-400">Expected by</p>
                    <p class="text-lg font-bold text-gray-800 mt-0.5 dark:text-gray-200">{{ info.eta_label }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">{{ info.eta_relative }}</p>
                </div>

                <div v-if="state === 'done'" class="py-2">
                    <p class="text-gray-600 dark:text-gray-300">Thank you for your patience. This request has been completed.</p>
                </div>
            </div>

            <div class="px-8 pb-6 text-center">
                <p class="text-[11px] text-gray-400">This page updates automatically. Keep it open to follow your place in line.</p>
            </div>
        </div>
    </div>
</template>
