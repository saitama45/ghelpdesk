<script setup>
import { ref, watch, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useDateFormatter } from '@/Composables/useDateFormatter';
import { MapPinIcon, ClockIcon, ArrowTopRightOnSquareIcon, MagnifyingGlassPlusIcon, MagnifyingGlassMinusIcon, ArrowsPointingOutIcon, XMarkIcon, FunnelIcon, UserCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    sessions: Object,
    officeAttendanceSummary: { type: Array, default: () => [] },
    users: Array,
    stores: Array,
    workHoursSummary: Array,
    filters: Object,
});

const { formatDate } = useDateFormatter();
const search = ref(props.filters?.search || '');
const isLoading = ref(false);
const activeTab = ref('logs');

const formatMinutes = (mins) => {
    const total = Math.abs(mins)
    const h = Math.floor(total / 60)
    const m = total % 60
    return `${h}h ${m}m`
}

const formatDetailWorkHours = (mins) => {
    return mins === null || mins === undefined ? '--' : formatMinutes(mins)
}

// Detail modal state
const detailModal = ref(false)
const detailUser  = ref('')
const detailMode  = ref('all')   // 'all' | 'present' | 'absent'
const detailRows  = ref([])

const filteredDetailRows = computed(() => {
    if (detailMode.value === 'present') return detailRows.value.filter(r => r.is_present)
    if (detailMode.value === 'absent')  return detailRows.value.filter(r => !r.is_present && r.scheduled_start)
    return detailRows.value
})

const openDetail = (row, mode) => {
    detailUser.value = row.name
    detailMode.value = mode
    detailRows.value = row.detail_dates || []
    detailModal.value = true
}

const formatTime = (t) => t || '—'

// Filters
const filterSubUnit = ref(props.filters?.sub_unit || '');
const filterStore = ref(props.filters?.store_id || '');
const filterDateFrom = ref(props.filters?.date_from || '');
const filterDateTo = ref(props.filters?.date_to || '');

const selectedOfficeId = computed(() => {
    const selectedId = Number(filterStore.value)
    return (props.officeAttendanceSummary || []).some(office => office.id === selectedId)
        ? selectedId
        : null
})

const selectOffice = (officeId) => {
    filterStore.value = selectedOfficeId.value === officeId ? '' : officeId
}

const clearOfficeFilter = () => {
    filterStore.value = ''
}

const subUnitOptions = computed(() => {
    if (!props.users) return [{ id: '', name: 'All Sub-Units' }];
    const units = props.users
        .map(u => u.sub_unit)
        .filter(u => u && u.trim() !== '')
    const unique = [...new Set(units)].sort()
    return [
        { id: '', name: 'All Sub-Units' },
        ...unique.map(u => ({ id: u, name: u }))
    ]
})

const storeOptions = computed(() => {
    return [
        { id: '', name: 'All Stores' },
        ...(props.stores || []).map(s => ({ id: s.id, name: s.name }))
    ]
})

const applyFilters = () => {
    isLoading.value = true;
    router.get(route('attendance.logs'), { 
        search: search.value,
        sub_unit: filterSubUnit.value,
        store_id: filterStore.value,
        date_from: filterDateFrom.value,
        date_to: filterDateTo.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => isLoading.value = false
    });
};

const clearFilters = () => {
    search.value = '';
    filterSubUnit.value = '';
    filterStore.value = '';
    filterDateFrom.value = '';
    filterDateTo.value = '';
    applyFilters();
};

// Trigger filter on change
watch([filterSubUnit, filterStore, filterDateFrom, filterDateTo], () => {
    applyFilters();
});

// --- Infinite scroll accumulation (mirrors Tickets/Index) ---
// Session rows are accumulated client-side across pages. The watcher on props.sessions
// replaces the buffer on any filter/search change (page <= 1) and appends,
// deduped, when a "load more" page arrives (page > 1).
const accumulatedSessions = ref([...(props.sessions?.data || [])]);
const sessionsMeta = ref({
    current_page: props.sessions?.current_page || 1,
    last_page: props.sessions?.last_page || 1,
    total: props.sessions?.total || 0,
});
const loadingMoreSessions = ref(false);

const mergeSessionsPage = (payload) => {
    if (!payload) return;
    const incoming = payload.data || [];
    if ((payload.current_page || 1) <= 1) {
        accumulatedSessions.value = [...incoming];
    } else {
        const seen = new Set(accumulatedSessions.value.map(session => session.id));
        accumulatedSessions.value = [
            ...accumulatedSessions.value,
            ...incoming.filter(session => !seen.has(session.id)),
        ];
    }
    sessionsMeta.value = {
        current_page: payload.current_page || 1,
        last_page: payload.last_page || 1,
        total: payload.total || 0,
    };
};

const hasMoreSessions = computed(() => sessionsMeta.value.current_page < sessionsMeta.value.last_page);

const sessionsShowingText = computed(() => {
    const total = sessionsMeta.value.total || 0;
    if (total === 0) return 'No records found';
    return `Showing ${accumulatedSessions.value.length} of ${total} sessions`;
});

const loadMoreSessions = () => {
    if (loadingMoreSessions.value || !hasMoreSessions.value) return;
    loadingMoreSessions.value = true;
    router.reload({
        only: ['sessions'],
        data: {
            search: search.value,
            sub_unit: filterSubUnit.value,
            store_id: filterStore.value,
            date_from: filterDateFrom.value,
            date_to: filterDateTo.value,
            perPage: props.sessions?.per_page,
            page: sessionsMeta.value.current_page + 1,
        },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { loadingMoreSessions.value = false; },
    });
};

// Merge every fresh session payload (filter reload -> replace, load-more -> append).
watch(() => props.sessions, (newSessions) => {
    mergeSessionsPage(newSessions);
}, { deep: true });

watch(search, (value) => {
    isLoading.value = true;
    router.get(route('attendance.logs'), { 
        search: value,
        sub_unit: filterSubUnit.value,
        store_id: filterStore.value,
        date_from: filterDateFrom.value,
        date_to: filterDateTo.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => isLoading.value = false
    });
});

const getGoogleMapsUrl = (lat, lng) => {
    return `https://www.google.com/maps?q=${lat},${lng}`;
};

const hasCoordinates = (event) => {
    return event?.latitude !== null
        && event?.latitude !== undefined
        && event?.longitude !== null
        && event?.longitude !== undefined
}

const formatSessionDate = (date) => {
    if (!date) return '—'
    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'Asia/Manila',
    }).format(new Date(`${date}T00:00:00+08:00`))
}

const formatEventTime = (event) => {
    if (!event?.log_time) return '—'
    return formatDate(event.log_time, {
        year: undefined,
        month: undefined,
        day: undefined,
        second: undefined,
    })
}

const eventDateKey = (event) => event?.log_time ? event.log_time.slice(0, 10) : null

const isNextDayTimeOut = (session) => {
    return !!session.time_out && eventDateKey(session.time_out) !== session.date
}

// Preview Modal State
const isPreviewOpen = ref(false);
const previewImage = ref(null);
const zoom = ref(1);
const position = reactive({ x: 0, y: 0 });
const isDragging = ref(false);
const startPos = reactive({ x: 0, y: 0 });

// Preview Functions
const openPreview = (photoPath) => {
    if (!photoPath) return;
    previewImage.value = '/serve-storage/' + photoPath;
    zoom.value = 1;
    position.x = 0;
    position.y = 0;
    isPreviewOpen.value = true;
};

const closePreview = () => {
    isPreviewOpen.value = false;
    previewImage.value = null;
};

const handleZoomIn = () => {
    zoom.value = Math.min(zoom.value + 0.1, 3);
};

const handleZoomOut = () => {
    zoom.value = Math.max(zoom.value - 0.1, 0.5);
};

const resetZoom = () => {
    zoom.value = 1;
    position.x = 0;
    position.y = 0;
};

const startDrag = (e) => {
    isDragging.value = true;
    startPos.x = e.clientX - position.x;
    startPos.y = e.clientY - position.y;
};

const onDrag = (e) => {
    if (!isDragging.value) return;
    position.x = e.clientX - startPos.x;
    position.y = e.clientY - startPos.y;
};

const stopDrag = () => {
    isDragging.value = false;
};

</script>

<template>
    <Head title="Attendance Logs" />

    <AppLayout>
        <template #header>
            Attendance History
        </template>

        <div class="space-y-6">
            <!-- Filter Bar (Prominent) -->
            <div class="bg-blue-600 rounded-xl shadow-lg p-1">
                <div class="bg-white rounded-lg p-4 dark:bg-gray-800">
                    <div class="flex flex-col lg:flex-row lg:items-center gap-6">
                        <div class="flex items-center gap-2 text-blue-600 font-bold shrink-0">
                            <FunnelIcon class="w-5 h-5" />
                            <span class="uppercase tracking-widest text-xs">Filters</span>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 flex-1">
                            <!-- Sub-Unit -->
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1 dark:text-gray-400">Sub-Unit</label>
                                <Autocomplete
                                    v-model="filterSubUnit"
                                    :options="subUnitOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="All Sub-Units"
                                />
                            </div>

                            <!-- Store -->
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1 dark:text-gray-400">Store</label>
                                <Autocomplete
                                    v-model="filterStore"
                                    :options="storeOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="All Stores"
                                />
                            </div>

                            <!-- Date From -->
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1 dark:text-gray-400">Date From</label>
                                <input 
                                    v-model="filterDateFrom" 
                                    type="date" 
                                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm h-[42px] dark:border-gray-600"
                                >
                            </div>

                            <!-- Date To -->
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1 dark:text-gray-400">Date To</label>
                                <input 
                                    v-model="filterDateTo" 
                                    type="date" 
                                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm h-[42px] dark:border-gray-600"
                                >
                            </div>
                        </div>

                        <button 
                            @click="clearFilters"
                            class="px-6 py-2 text-sm font-bold text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors h-[42px] border border-red-100"
                        >
                            Reset
                        </button>
                    </div>
                </div>
            </div>

            <!-- Corporate office monitoring -->
            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-5">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V7a2 2 0 00-2-2h-1V3H8v2H7a2 2 0 00-2 2v14m14 0H5m4-4h.01M9 13h.01M9 9h.01M15 17h.01M15 13h.01M15 9h.01" />
                                </svg>
                            </span>
                            <div>
                                <h2 class="text-base font-black text-gray-900 dark:text-gray-100">Corporate Office Attendance</h2>
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-300">Time In, Time Out, and open sessions for the selected filters.</p>
                            </div>
                        </div>
                    </div>
                    <button
                        v-if="selectedOfficeId"
                        type="button"
                        @click="clearOfficeFilter"
                        class="self-start rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-black text-blue-700 transition-colors hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300"
                    >
                        View all locations
                    </button>
                </div>

                <div
                    v-if="officeAttendanceSummary.length"
                    class="flex snap-x gap-3 overflow-x-auto pb-2 xl:grid xl:grid-cols-3 xl:overflow-visible xl:pb-0 2xl:grid-cols-4"
                >
                    <button
                        v-for="office in officeAttendanceSummary"
                        :key="office.id"
                        type="button"
                        @click="selectOffice(office.id)"
                        :aria-pressed="selectedOfficeId === office.id"
                        class="min-w-[250px] snap-start rounded-xl border p-4 text-left transition-all xl:min-w-0"
                        :class="selectedOfficeId === office.id
                            ? 'border-blue-500 bg-blue-50 shadow-md ring-2 ring-blue-100 dark:border-blue-400 dark:bg-blue-950/30 dark:ring-blue-900/60'
                            : 'border-gray-200 bg-gray-50/70 hover:-translate-y-0.5 hover:border-blue-300 hover:bg-white hover:shadow-md dark:border-gray-700 dark:bg-gray-900/40 dark:hover:border-blue-700 dark:hover:bg-gray-900'"
                    >
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-black text-gray-900 dark:text-gray-100">{{ office.name }}</p>
                                <p v-if="office.code" class="truncate text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-gray-400">{{ office.code }}</p>
                            </div>
                            <span v-if="selectedOfficeId === office.id" class="shrink-0 rounded-full bg-blue-600 px-2 py-0.5 text-[9px] font-black uppercase tracking-wide text-white">Selected</span>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="rounded-lg bg-emerald-50 px-2 py-2 text-center dark:bg-emerald-950/30">
                                <p class="text-lg font-black text-emerald-700 dark:text-emerald-300">{{ office.time_in_count }}</p>
                                <p class="text-[9px] font-black uppercase tracking-wide text-emerald-600 dark:text-emerald-400">Time In</p>
                            </div>
                            <div class="rounded-lg bg-orange-50 px-2 py-2 text-center dark:bg-orange-950/30">
                                <p class="text-lg font-black text-orange-700 dark:text-orange-300">{{ office.time_out_count }}</p>
                                <p class="text-[9px] font-black uppercase tracking-wide text-orange-600 dark:text-orange-400">Time Out</p>
                            </div>
                            <div class="rounded-lg bg-amber-50 px-2 py-2 text-center dark:bg-amber-950/30">
                                <p class="text-lg font-black text-amber-700 dark:text-amber-300">{{ office.open_count }}</p>
                                <p class="text-[9px] font-black uppercase tracking-wide text-amber-600 dark:text-amber-400">Open</p>
                            </div>
                        </div>
                    </button>
                </div>

                <div v-else class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm font-semibold text-gray-400 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                    No active corporate office locations found.
                </div>
            </section>

            <!-- Tab Bar (Ultra-Emphasis Segmented Control) -->
            <div class="inline-flex p-2 bg-slate-100 rounded-[2rem] shadow-inner mb-4 dark:bg-slate-800">
                <button
                    @click="activeTab = 'logs'"
                    :class="[
                        'inline-flex items-center gap-3 px-12 py-4 text-sm font-black transition-all duration-300 rounded-[1.75rem]',
                        activeTab === 'logs' 
                            ? 'bg-blue-600 text-white shadow-2xl shadow-blue-200 scale-[1.05] dark:shadow-blue-900/50' 
                            : 'text-slate-500 hover:text-slate-700 hover:bg-white dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-700'
                    ]"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span>Logs</span>
                </button>
                <button
                    @click="activeTab = 'work_hours'"
                    :class="[
                        'inline-flex items-center gap-3 px-12 py-4 text-sm font-black transition-all duration-300 rounded-[1.75rem]',
                        activeTab === 'work_hours' 
                            ? 'bg-blue-600 text-white shadow-2xl shadow-blue-200 scale-[1.05] dark:shadow-blue-900/50' 
                            : 'text-slate-500 hover:text-slate-700 hover:bg-white dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-700'
                    ]"
                >
                    <ClockIcon class="w-5 h-5" />
                    <span>Work Hours</span>
                </button>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3 dark:bg-gray-800 dark:border-gray-700">
                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1 dark:text-gray-400">Search Name</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-4 w-4 text-gray-400 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search employee name in Logs and Work Hours"
                        class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm h-[42px] pl-10 pr-4 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                </div>
            </div>

            <DataTable
                v-if="activeTab === 'logs'"
                title="Attendance Sessions"
                subtitle="Time In and Time Out are paired into a single row"
                v-model:search="search"
                :data="accumulatedSessions"
                :currentPage="sessionsMeta.current_page"
                :lastPage="sessionsMeta.last_page"
                :perPage="sessions.per_page"
                :showingText="sessionsShowingText"
                :isLoading="isLoading"
                :showSearch="false"
                infinite-scroll
                :has-more="hasMoreSessions"
                :loading-more="loadingMoreSessions"
                @load-more="loadMoreSessions"
            >
                <template #actions>
                    <Link
                        :href="route('attendance.index')"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-900 focus:outline-none focus:border-blue-900 focus:ring ring-blue-300 disabled:opacity-25 transition ease-in-out duration-150 whitespace-nowrap"
                    >
                        Log New Attendance
                    </Link>
                </template>

                <template #header>
                    <tr class="bg-gray-50 dark:bg-gray-900/50">
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Office / Store</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-emerald-700 uppercase tracking-wider dark:text-emerald-300">Time In</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-orange-700 uppercase tracking-wider dark:text-orange-300">Time Out</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="session in data" :key="session.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ formatSessionDate(session.date) }}</div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-gray-400">Workday</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ session.user?.name || 'Unknown employee' }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-300">{{ session.user?.email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ session.store?.name || 'Unassigned location' }}</div>
                            <div v-if="session.store?.code" class="text-[10px] font-bold text-blue-600 uppercase tracking-widest dark:text-blue-400">
                                CODE: {{ session.store.code }}
                            </div>
                        </td>
                        <td class="min-w-[260px] px-6 py-4">
                            <div v-if="session.time_in" class="flex items-center gap-3">
                                <button
                                    type="button"
                                    class="group relative h-12 w-12 shrink-0 rounded-lg"
                                    :class="session.time_in.photo_path ? 'cursor-pointer' : 'cursor-default'"
                                    :disabled="!session.time_in.photo_path"
                                    @click="openPreview(session.time_in.photo_path)"
                                >
                                    <img
                                        v-if="session.time_in.photo_path"
                                        :src="'/serve-storage/' + session.time_in.photo_path"
                                        class="h-12 w-12 rounded-lg border border-gray-200 object-cover shadow-sm transition-transform group-hover:scale-105 dark:border-gray-700"
                                        alt="Time In selfie"
                                    />
                                    <span v-else class="flex h-12 w-12 items-center justify-center rounded-lg border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-900/50" title="No Time In selfie">
                                        <UserCircleIcon class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                                    </span>
                                    <span v-if="session.time_in.photo_path" class="absolute inset-0 flex items-center justify-center rounded-lg bg-black/20 opacity-0 transition-opacity group-hover:opacity-100">
                                        <MagnifyingGlassPlusIcon class="h-5 w-5 text-white" />
                                    </span>
                                </button>
                                <div class="min-w-0">
                                    <p class="text-sm font-black text-emerald-700 dark:text-emerald-300">{{ formatEventTime(session.time_in) }}</p>
                                    <a
                                        v-if="hasCoordinates(session.time_in)"
                                        :href="getGoogleMapsUrl(session.time_in.latitude, session.time_in.longitude)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-1 inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                    >
                                        <MapPinIcon class="h-3.5 w-3.5" />
                                        <span>View map</span>
                                        <ArrowTopRightOnSquareIcon class="h-3 w-3" />
                                    </a>
                                    <span v-else class="mt-1 block text-[10px] font-semibold text-gray-400">No location captured</span>
                                </div>
                            </div>
                            <span v-else class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-black text-red-700 dark:bg-red-950/40 dark:text-red-300">Missing Time In</span>
                        </td>
                        <td class="min-w-[260px] px-6 py-4">
                            <div v-if="session.time_out" class="flex items-center gap-3">
                                <button
                                    type="button"
                                    class="group relative h-12 w-12 shrink-0 rounded-lg"
                                    :class="session.time_out.photo_path ? 'cursor-pointer' : 'cursor-default'"
                                    :disabled="!session.time_out.photo_path"
                                    @click="openPreview(session.time_out.photo_path)"
                                >
                                    <img
                                        v-if="session.time_out.photo_path"
                                        :src="'/serve-storage/' + session.time_out.photo_path"
                                        class="h-12 w-12 rounded-lg border border-gray-200 object-cover shadow-sm transition-transform group-hover:scale-105 dark:border-gray-700"
                                        alt="Time Out selfie"
                                    />
                                    <span v-else class="flex h-12 w-12 items-center justify-center rounded-lg border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-900/50" title="No Time Out selfie">
                                        <UserCircleIcon class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                                    </span>
                                    <span v-if="session.time_out.photo_path" class="absolute inset-0 flex items-center justify-center rounded-lg bg-black/20 opacity-0 transition-opacity group-hover:opacity-100">
                                        <MagnifyingGlassPlusIcon class="h-5 w-5 text-white" />
                                    </span>
                                </button>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <p class="text-sm font-black text-orange-700 dark:text-orange-300">{{ formatEventTime(session.time_out) }}</p>
                                        <span v-if="isNextDayTimeOut(session)" class="rounded-full bg-indigo-100 px-1.5 py-0.5 text-[9px] font-black uppercase text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">Next day</span>
                                    </div>
                                    <a
                                        v-if="hasCoordinates(session.time_out)"
                                        :href="getGoogleMapsUrl(session.time_out.latitude, session.time_out.longitude)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-1 inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                    >
                                        <MapPinIcon class="h-3.5 w-3.5" />
                                        <span>View map</span>
                                        <ArrowTopRightOnSquareIcon class="h-3 w-3" />
                                    </a>
                                    <span v-else class="mt-1 block text-[10px] font-semibold text-gray-400">No location captured</span>
                                </div>
                            </div>
                            <span v-else class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">Still clocked in</span>
                        </td>
                    </tr>
                </template>
            </DataTable>

            <!-- Work Hours Tab -->
            <div v-if="activeTab === 'work_hours'" class="bg-white rounded-b-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-base font-black text-gray-900 dark:text-gray-100">Work Hours Summary</h2>
                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-300">Based on scheduled shifts and actual time-in / time-out logs for the selected date range.</p>
                </div>

                <div v-if="!workHoursSummary || workHoursSummary.length === 0" class="px-6 py-12 text-center text-sm text-gray-400 dark:text-gray-400">
                    No scheduled or logged data found for the selected date range.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">User</th>
                                <th class="px-6 py-3 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Sched. Days</th>
                                <th class="px-6 py-3 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Present</th>
                                <th class="px-6 py-3 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Absent</th>
                                <th class="px-6 py-3 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Scheduled Hrs</th>
                                <th class="px-6 py-3 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Actual Hrs</th>
                                <th class="px-6 py-3 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Variance</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-50 dark:bg-gray-800">
                            <tr v-for="row in workHoursSummary" :key="row.user_id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                                <td class="px-6 py-3 text-sm font-bold text-gray-900 dark:text-gray-100">{{ row.name }}</td>
                                <td class="px-6 py-3 text-center">
                                    <button @click="openDetail(row, 'all')" class="text-sm font-bold text-blue-600 hover:underline cursor-pointer">
                                        {{ row.scheduled_days }}
                                    </button>
                                </td>
                                <td class="px-6 py-3 text-center">
                                    <button @click="openDetail(row, 'present')" class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-black text-emerald-700 hover:bg-emerald-200 cursor-pointer">
                                        {{ row.days_present }}
                                    </button>
                                </td>
                                <td class="px-6 py-3 text-center">
                                    <button
                                        @click="openDetail(row, 'absent')"
                                        :class="(row.scheduled_days - row.days_present) > 0 ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-gray-100 text-gray-400 hover:bg-gray-200'"
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-black cursor-pointer"
                                    >
                                        {{ row.scheduled_days - row.days_present }}
                                    </button>
                                </td>
                                <td class="px-6 py-3 text-right text-sm font-bold text-gray-700 dark:text-gray-300">
                                    {{ formatMinutes(row.scheduled_minutes) }}
                                </td>
                                <td class="px-6 py-3 text-right text-sm font-bold text-gray-900 dark:text-gray-100">
                                    {{ formatMinutes(row.actual_minutes) }}
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <span
                                        :class="(row.actual_minutes - row.scheduled_minutes) >= 0 ? 'text-emerald-600' : 'text-red-600'"
                                        class="text-sm font-black"
                                    >
                                        {{ (row.actual_minutes - row.scheduled_minutes) >= 0 ? '+' : '−' }}{{ formatMinutes(row.actual_minutes - row.scheduled_minutes) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Work Hours Detail Modal -->
        <Modal :show="detailModal" @close="detailModal = false" max-width="4xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-black text-gray-900 dark:text-gray-100">{{ detailUser }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-300">
                            <span v-if="detailMode === 'all'">All scheduled dates</span>
                            <span v-else-if="detailMode === 'present'">Days present</span>
                            <span v-else>Days absent</span>
                        </p>
                    </div>
                    <!-- Mode toggle -->
                    <div class="flex gap-1 rounded-lg border border-gray-200 p-1 text-xs font-bold dark:border-gray-700">
                        <button @click="detailMode = 'all'"     :class="detailMode === 'all'     ? 'bg-blue-600 text-white' : 'text-gray-500 hover:bg-gray-100'" class="px-3 py-1 rounded-md transition-colors">All</button>
                        <button @click="detailMode = 'present'" :class="detailMode === 'present' ? 'bg-emerald-600 text-white' : 'text-gray-500 hover:bg-gray-100'" class="px-3 py-1 rounded-md transition-colors">Present</button>
                        <button @click="detailMode = 'absent'"  :class="detailMode === 'absent'  ? 'bg-red-600 text-white' : 'text-gray-500 hover:bg-gray-100'" class="px-3 py-1 rounded-md transition-colors">Absent</button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Date</th>
                                <th class="px-4 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Sched. Start</th>
                                <th class="px-4 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Sched. End</th>
                                <th class="px-4 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Actual Time In</th>
                                <th class="px-4 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Actual Time Out</th>
                                <th class="px-4 py-2 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Work Hours</th>
                                <th class="px-4 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 bg-white dark:bg-gray-800">
                            <tr v-if="filteredDetailRows.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-gray-400 text-xs dark:text-gray-400">No records.</td>
                            </tr>
                            <tr v-for="d in filteredDetailRows" :key="d.date" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-2 font-bold text-gray-900 dark:text-gray-100">{{ d.date }}</td>
                                <td class="px-4 py-2 text-center text-gray-600 dark:text-gray-300">{{ formatTime(d.scheduled_start) }}</td>
                                <td class="px-4 py-2 text-center text-gray-600 dark:text-gray-300">{{ formatTime(d.scheduled_end) }}</td>
                                <td class="px-4 py-2 text-center font-bold" :class="d.actual_time_in ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-300 dark:text-slate-400'">
                                    {{ formatTime(d.actual_time_in) }}
                                </td>
                                <td class="px-4 py-2 text-center font-bold" :class="d.actual_time_out ? 'text-blue-700 dark:text-blue-300' : 'text-gray-300 dark:text-slate-400'">
                                    {{ formatTime(d.actual_time_out) }}
                                </td>
                                <td class="px-4 py-2 text-right font-bold" :class="d.actual_minutes !== null && d.actual_minutes !== undefined ? 'text-gray-900 dark:text-slate-100' : 'text-gray-300 dark:text-slate-400'">
                                    {{ formatDetailWorkHours(d.actual_minutes) }}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <span v-if="d.is_present" class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-black text-emerald-700">Present</span>
                                    <span v-else class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-black text-red-700">Absent</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex justify-end">
                    <SecondaryButton @click="detailModal = false">Close</SecondaryButton>
                </div>
            </div>
        </Modal>

        <!-- Selfie Preview Modal -->
        <Modal :show="isPreviewOpen" @close="closePreview" maxWidth="2xl">
            <div class="p-4 sm:p-6">
                <div class="flex items-center justify-between mb-4 border-b pb-3">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Selfie Preview</h3>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600 dark:text-gray-400">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div 
                    class="relative bg-gray-900 rounded-xl overflow-hidden h-[500px] flex items-center justify-center cursor-move select-none"
                    @mousedown="startDrag"
                    @mousemove="onDrag"
                    @mouseup="stopDrag"
                    @mouseleave="stopDrag"
                >
                    <img 
                        :src="previewImage" 
                        class="max-w-none transition-transform duration-75"
                        :style="{ 
                            transform: `translate(${position.x}px, ${position.y}px) scale(${zoom})`,
                            pointerEvents: isDragging ? 'none' : 'auto'
                        }"
                        alt="Enlarged Selfie"
                        draggable="false"
                    />
                    
                    <!-- Zoom Controls Overlay -->
                    <div class="absolute bottom-4 right-4 flex flex-col gap-2">
                        <button 
                            @click.stop="handleZoomIn" 
                            class="p-2 bg-white/20 hover:bg-white/40 backdrop-blur-md rounded-lg text-white transition-colors"
                            title="Zoom In"
                        >
                            <MagnifyingGlassPlusIcon class="w-6 h-6" />
                        </button>
                        <button 
                            @click.stop="handleZoomOut" 
                            class="p-2 bg-white/20 hover:bg-white/40 backdrop-blur-md rounded-lg text-white transition-colors"
                            title="Zoom Out"
                        >
                            <MagnifyingGlassMinusIcon class="w-6 h-6" />
                        </button>
                        <button 
                            @click.stop="resetZoom" 
                            class="p-2 bg-white/20 hover:bg-white/40 backdrop-blur-md rounded-lg text-white transition-colors"
                            title="Reset"
                        >
                            <ArrowsPointingOutIcon class="w-6 h-6" />
                        </button>
                    </div>

                    <div class="absolute top-4 left-4 bg-black/50 px-3 py-1 rounded-full text-white text-xs font-bold backdrop-blur-sm">
                        Zoom: {{ Math.round(zoom * 100) }}%
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closePreview">Close</SecondaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Prevent image ghosting while dragging */
img {
    -webkit-user-drag: none;
    user-select: none;
}
</style>
