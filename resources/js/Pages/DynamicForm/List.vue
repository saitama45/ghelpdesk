<script setup>
import { ref, onMounted, watch } from 'vue'
import { Link, Head, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import { useConfirm } from '@/Composables/useConfirm'
import { useToast } from '@/Composables/useToast'
import { useErrorHandler } from '@/Composables/useErrorHandler'

const props = defineProps({
    records: Object,
    forms: Array,
    filters: Object,
})

const { hasPermission } = usePermission()
const { confirm } = useConfirm()
const { showSuccess, showError } = useToast()
const { destroy: deleteRequest } = useErrorHandler()

const search = ref(props.filters?.search ?? '')
const status = ref(props.filters?.status ?? '')
const showCreateSection = ref(false)

const pagination = usePagination(props.records, 'dynamic-form.list', () => ({
    search: search.value,
    status: status.value,
}))

onMounted(() => {
    pagination.updateData(props.records)
})

watch([search, status], () => {
    pagination.goToPage(1)
})

watch(() => props.records, (newVal) => {
    pagination.updateData(newVal)
}, { deep: true })

const deleteRecord = async (record) => {
    const confirmed = await confirm({
        title: 'Delete Record',
        message: `Are you sure you want to delete this record from "${record.definition.name}"?`
    })
    
    if (confirmed) {
        deleteRequest(route('dynamic-form.destroy', { slug: record.definition.slug, id: record.id }), {
            onSuccess: () => showSuccess('Record deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete record'
                showError(errorMessage)
            }
        })
    }
}

const STATUS_COLORS = {
    'Open': 'bg-blue-100 text-blue-700 border-blue-200',
    'Approved': 'bg-emerald-100 text-emerald-800 border-emerald-200',
    'Cancelled': 'bg-rose-100 text-rose-800 border-rose-200',
    'Rejected': 'bg-red-100 text-red-800 border-red-200',
    'In Progress': 'bg-amber-100 text-amber-800 border-amber-200',
}

function statusClass(s) {
    if (!s) return 'bg-gray-100 text-gray-500 border-gray-200'
    if (s.startsWith('Approved Level')) return 'bg-indigo-100 text-indigo-700 border-indigo-200'
    return STATUS_COLORS[s] ?? 'bg-gray-100 text-gray-500 border-gray-200'
}

const getDisplayValue = (record) => {
    // Show the first available text field from the data if possible, or just a summary
    const data = record.data || {}
    const firstKey = Object.keys(data).find(k => k !== 'items' && typeof data[k] === 'string' && data[k].length > 0)
    return firstKey ? data[firstKey] : 'No summary available'
}
</script>

<template>
    <Head title="Dynamic Forms" />

    <AppLayout title="Dynamic Forms" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6 bg-gray-50/50 min-h-screen">
            <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
                
                <!-- Header with Toggle Button -->
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h1 class="text-3xl font-black text-gray-900 tracking-tight">Dynamic Forms</h1>
                        <p class="text-sm text-gray-500 font-medium mt-1">Submit and track custom form requests.</p>
                    </div>
                    <button @click="showCreateSection = !showCreateSection"
                        :class="showCreateSection ? 'bg-gray-200 text-gray-700' : 'bg-indigo-600 text-white shadow-lg shadow-indigo-100 hover:bg-indigo-700'"
                        class="flex items-center gap-2 px-6 py-3 rounded-2xl font-black text-sm transition-all">
                        <svg class="w-4 h-4 transition-transform" :class="showCreateSection ? 'rotate-45' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ showCreateSection ? 'Close' : 'New Form Submission' }}
                    </button>
                </div>

                <!-- Create Section (Selection Tiles) -->
                <div v-if="showCreateSection" class="mb-10 animate-in fade-in slide-in-from-top-4 duration-300">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-px flex-1 bg-gray-200"></div>
                        <h2 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Select a form to start</h2>
                        <div class="h-px flex-1 bg-gray-200"></div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <Link v-for="form in forms" :key="form.id" :href="route('dynamic-form.index', { slug: form.slug, create: 1 })"
                            class="bg-white p-6 rounded-[2rem] shadow-xl shadow-gray-100/50 border border-gray-100 text-left hover:border-indigo-500 hover:shadow-indigo-100/50 transition-all group">
                            <div class="w-12 h-12 bg-indigo-50 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <h4 class="text-sm font-black text-gray-900 mb-1">{{ form.name }}</h4>
                            <p class="text-xs text-gray-400 font-medium mb-3 line-clamp-2 min-h-[2.5rem]">{{ form.description || 'No description available.' }}</p>
                            <div class="flex items-center justify-between mt-auto pt-2 border-t border-gray-50">
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">
                                    {{ form.approval_levels > 0 ? `${form.approval_levels} Steps` : 'No Approval' }}
                                </span>
                                <div class="p-1.5 bg-gray-50 rounded-lg group-hover:bg-indigo-50 transition-colors">
                                    <svg class="w-3.5 h-3.5 text-gray-400 group-hover:text-indigo-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                    </svg>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <DataTable
                    title="Recent Submissions"
                    subtitle="Track the status of all your custom form requests"
                    search-placeholder="Search records..."
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
                    <template #actions>
                        <select v-model="status" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm font-bold text-gray-700 bg-white shadow-sm">
                            <option value="">All Statuses</option>
                            <option value="Open">Open</option>
                            <option value="Approved">Approved</option>
                            <option value="Rejected">Rejected</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </template>

                    <template #header>
                        <tr class="bg-gray-50/80 backdrop-blur-sm">
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-400 uppercase tracking-widest">Form Type</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-400 uppercase tracking-widest">Summary</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-400 uppercase tracking-widest">Submitted By</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-400 uppercase tracking-widest">Date</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-400 uppercase tracking-widest">Status</th>
                            <th class="px-6 py-4 text-right text-xs font-black text-gray-400 uppercase tracking-widest">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="record in data" :key="record.id" class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/30 transition-all duration-300 border-b border-gray-100 last:border-0">
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-all duration-500">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-black text-gray-900 group-hover:text-indigo-600 transition-colors">{{ record.definition.name }}</div>
                                        <div class="text-[10px] text-gray-400 font-bold uppercase tracking-tighter">ID: #{{ record.id }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-600 truncate max-w-xs">{{ getDisplayValue(record) }}</div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900">{{ record.creator?.name || 'System' }}</div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span class="text-xs font-bold text-gray-500">{{ new Date(record.created_at).toLocaleDateString() }}</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span :class="statusClass(record.status)" class="inline-flex items-center px-3 py-1 text-[10px] font-black uppercase tracking-widest rounded-full border shadow-sm">
                                    {{ record.status }}
                                </span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-right">
                                <div class="flex justify-end space-x-2">
                                    <Link
                                        :href="route('dynamic-form.show', { slug: record.definition.slug, id: record.id })"
                                        class="p-2 text-indigo-600 hover:text-white hover:bg-indigo-600 rounded-xl transition-all duration-300 shadow-sm flex items-center justify-center"
                                        title="View Details"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </Link>
                                    
                                    <button
                                        v-if="hasPermission(record.definition.slug + '.delete')"
                                        @click="deleteRecord(record)"
                                        class="p-2 text-rose-600 hover:text-white hover:bg-rose-600 rounded-xl transition-all duration-300 shadow-sm flex items-center justify-center"
                                        title="Delete Record"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.font-black { font-weight: 900; }
.tracking-widest { letter-spacing: 0.15em; }
</style>
