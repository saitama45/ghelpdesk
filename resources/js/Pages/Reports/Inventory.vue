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
                    title="Inventory Report"
                    subtitle="Detailed view of assets, stock on hand, and valuation by location"
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category / Sub</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Asset Info</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Stock on Hand</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Cost</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider font-bold">Total Value</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="row in data" :key="`${row.asset_id}-${row.location}`" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-bold text-blue-600 font-mono">{{ row.asset?.item_code }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-semibold text-gray-900">{{ row.location || 'N/A' }}</span>
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
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { usePagination } from '@/Composables/usePagination'

const props = defineProps({
    assets: Object,
    categories: Array,
    brands: Array,
    locations: Array,
    summary: Object,
    filters: Object
})

const pagination = usePagination(props.assets, 'reports.inventory')

const filterForm = reactive({
    category_id: props.filters.category_id || null,
    sub_category_id: props.filters.sub_category_id || null,
    type: props.filters.type || null,
    brand: props.filters.brand || null,
    location: props.filters.location || null,
    stock_status: props.filters.stock_status || null,
})

const formatCurrency = (value) => {
    if (!value) return 'PHP 0.00'
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(value)
}

const applyFilters = () => {
    router.get(route('reports.inventory'), {
        ...filterForm,
        search: pagination.search.value
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['assets', 'summary']
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

watch(() => pagination.search.value, () => {
    applyFilters()
})
</script>
