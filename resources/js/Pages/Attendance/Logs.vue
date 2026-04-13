<script setup>
import { ref, watch, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useDateFormatter } from '@/Composables/useDateFormatter';
import { MapPinIcon, ClockIcon, ArrowTopRightOnSquareIcon, MagnifyingGlassPlusIcon, MagnifyingGlassMinusIcon, ArrowsPointingOutIcon, XMarkIcon, FunnelIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    logs: Object,
    users: Array,
    stores: Array,
    filters: Object,
});

const { formatDate } = useDateFormatter();
const search = ref(props.filters?.search || '');
const isLoading = ref(false);

// Filters
const filterSubUnit = ref(props.filters?.sub_unit || '');
const filterStore = ref(props.filters?.store_id || '');
const filterDateFrom = ref(props.filters?.date_from || '');
const filterDateTo = ref(props.filters?.date_to || '');

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

const goToPage = (page) => {
    isLoading.value = true;
    router.get(route('attendance.logs'), { 
        page, 
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

const changePerPage = (perPage) => {
    isLoading.value = true;
    router.get(route('attendance.logs'), { 
        perPage, 
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

// Preview Modal State
const isPreviewOpen = ref(false);
const previewImage = ref(null);
const zoom = ref(1);
const position = reactive({ x: 0, y: 0 });
const isDragging = ref(false);
const startPos = reactive({ x: 0, y: 0 });

// Preview Functions
const openPreview = (photoPath) => {
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
                <div class="bg-white rounded-lg p-4">
                    <div class="flex flex-col lg:flex-row lg:items-center gap-6">
                        <div class="flex items-center gap-2 text-blue-600 font-bold shrink-0">
                            <FunnelIcon class="w-5 h-5" />
                            <span class="uppercase tracking-widest text-xs">Filters</span>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 flex-1">
                            <!-- Sub-Unit -->
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1">Sub-Unit</label>
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
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1">Store</label>
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
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1">Date From</label>
                                <input 
                                    v-model="filterDateFrom" 
                                    type="date" 
                                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm h-[42px]"
                                >
                            </div>

                            <!-- Date To -->
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1">Date To</label>
                                <input 
                                    v-model="filterDateTo" 
                                    type="date" 
                                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm h-[42px]"
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

            <DataTable
                title="Logs"
                subtitle="View your time-in and time-out history"
                v-model:search="search"
                :data="logs.data"
                :currentPage="logs.current_page"
                :lastPage="logs.last_page"
                :perPage="logs.per_page"
                :showingText="`Showing ${logs.from} to ${logs.to} of ${logs.total} results`"
                :isLoading="isLoading"
                @goToPage="goToPage"
                @changePerPage="changePerPage"
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
                    <tr class="bg-gray-50">
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Selfie</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Store</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Log Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Device</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="log in data" :key="log.id" class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex-shrink-0 h-12 w-12 cursor-pointer group relative" @click="openPreview(log.photo_path)">
                                <img 
                                    :src="'/serve-storage/' + log.photo_path" 
                                    class="h-12 w-12 rounded-lg object-cover border border-gray-200 shadow-sm transition-transform group-hover:scale-105"
                                    alt="Selfie"
                                />
                                <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 rounded-lg flex items-center justify-center transition-opacity">
                                    <MagnifyingGlassPlusIcon class="w-5 h-5 text-white" />
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-semibold text-gray-900">{{ log.user?.name }}</div>
                            <div class="text-xs text-gray-500">{{ log.user?.email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-blue-600">{{ log.schedule_store?.store?.name || log.schedule?.store?.name || '-' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-gray-900">{{ formatDate(log.log_time, { year: undefined, month: undefined, day: undefined }) }}</span>
                                <span class="text-xs text-gray-500">{{ formatDate(log.log_time, { hour: undefined, minute: undefined, second: undefined }) }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span 
                                :class="[
                                    'px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full uppercase tracking-tighter',
                                    log.type === 'time_in' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800'
                                ]"
                            >
                                {{ log.type === 'time_in' ? 'In' : 'Out' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <a 
                                :href="getGoogleMapsUrl(log.latitude, log.longitude)" 
                                target="_blank"
                                class="inline-flex items-center text-blue-600 hover:text-blue-800 gap-1 font-medium transition-colors"
                            >
                                <MapPinIcon class="w-4 h-4" />
                                <span>View on Map</span>
                                <ArrowTopRightOnSquareIcon class="w-3 h-3" />
                            </a>
                        </td>
                        <td class="px-6 py-4 text-xs text-gray-400 min-w-[220px]">
                            {{ log.device_info || 'Unknown Device' }}
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>

        <!-- Selfie Preview Modal -->
        <Modal :show="isPreviewOpen" @close="closePreview" maxWidth="2xl">
            <div class="p-4 sm:p-6">
                <div class="flex items-center justify-between mb-4 border-b pb-3">
                    <h3 class="text-lg font-bold text-gray-900">Selfie Preview</h3>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600">
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
