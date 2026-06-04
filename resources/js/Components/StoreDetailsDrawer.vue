<script setup>
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { XMarkIcon, ArrowTopRightOnSquareIcon, ArrowDownTrayIcon, MapPinIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    show: { type: Boolean, default: false },
    storeId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close'])

const store = ref(null)
const sectorUsers = ref([])
const loading = ref(false)
const error = ref('')
const loadedId = ref(null)

const fetchDetails = async () => {
    if (!props.storeId) return
    // Avoid refetching the same store we already have loaded
    if (loadedId.value === props.storeId && store.value) return

    loading.value = true
    error.value = ''
    try {
        const { data } = await axios.get(route('stores.details', props.storeId))
        store.value = data.store
        sectorUsers.value = data.sector_users || []
        loadedId.value = props.storeId
    } catch (e) {
        error.value = e.response?.data?.message || 'Failed to load store details.'
        store.value = null
        sectorUsers.value = []
    } finally {
        loading.value = false
    }
}

watch(() => props.show, (val) => {
    if (val) fetchDetails()
})

watch(() => props.storeId, () => {
    // Invalidate cache when the selected store changes; refetch if drawer is open
    if (loadedId.value !== props.storeId) {
        store.value = null
        loadedId.value = null
        if (props.show) fetchDetails()
    }
})

const close = () => emit('close')

// ── option helpers ──────────────────────────────────────────────────────
const optionValues = (type) =>
    (store.value?.options || []).filter(o => o.type === type).map(o => o.value)

const remoteApps = computed(() =>
    (store.value?.options || [])
        .filter(o => o.type === 'remote_app')
        .map(o => ({ app: o.value, id: o.meta || '' }))
)

const telcos = computed(() => optionValues('telco'))
const connectivity = computed(() => optionValues('connectivity_type'))
const systems = computed(() => optionValues('system'))

const hasGeo = computed(() => store.value?.latitude && store.value?.longitude)
const mapsUrl = computed(() =>
    hasGeo.value ? `https://www.google.com/maps?q=${store.value.latitude},${store.value.longitude}` : ''
)

const blueprintUrl = (bp) => route('stores.blueprints.download', [store.value.id, bp.id])

const formatBytes = (bytes) => {
    if (!bytes) return ''
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const formatDate = (value) => {
    if (!value) return ''
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return value
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100" leave-to-class="opacity-0"
    >
        <div v-if="show" class="fixed inset-0 z-[60]">
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/30 backdrop-blur-sm" @click="close"></div>

            <!-- Panel -->
            <Transition
                enter-active-class="transform transition ease-out duration-300"
                enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                leave-active-class="transform transition ease-in duration-200"
                leave-from-class="translate-x-0" leave-to-class="translate-x-full"
            >
                <div v-if="show" class="absolute right-0 top-0 h-full w-full max-w-md bg-white shadow-2xl flex flex-col">
                    <!-- Header -->
                    <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-br from-blue-600 to-indigo-600 text-white">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-black truncate">{{ store?.name || 'Store Details' }}</h3>
                                <p v-if="store" class="text-xs font-mono text-blue-100 tracking-tight">{{ store.code }}</p>
                            </div>
                            <button @click="close" class="text-blue-100 hover:text-white shrink-0">
                                <XMarkIcon class="w-6 h-6" />
                            </button>
                        </div>
                        <div v-if="store" class="mt-2 flex flex-wrap items-center gap-2">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider"
                                  :class="store.is_active ? 'bg-emerald-400/20 text-emerald-50 border border-emerald-300/40' : 'bg-gray-400/20 text-gray-50 border border-gray-300/40'">
                                {{ store.is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-white/15 border border-white/25">
                                Sector {{ store.sector }}
                            </span>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto custom-scrollbar">
                        <!-- Loading -->
                        <div v-if="loading" class="py-16 flex flex-col items-center justify-center space-y-3">
                            <svg class="animate-spin h-7 w-7 text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4z"></path>
                            </svg>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Loading...</p>
                        </div>

                        <div v-else-if="error" class="m-5 p-4 bg-red-50 border border-red-100 rounded-lg text-sm text-red-700">
                            {{ error }}
                        </div>

                        <div v-else-if="store" class="p-5 space-y-6">
                            <!-- Overview -->
                            <section>
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Overview</h4>
                                <dl class="grid grid-cols-2 gap-3">
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Brand</dt>
                                        <dd class="text-sm font-semibold text-gray-900">{{ store.brand || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Area</dt>
                                        <dd class="text-sm font-semibold text-gray-900">{{ store.area || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Class</dt>
                                        <dd class="text-sm font-semibold text-gray-900">{{ store.class || '—' }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-3">
                                    <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Clusters</dt>
                                    <div v-if="store.clusters?.length" class="flex flex-wrap gap-1">
                                        <span v-for="c in store.clusters" :key="c.id" class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-100">{{ c.name }}</span>
                                    </div>
                                    <span v-else class="text-sm text-gray-400">—</span>
                                </div>
                                <div class="mt-3">
                                    <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Sector {{ store.sector }} — Assigned Personnel</dt>
                                    <div v-if="sectorUsers.length" class="space-y-1">
                                        <div v-for="u in sectorUsers" :key="u.id" class="flex items-center gap-2">
                                            <div class="h-6 w-6 rounded-full bg-blue-100 flex items-center justify-center text-[10px] font-bold text-blue-700 shrink-0">{{ u.name.charAt(0) }}</div>
                                            <div class="min-w-0">
                                                <div class="text-xs text-gray-700 truncate">{{ u.name }}</div>
                                                <div v-if="u.position" class="text-[10px] text-gray-400 truncate">{{ u.position }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <span v-else class="text-sm text-gray-400">No personnel assigned to this sector</span>
                                </div>
                            </section>

                            <!-- Contact -->
                            <section class="pt-4 border-t border-gray-100">
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Contact</h4>
                                <dl class="space-y-2">
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Contact Person (AOM)</dt>
                                        <dd class="text-sm font-semibold text-gray-900">{{ store.contact_person || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Contact Details</dt>
                                        <dd class="text-sm font-semibold text-gray-900">{{ store.contact_details || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Email</dt>
                                        <dd class="text-sm font-semibold">
                                            <a v-if="store.email" :href="`mailto:${store.email}`" class="text-blue-600 hover:underline">{{ store.email }}</a>
                                            <span v-else class="text-gray-400">—</span>
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            <!-- Connectivity & Systems -->
                            <section class="pt-4 border-t border-gray-100">
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Connectivity & Systems</h4>
                                <div class="space-y-3">
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Hookup</dt>
                                        <dd class="text-sm font-semibold text-gray-900">{{ store.hookup || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Telco</dt>
                                        <div v-if="telcos.length" class="flex flex-wrap gap-1">
                                            <span v-for="t in telcos" :key="t" class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-100">{{ t }}</span>
                                        </div>
                                        <span v-else class="text-sm text-gray-400">—</span>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Connectivity Type</dt>
                                        <div v-if="connectivity.length" class="flex flex-wrap gap-1">
                                            <span v-for="c in connectivity" :key="c" class="px-2 py-0.5 rounded text-[10px] font-bold bg-teal-50 text-teal-700 border border-teal-100">{{ c }}</span>
                                        </div>
                                        <span v-else class="text-sm text-gray-400">—</span>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Systems Deployed</dt>
                                        <div v-if="systems.length" class="flex flex-wrap gap-1">
                                            <span v-for="s in systems" :key="s" class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-100">{{ s }}</span>
                                        </div>
                                        <span v-else class="text-sm text-gray-400">—</span>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Remote Apps</dt>
                                        <div v-if="remoteApps.length" class="space-y-1">
                                            <div v-for="(r, idx) in remoteApps" :key="idx" class="flex items-center justify-between gap-2 bg-gray-50 rounded-lg border border-gray-100 px-3 py-1.5">
                                                <span class="text-xs font-bold text-gray-700">{{ r.app }}</span>
                                                <span class="text-xs font-mono text-gray-500 truncate">{{ r.id || '—' }}</span>
                                            </div>
                                        </div>
                                        <span v-else class="text-sm text-gray-400">—</span>
                                    </div>
                                </div>
                            </section>

                            <!-- Files & Location -->
                            <section class="pt-4 border-t border-gray-100">
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Files & Location</h4>
                                <div class="space-y-3">
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase">Opening Date</dt>
                                        <dd class="text-sm font-semibold text-gray-900">{{ store.opening_date ? formatDate(store.opening_date) : '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Blueprints</dt>
                                        <div v-if="store.blueprints?.length" class="space-y-1.5">
                                            <a v-for="bp in store.blueprints" :key="bp.id" :href="blueprintUrl(bp)" target="_blank" rel="noopener noreferrer"
                                               class="flex items-center justify-between gap-2 bg-white rounded-lg border border-gray-200 px-3 py-2 hover:border-blue-300 hover:bg-blue-50/40 transition-colors">
                                                <span class="text-xs font-bold text-blue-600 truncate">{{ bp.file_name }}</span>
                                                <span class="flex items-center gap-1.5 shrink-0">
                                                    <span class="text-[10px] text-gray-400">{{ formatBytes(bp.file_size_bytes) }}</span>
                                                    <ArrowDownTrayIcon class="w-3.5 h-3.5 text-blue-500" />
                                                </span>
                                            </a>
                                        </div>
                                        <span v-else class="text-sm text-gray-400">No blueprint files</span>
                                    </div>
                                    <div>
                                        <dt class="text-[10px] font-bold text-gray-400 uppercase mb-1">Geofencing</dt>
                                        <div v-if="hasGeo" class="text-sm text-gray-900 space-y-1">
                                            <div class="flex items-center gap-1.5 text-xs text-gray-600">
                                                <MapPinIcon class="w-4 h-4 text-emerald-500" />
                                                {{ store.latitude }}, {{ store.longitude }} · {{ store.radius_meters }}m
                                            </div>
                                            <a :href="mapsUrl" target="_blank" rel="noopener noreferrer"
                                               class="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:underline">
                                                View on Maps <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
                                            </a>
                                        </div>
                                        <span v-else class="text-sm text-gray-400">Not set</span>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </Transition>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>
