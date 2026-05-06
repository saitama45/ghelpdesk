<template>
    <AppLayout title="Inventory Report">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Unique Assets</p>
                                <p class="text-2xl font-black text-gray-900 mt-1">{{ summary.total_items }}</p>
                            </div>
                            <div class="p-3 bg-blue-50 rounded-lg">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Stock on Hand</p>
                                <p class="text-2xl font-black text-emerald-600 mt-1">{{ summary.total_soh }}</p>
                            </div>
                            <div class="p-3 bg-emerald-50 rounded-lg">
                                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Inventory Value</p>
                                <p class="text-2xl font-black text-indigo-600 mt-1">{{ formatCurrency(summary.total_inventory_value) }}</p>
                            </div>
                            <div class="p-3 bg-indigo-50 rounded-lg">
                                <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Out of Stock Assets</p>
                                <p class="text-2xl font-black text-red-600 mt-1">{{ summary.out_of_stock_count }}</p>
                            </div>
                            <div class="p-3 bg-red-50 rounded-lg">
                                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Category</label>
                            <select v-model="filterForm.category_id" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Categories</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Location</label>
                            <select v-model="filterForm.location" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Locations</option>
                                <option v-for="loc in locations" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Brand</label>
                            <select v-model="filterForm.brand" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Brands</option>
                                <option v-for="brand in brands" :key="brand" :value="brand">{{ brand }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Type</label>
                            <select v-model="filterForm.type" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Types</option>
                                <option value="Fixed">Fixed</option>
                                <option value="Consumables">Consumables</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Stock Status</label>
                            <select v-model="filterForm.stock_status" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Statuses</option>
                                <option value="in_stock">In Stock</option>
                                <option value="out_of_stock">Out of Stock</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button @click="resetFilters" class="w-full px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 transition-colors">
                                Reset Filters
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Inventory Table -->
                <DataTable
                    title="Inventory by Location"
                    subtitle="Internal stock on hand grouped by holding location"
                    search-placeholder="Search by code, brand, model..."
                    :search="pagination.search.value"
                    :data="pagination.data.value"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="pagination.showingText.value"
                    :is-loading="pagination.isLoading.value"
                    @update:search="pagination.search.value = $event"
                    @go-to-page="pagination.goToPage"
                    @change-per-page="pagination.changePerPage"
                >
                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category / Sub</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Asset Info</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Stock on Hand</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Cost</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider font-bold">Total Value</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <template v-for="group in groupRowsByLocation(data)" :key="group.location">
                            <tr class="bg-slate-50">
                                <td colspan="8" class="px-6 py-3 border-y border-slate-200">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        <button
                                            type="button"
                                            @click="toggleLocation(group.location)"
                                            class="flex w-fit items-center gap-2 text-left"
                                            :aria-expanded="!isLocationCollapsed(group.location)"
                                        >
                                            <svg class="h-4 w-4 text-slate-500 transition-transform"
                                                 :class="isLocationCollapsed(group.location) ? '-rotate-90' : 'rotate-0'"
                                                 fill="none"
                                                 stroke="currentColor"
                                                 viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                            <span class="text-sm font-black uppercase tracking-wide text-slate-900">{{ group.location }}</span>
                                        </button>

                                        <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3 lg:min-w-[520px]">
                                            <div class="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                <span class="block font-bold uppercase tracking-wider text-slate-400">Items</span>
                                                <span class="font-black text-slate-900">{{ formatPlainQuantity(group.summary.item_count) }}</span>
                                            </div>
                                            <div class="rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2">
                                                <span class="block font-bold uppercase tracking-wider text-emerald-600">Stock on Hand</span>
                                                <span class="font-black text-emerald-800">{{ formatPlainQuantity(group.summary.total_soh) }}</span>
                                            </div>
                                            <div class="rounded-md border border-indigo-100 bg-indigo-50 px-3 py-2">
                                                <span class="block font-bold uppercase tracking-wider text-indigo-600">Inventory Value</span>
                                                <span class="font-black text-indigo-800">{{ formatCurrency(group.summary.total_value) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <template v-if="!isLocationCollapsed(group.location)">
                                <tr v-for="row in group.rows" :key="`${row.asset_id}-${row.location}`" class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-sm font-bold text-blue-600 font-mono">{{ row.asset?.item_code }}</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col space-y-1">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-800 border border-blue-200 uppercase w-fit">
                                                {{ row.asset?.category?.name || 'N/A' }}
                                            </span>
                                            <span v-if="row.asset?.sub_category" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-purple-100 text-purple-800 border border-purple-200 uppercase w-fit">
                                                {{ row.asset?.sub_category.name }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <div class="text-sm font-medium text-gray-900">{{ row.asset?.brand }} {{ row.asset?.model }}</div>
                                            <div class="text-xs text-gray-500 max-w-xs truncate">{{ row.asset?.description || 'No description' }}</div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider w-fit"
                                              :class="row.asset?.type === 'Fixed' ? 'bg-indigo-100 text-indigo-800 border border-indigo-200' : 'bg-orange-100 text-orange-800 border border-orange-200'">
                                            {{ row.asset?.type }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span class="text-sm font-black" :class="(row.soh || 0) <= 0 ? 'text-red-600' : 'text-emerald-700'">
                                            {{ row.soh || 0 }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <span class="text-sm text-gray-600">
                                            {{ formatCurrency(row.asset?.cost) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right font-bold">
                                        <span class="text-sm text-gray-900">
                                            {{ formatCurrency(row.total_value) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <button
                                            @click="viewHistory(row)"
                                            class="text-blue-600 hover:text-blue-900 text-xs font-bold uppercase tracking-widest p-2 hover:bg-blue-50 rounded-lg transition-colors"
                                            title="View Transaction History"
                                        >
                                            History
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- History Modal -->
        <Modal :show="showHistoryModal" @close="closeHistoryModal" max-width="4xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-lg font-black text-gray-900 uppercase tracking-tight">Transaction Trail</h3>
                        <p class="text-sm text-gray-500" v-if="selectedAssetForHistory">
                            History for <span class="font-bold text-blue-600">{{ selectedAssetForHistory.item_code }}</span> at <span class="font-bold text-gray-900">{{ historyLocation }}</span>
                        </p>
                    </div>
                    <button @click="closeHistoryModal" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div v-if="isLoadingHistory" class="py-12 flex flex-col items-center justify-center space-y-4">
                    <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-sm font-bold text-gray-500 uppercase tracking-widest">Loading History...</p>
                </div>

                <div v-else class="space-y-8 max-h-[60vh] overflow-y-auto pr-2">
                    <div v-for="group in groupedHistory" :key="group.date">
                        <h4 class="flex items-center gap-2 mb-3">
                            <span class="px-3 py-1 bg-blue-50 text-blue-700 text-[10px] font-black uppercase tracking-[0.2em] rounded-md border border-blue-100 shadow-sm">
                                {{ formatDateHeader(group.date) }}
                            </span>
                            <span class="px-3 py-1 text-[10px] font-black uppercase tracking-[0.2em] rounded-md border shadow-sm"
                                  :class="getQuantityBadgeClass(group.total_quantity)">
                                Total Qty: {{ formatSignedQuantity(group.total_quantity) }}
                            </span>
                            <div class="h-px flex-1 bg-gradient-to-r from-blue-100 to-transparent"></div>
                        </h4>

                        <div class="overflow-hidden rounded-xl border border-gray-100 shadow-sm bg-white">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50/50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-[9px] font-black text-gray-400 uppercase tracking-widest">Action</th>
                                        <th class="px-4 py-2 text-center text-[9px] font-black text-gray-400 uppercase tracking-widest">Total Qty</th>
                                        <th class="px-4 py-2 text-left text-[9px] font-black text-gray-400 uppercase tracking-widest">Reference</th>
                                        <th class="px-4 py-2 text-left text-[9px] font-black text-gray-400 uppercase tracking-widest">Received By</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="tx in group.transactions" :key="tx.key" class="hover:bg-gray-50/50 transition-colors">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                                  :class="getTransactionTypeClass(tx.transaction_type)">
                                                {{ tx.transaction_type }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-black"
                                            :class="getQuantityTextClass(tx.total_quantity)">
                                            {{ formatSignedQuantity(tx.total_quantity) }}
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500">
                                            <div class="flex flex-col">
                                                <span class="font-bold text-gray-700 leading-tight">Batch Record</span>
                                                <span v-if="tx.dr_numbers.length" class="text-[10px] text-blue-500 font-semibold mt-0.5">DR: {{ tx.dr_numbers.join(', ') }}</span>
                                                <span v-if="formatMovement(tx)" class="text-[10px] text-gray-500 font-semibold mt-0.5">{{ formatMovement(tx) }}</span>
                                                <span v-if="tx.source_count > 1" class="text-[10px] text-gray-400 font-semibold mt-0.5">
                                                    {{ tx.source_count }} rows grouped
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-600 font-medium">
                                            {{ tx.received_by || tx.creator_name || 'System' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="groupedHistory.length === 0" class="py-12 flex flex-col items-center justify-center border-2 border-dashed border-gray-100 rounded-2xl">
                        <svg class="w-12 h-12 text-gray-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
                        <p class="text-sm font-bold text-gray-400 uppercase tracking-widest">No History Records</p>
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
import { usePagination } from '@/Composables/usePagination'
import axios from 'axios'

const props = defineProps({
    assets: Object,
    locationSummaries: Array,
    categories: Array,
    brands: Array,
    locations: Array,
    summary: Object,
    filters: Object
})

const filterForm = reactive({
    category_id: props.filters.category_id || null,
    sub_category_id: props.filters.sub_category_id || null,
    type: props.filters.type || null,
    brand: props.filters.brand || null,
    location: props.filters.location || null,
    stock_status: props.filters.stock_status || null,
})

const pagination = usePagination(props.assets, 'reports.inventory', () => ({ ...filterForm }))

const collapsedLocations = ref(new Set())

const normalizeLocation = (value) => value || 'N/A'

const locationSummaryByName = computed(() => {
    return new Map((props.locationSummaries || []).map(summary => [
        normalizeLocation(summary.location),
        {
            item_count: Number(summary.item_count || 0),
            total_soh: Number(summary.total_soh || 0),
            total_value: Number(summary.total_value || 0),
        },
    ]))
})

const getLocationSummary = (location, rows = []) => {
    const key = normalizeLocation(location)
    const summary = locationSummaryByName.value.get(key)

    if (summary) return summary

    return {
        item_count: rows.length,
        total_soh: rows.reduce((sum, row) => sum + Number(row.soh || 0), 0),
        total_value: rows.reduce((sum, row) => sum + Number(row.total_value || 0), 0),
    }
}

const groupRowsByLocation = (rows = []) => {
    const groups = new Map()

    rows.forEach(row => {
        const location = normalizeLocation(row.location)

        if (!groups.has(location)) {
            groups.set(location, {
                location,
                rows: [],
                summary: getLocationSummary(location),
            })
        }

        groups.get(location).rows.push(row)
    })

    return Array.from(groups.values()).map(group => ({
        ...group,
        summary: getLocationSummary(group.location, group.rows),
    }))
}

const isLocationCollapsed = (location) => collapsedLocations.value.has(normalizeLocation(location))

const toggleLocation = (location) => {
    const key = normalizeLocation(location)
    const next = new Set(collapsedLocations.value)

    if (next.has(key)) {
        next.delete(key)
    } else {
        next.add(key)
    }

    collapsedLocations.value = next
}

// History State
const showHistoryModal = ref(false)
const isLoadingHistory = ref(false)
const selectedAssetForHistory = ref(null)
const historyLocation = ref('')
const transactionHistory = ref([])

const groupedHistory = computed(() => {
    const groups = new Map()

    transactionHistory.value.forEach(tx => {
        const date = toDateKey(tx.receive_date) || toDateKey(tx.latest_tx_at) || 'Unknown Date'
        const quantity = Number(tx.total_quantity ?? tx.quantity ?? 0)
        const receivedBy = tx.received_by || tx.creator_name || 'System'
        const childKey = [
            tx.transaction_type || 'Transaction',
            receivedBy,
            tx.dr_no || 'No DR',
            tx.origin_location || 'No Origin',
            tx.destination_location || 'No Destination',
        ].join('|')

        if (!groups.has(date)) {
            groups.set(date, {
                date,
                total_quantity: 0,
                transactions: new Map(),
            })
        }

        const group = groups.get(date)
        group.total_quantity += quantity

        if (!group.transactions.has(childKey)) {
            group.transactions.set(childKey, {
                key: `${date}|${childKey}`,
                transaction_type: tx.transaction_type || 'Transaction',
                creator_name: tx.creator_name || 'System',
                received_by: tx.received_by || '',
                total_quantity: 0,
                source_count: 0,
                latest_tx_at: tx.latest_tx_at,
                dr_numbers: [],
                origin_locations: [],
                destination_locations: [],
            })
        }

        const child = group.transactions.get(childKey)
        child.total_quantity += quantity
        child.source_count += Number(tx.record_count ?? 1)

        if (tx.dr_no && !child.dr_numbers.includes(tx.dr_no)) {
            child.dr_numbers.push(tx.dr_no)
        }

        pushUnique(child.origin_locations, tx.origin_location)
        pushUnique(child.destination_locations, tx.destination_location)

        if (toTimestamp(tx.latest_tx_at) > toTimestamp(child.latest_tx_at)) {
            child.latest_tx_at = tx.latest_tx_at
        }
    })

    return Array.from(groups.values())
        .map(group => ({
            ...group,
            transactions: Array.from(group.transactions.values())
                .sort((a, b) => toTimestamp(b.latest_tx_at) - toTimestamp(a.latest_tx_at)),
        }))
        .sort((a, b) => sortDateKey(b.date).localeCompare(sortDateKey(a.date)))
})

const viewHistory = async (row) => {
    selectedAssetForHistory.value = row.asset
    historyLocation.value = row.location
    showHistoryModal.value = true
    isLoadingHistory.value = true
    transactionHistory.value = []

    try {
        const response = await axios.get(route('reports.inventory.history', row.asset_id), {
            params: { location: row.location }
        })
        transactionHistory.value = response.data.history
    } catch (error) {
        console.error('Failed to fetch history:', error)
    } finally {
        isLoadingHistory.value = false
    }
}

const closeHistoryModal = () => {
    showHistoryModal.value = false
}

const pushUnique = (items, value) => {
    if (value && !items.includes(value)) {
        items.push(value)
    }
}

const getTransactionTypeClass = (type) => {
    switch (type) {
        case 'Stock In':
        case 'Transfer In':
            return 'bg-emerald-100 text-emerald-800 border border-emerald-200'
        case 'Stock Out':
        case 'Transfer Out':
            return 'bg-red-100 text-red-800 border border-red-200'
        default:
            return 'bg-gray-100 text-gray-800 border border-gray-200'
    }
}

const padDatePart = (value) => String(value).padStart(2, '0')

const toDateKey = (value) => {
    if (!value) return ''

    if (value instanceof Date) {
        if (Number.isNaN(value.getTime())) return ''

        return [
            value.getFullYear(),
            padDatePart(value.getMonth() + 1),
            padDatePart(value.getDate()),
        ].join('-')
    }

    const normalized = String(value).trim()
    const dateMatch = normalized.match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (dateMatch) {
        return `${dateMatch[1]}-${dateMatch[2]}-${dateMatch[3]}`
    }

    const parsed = new Date(normalized)
    if (Number.isNaN(parsed.getTime())) return ''

    return toDateKey(parsed)
}

const sortDateKey = (value) => value === 'Unknown Date' ? '0000-00-00' : value

const toTimestamp = (value) => {
    const timestamp = new Date(value || 0).getTime()
    return Number.isNaN(timestamp) ? 0 : timestamp
}

const parseDateOnly = (value) => {
    const dateKey = toDateKey(value)
    const match = dateKey.match(/^(\d{4})-(\d{2})-(\d{2})$/)
    if (!match) return null

    const [, year, month, day] = match
    return new Date(Number(year), Number(month) - 1, Number(day))
}

const formatSignedQuantity = (value) => {
    const quantity = Number(value || 0)
    return `${quantity > 0 ? '+' : ''}${quantity}`
}

const formatPlainQuantity = (value) => {
    return new Intl.NumberFormat('en-US').format(Number(value || 0))
}

const formatLocationList = (locations = []) => locations.filter(Boolean).join(', ')

const formatMovement = (tx) => {
    const origin = formatLocationList(tx.origin_locations)
    const destination = formatLocationList(tx.destination_locations)

    if (origin && destination) return `Origin: ${origin} -> Destination: ${destination}`
    if (origin) return `Origin: ${origin}`
    if (destination) return `Destination: ${destination}`

    return ''
}

const getQuantityTextClass = (value) => {
    const quantity = Number(value || 0)

    if (quantity > 0) return 'text-emerald-600'
    if (quantity < 0) return 'text-red-600'

    return 'text-gray-600'
}

const getQuantityBadgeClass = (value) => {
    const quantity = Number(value || 0)

    if (quantity > 0) return 'bg-emerald-50 text-emerald-700 border-emerald-100'
    if (quantity < 0) return 'bg-red-50 text-red-700 border-red-100'

    return 'bg-gray-50 text-gray-600 border-gray-100'
}

const formatDateTime = (value) => {
    if (!value) return '-'
    const date = new Date(value)
    return date.toLocaleString('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    })
}

const formatDateHeader = (value) => {
    if (!value || value === 'Unknown Date') return value
    const date = parseDateOnly(value)
    if (!date) return value

    return date.toLocaleString('en-PH', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    })
}

const formatTime = (value) => {
    if (!value) return '-'
    const date = new Date(value)
    return date.toLocaleString('en-PH', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    })
}

const formatCurrency = (value) => {
    if (!value) return 'PHP 0.00'
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(value)
}

const applyFilters = () => {
    pagination.currentPage.value = 1

    router.get(route('reports.inventory'), {
        ...filterForm,
        search: pagination.search.value,
        per_page: pagination.perPage.value,
        page: 1,
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['assets', 'summary', 'locationSummaries', 'locations']
    })
}

const resetFilters = () => {
    Object.assign(filterForm, {
        category_id: null,
        sub_category_id: null,
        type: null,
        brand: null,
        location: null,
        stock_status: null,
    })
    pagination.search.value = ''
    applyFilters()
}

onMounted(() => {
    pagination.updateData(props.assets)
})

watch(() => props.assets, (newAssets) => {
    pagination.updateData(newAssets)
}, { deep: true })

</script>
