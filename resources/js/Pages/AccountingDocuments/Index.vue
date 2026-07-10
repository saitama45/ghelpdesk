<template>
    <AppLayout title="Accounting Document Reviews">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <!-- Filters -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-wrap items-end gap-3 dark:bg-gray-800 dark:border-gray-700">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Status</label>
                        <select v-model="filterState.status" class="border-gray-300 rounded-lg shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" @change="applyFilters">
                            <option value="">Pending + In Review</option>
                            <option value="pending">Pending</option>
                            <option value="in_review">In Review</option>
                            <option value="approved">Approved</option>
                            <option value="returned">Returned</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Type</label>
                        <select v-model="filterState.document_type" class="border-gray-300 rounded-lg shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" @change="applyFilters">
                            <option value="">All</option>
                            <option value="invoice">Invoice</option>
                            <option value="purchase_order">Purchase Order</option>
                            <option value="quotation">Quotation</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Exceptions</label>
                        <select v-model="filterState.has_exceptions" class="border-gray-300 rounded-lg shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" @change="applyFilters">
                            <option value="">All</option>
                            <option value="1">With exceptions</option>
                            <option value="0">Without exceptions</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">From</label>
                        <input v-model="filterState.date_from" type="date" class="border-gray-300 rounded-lg shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" @change="applyFilters" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">To</label>
                        <input v-model="filterState.date_to" type="date" class="border-gray-300 rounded-lg shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" @change="applyFilters" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Min Confidence</label>
                        <select v-model="filterState.min_confidence" class="border-gray-300 rounded-lg shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600" @change="applyFilters">
                            <option value="">Any</option>
                            <option value="0.9">≥ 90%</option>
                            <option value="0.75">≥ 75%</option>
                            <option value="0.5">≥ 50%</option>
                        </select>
                    </div>
                </div>

                <DataTable
                    title="Accounting Document Reviews"
                    subtitle="Vendor documents submitted from the partner portal"
                    search-placeholder="Search reference, vendor name or code..."
                    empty-message="No documents pending review."
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Reference</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Vendor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Type</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Total</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Confidence</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Received</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="review in data" :key="review.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ review.source_reference_no }}</div>
                                <div v-if="hasExceptions(review)" class="text-xs font-semibold text-amber-600 mt-0.5">
                                    ⚠ {{ review.exceptions_summary.length }} warning(s)
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 dark:text-gray-100">{{ review.vendor_name || '—' }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-300">{{ review.vendor_code }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize dark:text-gray-300">
                                {{ (review.document_type || '').replaceAll('_', ' ') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-gray-800 dark:text-gray-200">
                                {{ money(review.fields?.total_amount) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <span :class="confidenceClass(review.confidence?.overall)" class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    {{ pct(review.confidence?.overall) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                {{ formatDate(review.received_at) }}
                                <div v-if="review.due_at && !isDecided(review)" :class="isOverdue(review) ? 'text-red-600 font-semibold' : 'text-gray-400'" class="text-xs">
                                    due {{ formatDate(review.due_at) }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="statusClass(review.status)" class="inline-flex px-2 py-1 text-xs font-semibold rounded-full capitalize">
                                    {{ review.status.replaceAll('_', ' ') }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <Link :href="route('accounting-documents.show', review.id)"
                                    class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors inline-flex" title="Review">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </Link>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { onMounted, reactive, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { usePagination } from '@/Composables/usePagination'

const props = defineProps({
    reviews: Object,
    filters: { type: Object, default: () => ({}) },
})

const filterState = reactive({
    status: props.filters.status || '',
    document_type: props.filters.document_type || '',
    has_exceptions: props.filters.has_exceptions ?? '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    min_confidence: props.filters.min_confidence || '',
})

const activeFilterParams = () => {
    const params = {}
    for (const [key, value] of Object.entries(filterState)) {
        if (value !== '' && value !== null) params[key] = value
    }
    return params
}

const pagination = usePagination(props.reviews, 'accounting-documents.index', activeFilterParams, { dataKey: 'reviews' })

onMounted(() => pagination.updateData(props.reviews))
watch(() => props.reviews, (value) => pagination.updateData(value), { deep: true })

const applyFilters = () => {
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const money = (value) => (value == null ? '—' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }))
const pct = (value) => (value == null ? '—' : `${Math.round(value * 100)}%`)
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—')
const hasExceptions = (review) => Array.isArray(review.exceptions_summary) && review.exceptions_summary.length > 0
const isDecided = (review) => ['approved', 'returned', 'rejected'].includes(review.status)
const isOverdue = (review) => review.due_at && new Date(review.due_at) < new Date()

const confidenceClass = (value) => {
    if (value == null) return 'bg-gray-100 text-gray-600'
    if (value >= 0.9) return 'bg-green-100 text-green-800'
    if (value >= 0.75) return 'bg-yellow-100 text-yellow-800'
    return 'bg-red-100 text-red-800'
}

const statusClass = (status) => ({
    pending: 'bg-blue-100 text-blue-800',
    in_review: 'bg-indigo-100 text-indigo-800',
    approved: 'bg-green-100 text-green-800',
    returned: 'bg-yellow-100 text-yellow-800',
    rejected: 'bg-red-100 text-red-800',
}[status] || 'bg-gray-100 text-gray-600')
</script>
