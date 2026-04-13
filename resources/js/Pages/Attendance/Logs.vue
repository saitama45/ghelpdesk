<script setup>
import { ref, watch, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useDateFormatter } from '@/Composables/useDateFormatter';
import { MapPinIcon, ClockIcon, ArrowTopRightOnSquareIcon, MagnifyingGlassPlusIcon, MagnifyingGlassMinusIcon, ArrowsPointingOutIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    logs: Object
});

const { formatDate } = useDateFormatter();
const search = ref('');
const isLoading = ref(false);

// Preview Modal State
const isPreviewOpen = ref(false);
const previewImage = ref(null);
const zoom = ref(1);
const position = reactive({ x: 0, y: 0 });
const isDragging = ref(false);
const startPos = reactive({ x: 0, y: 0 });

const goToPage = (page) => {
    isLoading.value = true;
    router.get(route('attendance.logs'), { page, search: search.value }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => isLoading.value = false
    });
};

const changePerPage = (perPage) => {
    isLoading.value = true;
    router.get(route('attendance.logs'), { perPage, search: search.value }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => isLoading.value = false
    });
};

watch(search, (value) => {
    isLoading.value = true;
    router.get(route('attendance.logs'), { search: value }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => isLoading.value = false
    });
});

const getGoogleMapsUrl = (lat, lng) => {
    return `https://www.google.com/maps?q=${lat},${lng}`;
};

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
                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-900 focus:outline-none focus:border-blue-900 focus:ring ring-blue-300 disabled:opacity-25 transition ease-in-out duration-150"
                >
                    Log New Attendance
                </Link>
            </template>

            <template #header>
                <tr class="bg-gray-50">
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Selfie</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
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
