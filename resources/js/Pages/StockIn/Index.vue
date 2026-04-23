<template>
    <AppLayout title="Stock In">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Stock In Headers"
                    subtitle="Manage grouped stock-in header records and their quantity"
                    search-placeholder="Search by serial no..."
                    :search="pagination.search.value"
                    :data="groupedStockIns"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="pagination.showingText.value"
                    :is-loading="pagination.isLoading.value"
                    @update:search="pagination.search.value = $event"
                    @go-to-page="pagination.goToPage"
                    @change-per-page="pagination.changePerPage"
                >
                    <template #actions>
                        <button 
                            v-if="permissions.create"
                            @click="openCreateModal" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <PlusIcon class="w-4 h-4" />
                            <span>Add Stock</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receive Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">DR / Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Header Record</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="item in data" :key="item.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ formatDate(item.receive_date) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <div class="flex flex-col">
                                    <span class="font-semibold text-gray-900">{{ item.dr_no || '-' }}</span>
                                    <span class="mt-1 inline-flex w-fit items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                                          :class="item.status === 'Posted' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                                        {{ item.status || 'For Posting' }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">{{ item.asset?.item_code }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="flex flex-col">
                                    <div class="font-semibold text-gray-900">
                                        {{ [item.asset?.brand, item.asset?.model].filter(Boolean).join(' ') || 'Unnamed Stock Header' }}
                                    </div>
                                    <div class="text-xs text-gray-500 max-w-md truncate" :title="item.asset?.description">
                                        {{ item.asset?.description || 'No description' }}
                                    </div>
                                    <div class="mt-1 text-[11px] text-gray-500">
                                        Vendor: {{ item.vendor || '-' }} | Origin: {{ item.origin_location || '-' }} | Destination: {{ item.destination_location || item.location || '-' }}
                                    </div>
                                    <div class="mt-2 text-[11px] text-gray-500">
                                        {{ item.record_count }} row<span v-if="item.record_count !== 1">s</span> grouped in this header
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 text-slate-800 text-xs font-bold">
                                    {{ item.quantity }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="permissions.edit" 
                                        @click="editHeaderItem(item)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Modal -->
        <Modal :show="showModal" @close="closeModal" max-width="4xl">
            <div class="p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">{{ isEditing ? 'Edit Stock In' : 'Add Stock In' }}</h3>
                <form @submit.prevent="submitForm()" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Receive Date</label>
                            <input type="date" v-model="form.receive_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">DR No</label>
                            <input type="text" v-model="form.dr_no" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">DR Date</label>
                            <input type="date" v-model="form.dr_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Vendor</label>
                            <Autocomplete
                                v-model="form.vendor"
                                :options="vendorOptions"
                                label-key="name"
                                value-key="value"
                                placeholder="Select Vendor"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Origin Location</label>
                            <Autocomplete
                                v-model="form.origin_location"
                                :options="storeOptions"
                                label-key="name"
                                value-key="value"
                                placeholder="Select Origin Store"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Received by</label>
                            <input type="text" v-model="form.received_by" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Posted by</label>
                            <input type="text" v-model="form.posted_by" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Brand</label>
                            <Autocomplete
                                v-model="form.brand"
                                :options="brandOptions"
                                label-key="name"
                                value-key="value"
                                placeholder="Select Brand"
                                @update:modelValue="onBrandChange"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Model</label>
                            <Autocomplete
                                v-model="form.model"
                                :options="modelOptions"
                                label-key="name"
                                value-key="value"
                                placeholder="Select Model"
                                :disabled="!form.brand"
                                @update:modelValue="onModelChange"
                            />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Asset Item</label>
                            <Autocomplete
                                v-model="form.asset_id"
                                :options="assetOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Select Asset Item"
                                :disabled="!form.brand || !form.model"
                                @update:modelValue="onAssetChange"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Qty</label>
                            <input
                                type="number"
                                v-model.number="form.quantity"
                                required
                                min="1"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                            >
                            <p class="mt-1 text-[11px] text-gray-500">
                                {{ isEditing ? 'Qty updates how many grouped stock-in detail rows are kept below.' : 'Qty controls how many detail rows are prepared below.' }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50/70 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-white">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Stock Details</h4>
                                <p class="text-xs text-gray-500">Add serial, codes, pricing, warranty, and location per unit.</p>
                            </div>
                            <span class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">{{ form.entries.length }} row<span v-if="form.entries.length !== 1">s</span></span>
                        </div>

                        <div class="max-h-[55vh] overflow-y-auto p-4 space-y-4">
                            <div
                                v-for="(entry, index) in form.entries"
                                :key="entry.uid"
                                class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                            >
                                <div class="flex items-center justify-between mb-4">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">Item {{ index + 1 }}</p>
                                        <p class="text-xs text-gray-500">{{ selectedAssetLabel || 'Select an asset item above first.' }}</p>
                                    </div>
                                    <button
                                        v-if="!isEditing && form.entries.length > 1"
                                        type="button"
                                        @click="removeEntry(index)"
                                        class="text-xs font-semibold text-red-600 hover:text-red-700"
                                    >
                                        Remove
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Serial No</label>
                                        <input type="text" v-model="entry.serial_no" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destination Location</label>
                                        <Autocomplete
                                            v-model="entry.destination_location"
                                            :options="storeOptions"
                                            label-key="name"
                                            value-key="value"
                                            placeholder="Select Destination Store"
                                            size="sm"
                                        />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-4">
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Barcode Generated</label>
                                        <div class="flex rounded-md shadow-sm">
                                            <input type="text" v-model="entry.barcode" class="block w-full rounded-none rounded-l-md border-gray-300 focus:ring-blue-500 focus:border-blue-500 text-sm">
                                            <button type="button" @click="generateBarcode(index)" class="inline-flex items-center px-4 rounded-r-md border border-l-0 border-gray-300 bg-white text-blue-600 text-xs font-bold hover:bg-gray-50 uppercase tracking-widest transition-colors">Gen</button>
                                        </div>
                                        <div v-if="entry.barcode" class="mt-3 p-4 bg-white border border-gray-200 rounded-lg flex justify-center cursor-pointer hover:border-blue-300 transition-all shadow-sm"
                                             @click="openImageViewer(`https://barcode.tec-it.com/barcode.ashx?data=${encodeURIComponent(entry.barcode)}&code=Code128`, `Barcode: ${entry.barcode}`)">
                                            <img :src="`https://bwipjs-api.metafloor.com/?bcid=code128&text=${encodeURIComponent(entry.barcode)}&scale=1&height=10&includetext`" class="max-h-12" :alt="entry.barcode">
                                        </div>
                                    </div>

                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">QR Code Generated</label>
                                        <div class="flex rounded-md shadow-sm">
                                            <input type="text" v-model="entry.qrcode" class="block w-full rounded-none rounded-l-md border-gray-300 focus:ring-blue-500 focus:border-blue-500 text-sm" placeholder="Generate summary for scanning...">
                                            <button type="button" @click="generateQrcode(index)" class="inline-flex items-center px-4 rounded-r-md border border-l-0 border-gray-300 bg-white text-blue-600 text-xs font-bold hover:bg-gray-50 uppercase tracking-widest transition-colors">Gen</button>
                                        </div>
                                        <div v-if="entry.qrcode" class="mt-3 p-4 bg-white border border-gray-200 rounded-lg flex justify-center cursor-pointer hover:border-blue-300 transition-all shadow-sm"
                                             @click="openImageViewer(`https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(entry.qrcode)}`, `QR Code Summary`)">
                                            <img :src="`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(entry.qrcode)}`" class="w-24 h-24" :alt="entry.qrcode">
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mt-4">
                                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Warranty (Months)</label>
                                        <input type="number" v-model.number="entry.warranty_months" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        <p class="mt-1 text-[10px] text-blue-600 font-medium italic">Computed: {{ computedWarrantyDate(entry) }}</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">EOL (Months)</label>
                                        <input type="number" v-model.number="entry.eol_months" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        <p class="mt-1 text-[10px] text-blue-600 font-medium italic">Computed: {{ computedEolDate(entry) }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Cost</label>
                                        <input type="number" step="0.01" v-model.number="entry.cost" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Price</label>
                                        <input type="number" step="0.01" v-model.number="entry.price" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" @click="closeModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50">Cancel</button>
                        <button
                            v-if="permissions.post"
                            type="button"
                            @click="submitForm('Posted')"
                            class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 border border-transparent rounded-md shadow-sm hover:bg-emerald-700"
                        >
                            Post
                        </button>
                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700">
                            {{ isEditing ? 'Update' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Image Viewer Modal -->
        <Modal :show="showImageViewer" max-width="4xl" @close="closeImageViewer">
            <div class="relative bg-black rounded-lg overflow-hidden h-[80vh] flex flex-col">
                <!-- Toolbar -->
                <div class="absolute top-0 left-0 right-0 z-10 flex justify-between items-center p-4 bg-gradient-to-b from-black/50 to-transparent">
                    <h3 class="text-white text-xs sm:text-sm font-medium truncate ml-2 text-shadow">{{ viewerTitle }}</h3>
                    <div class="flex items-center space-x-2">
                        <button @click="handleZoom(-0.1)" class="p-1 sm:p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" /></svg>
                        </button>
                        <span class="text-white text-[10px] sm:text-sm font-mono w-8 sm:w-12 text-center">{{ Math.round(zoomLevel * 100) }}%</span>
                        <button @click="handleZoom(0.1)" class="p-1 sm:p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                        </button>
                        <div class="w-px h-6 bg-white/30 mx-1 sm:mx-2"></div>
                        <button @click="closeImageViewer" class="p-1 sm:p-2 text-white hover:bg-red-500/80 rounded-full backdrop-blur-sm transition-colors">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Image Container -->
                <div class="flex-grow flex items-center justify-center overflow-hidden cursor-move p-4 relative" 
                     @mousedown.prevent="isDragging = true" 
                     @mouseup="isDragging = false" 
                     @mouseleave="isDragging = false"
                     @mousemove="isDragging && (panOffset.x += $event.movementX, panOffset.y += $event.movementY)"
                     @wheel.prevent="handleWheel">
                    
                    <div v-if="viewerLoading" class="absolute inset-0 flex items-center justify-center">
                        <svg class="animate-spin h-10 w-10 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>

                    <template v-if="viewerImageUrl">
                        <img :src="viewerImageUrl" 
                             class="transition-transform duration-100 ease-linear transform origin-center max-w-none shadow-2xl bg-white p-6 rounded-lg" 
                             :style="{ transform: `scale(${zoomLevel}) translate(${panOffset.x / zoomLevel}px, ${panOffset.y / zoomLevel}px)` }" 
                             @load="viewerLoading = false"
                             @error="handleViewerError"
                             draggable="false">
                    </template>
                    
                    <div v-if="viewerError" class="bg-red-50 p-4 rounded-lg text-red-700 text-sm font-bold flex flex-col items-center">
                        <svg class="w-10 h-10 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        Failed to load image. Please check the code content or your internet connection.
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { PlusIcon } from '@heroicons/vue/24/outline'
import { usePagination } from '@/Composables/usePagination'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'

const props = defineProps({
    stockIns: Object,
    assets: Array,
    stores: Array,
    vendors: Array,
    permissions: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const pagination = usePagination(props.stockIns, 'stock-ins.index')

const normalizedAssets = computed(() => {
    return (props.assets || []).map(asset => ({
        ...asset,
        brand: asset.brand || 'Unbranded',
        model: asset.model || 'Unspecified Model',
    }))
})

const brandOptions = computed(() => {
    return [...new Set(normalizedAssets.value.map(asset => asset.brand))]
        .sort((a, b) => a.localeCompare(b))
        .map(value => ({ value, name: value }))
})

const modelOptions = computed(() => {
    return [...new Set(
        normalizedAssets.value
            .filter(asset => asset.brand === form.brand)
            .map(asset => asset.model)
    )]
        .sort((a, b) => a.localeCompare(b))
        .map(value => ({ value, name: value }))
})

const assetOptions = computed(() => {
    return normalizedAssets.value
        .filter(asset => asset.brand === form.brand && asset.model === form.model)
        .map(asset => ({
            id: asset.id,
            name: `${asset.item_code} - ${asset.description || asset.model}`
        }))
})

const storeOptions = computed(() => {
    return (props.stores || []).map(store => ({
        value: store.code,
        name: [store.code, store.name].filter(Boolean).join(' - ')
    }))
})
const vendorOptions = computed(() => {
    return (props.vendors || []).map(vendor => ({
        value: vendor.name,
        name: [vendor.code, vendor.name].filter(Boolean).join(' - ')
    }))
})

const normalizeLocationValue = (value) => {
    if (!value) return ''

    const matchedStore = (props.stores || []).find(store =>
        store.code === value || store.name === value
    )

    return matchedStore?.code || value
}

const showModal = ref(false)
const isEditing = ref(false)
const currentId = ref(null)

const getToday = () => new Date().toISOString().split('T')[0]

const form = reactive({
    receive_date: getToday(),
    dr_no: '',
    dr_date: getToday(),
    vendor: '',
    origin_location: '',
    received_by: '',
    posted_by: '',
    status: 'For Posting',
    brand: '',
    model: '',
    asset_id: '',
    quantity: 1,
    entries: []
})

let entryUid = 0

const createEntry = (overrides = {}) => ({
    uid: `entry-${entryUid++}`,
    serial_no: '',
    barcode: '',
    qrcode: '',
    warranty_months: 12,
    eol_months: 60,
    cost: 0,
    price: 0,
    destination_location: '',
    ...overrides
})

const selectedAsset = computed(() => normalizedAssets.value.find(asset => asset.id == form.asset_id) || null)
const selectedAssetLabel = computed(() => selectedAsset.value ? `${selectedAsset.value.item_code} - ${selectedAsset.value.description || selectedAsset.value.model}` : '')
const toDateKey = (value) => {
    if (!value) return ''
    if (typeof value === 'string') return value.slice(0, 10)
    return new Date(value).toISOString().slice(0, 10)
}

const groupedStockIns = computed(() => {
    const groups = new Map()

    for (const row of pagination.data.value || []) {
        const receiveDateKey = toDateKey(row.receive_date)
        const key = `${row.asset_id || `row-${row.id}`}-${receiveDateKey}`
        const existing = groups.get(key)

        if (!existing) {
            groups.set(key, {
                ...row,
                quantity: Number(row.quantity || 0),
                record_count: 1,
                latestRecord: row,
                relatedRows: [row],
                receive_date: receiveDateKey,
            })
            continue
        }

        existing.quantity += Number(row.quantity || 0)
        existing.record_count += 1
        existing.relatedRows.push(row)

        if (new Date(row.receive_date) > new Date(existing.receive_date)) {
            existing.latestRecord = row
            existing.id = row.id
        }
    }

    return Array.from(groups.values())
})

const syncEntriesToQuantity = (quantity) => {
    const target = Math.max(1, parseInt(quantity || 1, 10))
    form.quantity = target

    if (!form.entries.length) {
        form.entries = [createEntry(isEditing.value ? {} : getEntryDefaults())]
    }

    while (form.entries.length < target) {
        form.entries.push(createEntry(getEntryDefaults()))
    }

    while (form.entries.length > target) {
        form.entries.pop()
    }
}

const getEntryDefaults = () => ({
    cost: selectedAsset.value?.cost || 0,
    price: 0,
    warranty_months: 12,
    eol_months: 60,
})

const onBrandChange = () => {
    form.model = ''
    form.asset_id = ''
    form.entries = form.entries.map(entry => ({
        ...entry,
        barcode: '',
        qrcode: '',
    }))
}

const onModelChange = () => {
    form.asset_id = ''
    form.entries = form.entries.map(entry => ({
        ...entry,
        barcode: '',
        qrcode: '',
    }))
}

const onAssetChange = () => {
    const defaults = getEntryDefaults()
    form.entries = form.entries.map(entry => ({
        ...entry,
        cost: entry.cost || defaults.cost,
        price: entry.price || defaults.price,
    }))
}

const generateBarcode = (index) => {
    const asset = selectedAsset.value
    const prefix = asset ? asset.item_code : 'ST'
    const entry = form.entries[index]
    if (!entry) return
    entry.barcode = `${prefix}-${Date.now()}-${index + 1}`
}

const generateQrcode = (index) => {
    const asset = selectedAsset.value
    const entry = form.entries[index]
    if (!asset) {
        showError('Please select an asset first');
        return
    }

    const details = [
        `Item Code: ${asset.item_code}`,
        `Description: ${asset.description || 'N/A'}`,
        `Brand: ${asset.brand || 'N/A'}`,
        `Model: ${asset.model || 'N/A'}`,
        `Serial No: ${entry?.serial_no || 'N/A'}`,
        `Barcode: ${entry?.barcode || 'N/A'}`,
        `Warranty: ${computedWarrantyDate(entry)}`,
        `EOL: ${computedEolDate(entry)}`,
        `Destination: ${entry?.destination_location || 'N/A'}`
    ]
    
    entry.qrcode = details.join('\n')
}

const addMonths = (dateStr, months) => {
    if (!dateStr) return null;
    const date = new Date(dateStr);
    date.setMonth(date.getMonth() + parseInt(months));
    return date;
}

const formatDate = (date) => {
    if (!date) return '-';
    return new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', year: 'numeric' }).format(new Date(date));
}

const computedWarrantyDate = (entry) => {
    const date = addMonths(form.receive_date, entry?.warranty_months)
    return date ? formatDate(date) : '-'
}

const computedEolDate = (entry) => {
    const date = addMonths(form.receive_date, entry?.eol_months)
    return date ? formatDate(date) : '-'
}

const resetForm = () => {
    Object.assign(form, {
        receive_date: getToday(),
        dr_no: '',
        dr_date: getToday(),
        vendor: '',
        origin_location: '',
        received_by: '',
        posted_by: '',
        status: 'For Posting',
        brand: '',
        model: '',
        asset_id: '',
        quantity: 1,
        entries: [createEntry()],
    })
}

const openCreateModal = () => {
    isEditing.value = false
    currentId.value = null
    resetForm()
    showModal.value = true
}

const editHeaderItem = (header) => {
    const target = header.latestRecord || header
    editItem(target, header.quantity, header.relatedRows || [target])
}

const editItem = (item, aggregatedQuantity = item.quantity, relatedRows = [item]) => {
    const asset = normalizedAssets.value.find(a => a.id == item.asset_id)
    isEditing.value = true
    currentId.value = item.id
    Object.assign(form, {
        receive_date: toDateKey(item.receive_date),
        dr_no: item.dr_no || '',
        dr_date: toDateKey(item.dr_date),
        vendor: item.vendor || '',
        origin_location: normalizeLocationValue(item.origin_location),
        received_by: item.received_by || '',
        posted_by: item.posted_by || '',
        status: item.status || 'For Posting',
        brand: asset?.brand || '',
        model: asset?.model || '',
        asset_id: item.asset_id,
        quantity: aggregatedQuantity,
        entries: relatedRows.map(row => createEntry({
            serial_no: row.serial_no,
            barcode: row.barcode || '',
            qrcode: row.qrcode || '',
            warranty_months: row.warranty_months,
            eol_months: row.eol_months,
            cost: Number(row.cost || 0),
            price: Number(row.price || 0),
            destination_location: normalizeLocationValue(row.destination_location || row.location),
        }))
    })
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = (statusOverride = form.status || 'For Posting') => {
    const url = isEditing.value ? route('stock-ins.update', currentId.value) : route('stock-ins.store')
    const method = isEditing.value ? 'put' : 'post'
    const payload = {
        receive_date: form.receive_date,
        dr_no: form.dr_no,
        dr_date: form.dr_date || null,
        vendor: form.vendor,
        origin_location: form.origin_location || null,
        received_by: form.received_by,
        posted_by: form.posted_by,
        status: statusOverride,
        brand: form.brand,
        model: form.model,
        asset_id: form.asset_id,
        quantity: form.quantity,
        entries: form.entries.map(({ uid, ...entry }) => ({
            ...entry,
        })),
    }

    if (isEditing.value) {
        Object.assign(payload, {
            header_mode: true,
            quantity: form.quantity,
        })
    }

    router[method](url, payload, {
        onSuccess: () => {
            closeModal()
        },
        onError: (errors) => {
            showError(Object.values(errors)[0])
        }
    })
}

const removeEntry = (index) => {
    if (isEditing.value || form.entries.length === 1) return
    form.entries.splice(index, 1)
    form.quantity = form.entries.length
}

const deleteItem = (item) => {
    confirm({
        title: 'Delete Stock In',
        message: 'Are you sure you want to delete this record?',
        onConfirm: () => {
            router.delete(route('stock-ins.destroy', item.id))
        }
    })
}

// Image Viewer State
const showImageViewer = ref(false);
const viewerImageUrl = ref(null);
const viewerTitle = ref('');
const zoomLevel = ref(1);
const isDragging = ref(false);
const panOffset = ref({ x: 0, y: 0 });
const viewerLoading = ref(false);
const viewerError = ref(false);

const openImageViewer = (url, title) => {
    viewerError.value = false;
    viewerLoading.value = true;
    viewerImageUrl.value = url;
    viewerTitle.value = title;
    zoomLevel.value = 1;
    panOffset.value = { x: 0, y: 0 };
    showImageViewer.value = true;
};

const closeImageViewer = () => {
    showImageViewer.value = false;
    viewerImageUrl.value = null;
    viewerLoading.value = false;
    viewerError.value = false;
};

const handleViewerError = () => {
    viewerLoading.value = false;
    viewerError.value = true;
};

const handleZoom = (delta) => {
    const newZoom = zoomLevel.value + delta;
    if (newZoom >= 0.1 && newZoom <= 5) {
        zoomLevel.value = Math.round(newZoom * 10) / 10;
    }
};

const handleWheel = (event) => {
    const delta = event.deltaY > 0 ? -0.1 : 0.1;
    handleZoom(delta);
};

onMounted(() => {
    pagination.updateData(props.stockIns)
})

watch(() => props.stockIns, (newVal) => {
    pagination.updateData(newVal)
}, { deep: true })

watch(() => form.quantity, (newVal) => {
    syncEntriesToQuantity(newVal)
})
</script>
