<template>
    <AppLayout title="Items">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Item Management"
                    subtitle="Manage items linked to categories and sub-categories"
                    search-placeholder="Search items by name, description, priority..."
                    empty-message="No items found. Create your first item to get started."
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
                        <button
                            v-if="hasPermission('items.create')"
                            @click="openImportModal"
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            <span>Import</span>
                        </button>
                        <button
                            v-if="hasPermission('items.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Item</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Links</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="item in data" :key="item.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-teal-500 to-teal-600 rounded-full flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ item.name }}</div>
                                        <div class="text-sm text-gray-500 max-w-xs truncate">{{ item.description || 'No description' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col space-y-1">
                                    <div class="flex items-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-800 border border-blue-200 uppercase tracking-wider">
                                            Cat: {{ item.category?.name || 'N/A' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-purple-100 text-purple-800 border border-purple-200 uppercase tracking-wider">
                                            Sub: {{ item.sub_category?.name || 'N/A' }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="getPriorityClass(item.priority)" 
                                      class="inline-flex px-2.5 py-0.5 text-xs font-bold rounded-md border uppercase tracking-wider">
                                    {{ item.priority }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="item.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" 
                                      class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    {{ item.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="hasPermission('items.edit')"
                                        @click="editItem(item)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Item"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('items.delete')"
                                        @click="deleteItem(item)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Item"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeImportModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Import Items</h3>
                        <button @click="closeImportModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="space-y-4">
                        <p class="text-sm text-gray-600">
                            Import items in bulk using an Excel file. Columns: <span class="font-semibold">name</span>, <span class="font-semibold">description</span>,
                            <span class="font-semibold">priority</span> (dropdown: Low/Medium/High/Urgent),
                            <span class="font-semibold">category</span> (dropdown: select from existing),
                            <span class="font-semibold">sub_category</span> (dropdown: select from existing),
                            <span class="font-semibold">is_active</span> (1 or 0).
                        </p>

                        <!-- Template Download -->
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4">
                            <a href="/items/template" class="flex items-center space-x-3 text-blue-600 hover:text-blue-800 transition-colors group">
                                <div class="h-10 w-10 bg-blue-100 group-hover:bg-blue-200 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-semibold">Download Excel Template</div>
                                    <div class="text-xs text-gray-500">items-import-template.xlsx</div>
                                </div>
                            </a>
                        </div>

                        <!-- Divider -->
                        <div class="flex items-center space-x-3">
                            <div class="flex-1 border-t border-gray-200"></div>
                            <span class="text-xs text-gray-400 font-medium uppercase tracking-wider">Then Upload</span>
                            <div class="flex-1 border-t border-gray-200"></div>
                        </div>

                        <!-- File Upload -->
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Upload Excel File</label>
                            <div v-if="!importFile"
                                 @click="importFileInput.click()"
                                 class="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 hover:bg-gray-100 hover:border-gray-400 p-6 text-center cursor-pointer transition-colors">
                                <svg class="w-8 h-8 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                                <p class="text-sm text-gray-600 font-medium">Click to choose a CSV file</p>
                                <p class="text-xs text-gray-400 mt-1">CSV only, max 2MB</p>
                            </div>
                            <div v-else class="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center space-x-2 min-w-0">
                                    <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <span class="text-sm font-medium text-green-800 truncate">{{ importFile.name }}</span>
                                </div>
                                <button @click="removeImportFile" type="button" class="text-gray-400 hover:text-red-500 transition-colors ml-2 flex-shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <input ref="importFileInput" type="file" accept=".xlsx" class="hidden" @change="handleImportFileSelect">
                        </div>

                        <!-- Import Result -->
                        <div v-if="importResult" class="rounded-lg p-4" :class="importResult.errors.length === 0 ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200'">
                            <div class="flex items-center space-x-2 mb-1">
                                <svg v-if="importResult.errors.length === 0" class="w-4 h-4 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <svg v-else class="w-4 h-4 text-yellow-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <span class="text-sm font-semibold" :class="importResult.errors.length === 0 ? 'text-green-800' : 'text-yellow-800'">
                                    {{ importResult.imported }} record{{ importResult.imported !== 1 ? 's' : '' }} imported
                                </span>
                            </div>
                            <ul v-if="importResult.errors.length > 0" class="space-y-0.5 max-h-28 overflow-y-auto mt-2">
                                <li v-for="(error, i) in importResult.errors" :key="i" class="text-xs text-red-700">{{ error }}</li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex justify-between pt-6 border-t mt-6">
                        <button type="button" @click="closeImportModal"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                            Close
                        </button>
                        <button type="button" @click="submitImport" :disabled="!importFile || isImporting"
                                class="px-6 py-2 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2">
                            <svg v-if="isImporting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ isImporting ? 'Importing...' : 'Import' }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">
                            {{ isEditing ? 'Edit Item' : 'Create Item' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Category</label>
                                <select v-model="form.category_id"
                                        class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <option :value="null">None</option>
                                    <option v-for="category in categories" :key="category.id" :value="category.id">
                                        {{ category.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Sub-Category</label>
                                <select v-model="form.sub_category_id"
                                        class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <option :value="null">None</option>
                                    <option v-for="sub in subCategories" :key="sub.id" :value="sub.id">
                                        {{ sub.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Item Name</label>
                                <input v-model="form.name" type="text" required
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Priority</label>
                                <select v-model="form.priority" required
                                        class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                    <option value="Urgent">Urgent</option>
                                </select>
                            </div>
                        </div>

                        <!-- SLA Targets Display -->
                        <div v-if="form.priority" class="grid grid-cols-2 gap-4 p-4 rounded-xl border transition-colors duration-200" :class="getSlaBoxClass(form.priority)">
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" :class="getSlaLabelClass(form.priority)">Target Response</label>
                                <div class="text-lg font-black" :class="getSlaValueClass(form.priority)">{{ getSlaTarget(form.priority, 'response') }} Hours</div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" :class="getSlaLabelClass(form.priority)">Target Resolution</label>
                                <div class="text-lg font-black" :class="getSlaValueClass(form.priority)">{{ getSlaTarget(form.priority, 'resolution') }} Hours</div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label>
                            <textarea v-model="form.description" rows="3"
                                      class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                        </div>
                        <div v-if="isEditing" class="flex items-center">
                            <input v-model="form.is_active" type="checkbox" id="is_active_item" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <label for="is_active_item" class="ml-2 text-sm font-medium text-gray-700">Active Item</label>
                        </div>
                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="closeModal" 
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                                {{ isEditing ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    items: Object,
    categories: Array,
    subCategories: Array,
    settings: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.items, 'items.index')
const { hasPermission } = usePermission()

const getSlaTarget = (priority, type) => {
    if (!priority) return '0';
    const key = `sla_${priority.toLowerCase()}_${type}`;
    return props.settings[key] || (type === 'response' ? '24' : '72');
};

const getSlaBoxClass = (priority) => {
    switch (priority) {
        case 'Low': return 'bg-green-50 border-green-200';
        case 'Medium': return 'bg-yellow-50 border-yellow-200';
        case 'High': return 'bg-orange-50 border-orange-200';
        case 'Urgent': return 'bg-red-50 border-red-200 shadow-sm';
        default: return 'bg-gray-50 border-gray-200';
    }
};

const getSlaLabelClass = (priority) => {
    switch (priority) {
        case 'Low': return 'text-green-600';
        case 'Medium': return 'text-yellow-600';
        case 'High': return 'text-orange-600';
        case 'Urgent': return 'text-red-600';
        default: return 'text-gray-500';
    }
};

const getSlaValueClass = (priority) => {
    switch (priority) {
        case 'Low': return 'text-green-900';
        case 'Medium': return 'text-yellow-900';
        case 'High': return 'text-orange-900';
        case 'Urgent': return 'text-red-900';
        default: return 'text-gray-900';
    }
};

const showModal = ref(false)
const showImportModal = ref(false)
const isEditing = ref(false)
const currentItem = ref(null)

const form = reactive({
    category_id: null,
    sub_category_id: null,
    name: '',
    description: '',
    priority: 'Medium',
    is_active: true
})

onMounted(() => {
    pagination.updateData(props.items)
})

watch(() => props.items, (newItems) => {
    pagination.updateData(newItems)
}, { deep: true })

const importFile = ref(null)
const importFileInput = ref(null)
const isImporting = ref(false)
const importResult = ref(null)

const handleImportFileSelect = (event) => {
    importFile.value = event.target.files[0] || null
    importResult.value = null
}

const removeImportFile = () => {
    importFile.value = null
    importResult.value = null
    if (importFileInput.value) importFileInput.value.value = ''
}

const submitImport = async () => {
    if (!importFile.value || isImporting.value) return
    isImporting.value = true
    importResult.value = null

    const formData = new FormData()
    formData.append('file', importFile.value)

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        const response = await fetch('/items/import', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: formData
        })
        const result = await response.json()
        importResult.value = result
        if (result.imported > 0) {
            showSuccess(`${result.imported} item${result.imported === 1 ? '' : 's'} imported successfully`)
            router.reload({ only: ['items'] })
        }
    } catch {
        showError('Import failed. Please try again.')
    } finally {
        isImporting.value = false
    }
}

const openImportModal = () => { showImportModal.value = true }
const closeImportModal = () => {
    showImportModal.value = false
    importFile.value = null
    importResult.value = null
    if (importFileInput.value) importFileInput.value.value = ''
}

const openCreateModal = () => {
    isEditing.value = false
    currentItem.value = null
    form.category_id = null
    form.sub_category_id = null
    form.name = ''
    form.description = ''
    form.priority = 'Medium'
    form.is_active = true
    showModal.value = true
}

const editItem = (item) => {
    isEditing.value = true
    currentItem.value = item
    form.category_id = item.category_id
    form.sub_category_id = item.sub_category_id
    form.name = item.name
    form.description = item.description || ''
    form.priority = item.priority || 'Medium'
    form.is_active = item.is_active
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.category_id = null
    form.sub_category_id = null
    form.name = ''
    form.description = ''
    form.priority = 'Medium'
    form.is_active = true
}

const submitForm = () => {
    const url = isEditing.value ? `/items/${currentItem.value.id}` : '/items'
    const method = isEditing.value ? 'put' : 'post'
    
    const requestMethod = method === 'put' ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Item updated successfully' : 'Item created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteItem = async (item) => {
    const confirmed = await confirm({
        title: 'Delete Item',
        message: `Are you sure you want to delete "${item.name}"? This action cannot be undone.`
    })
    
    if (confirmed) {
        destroy(`/items/${item.id}`, {
            onSuccess: () => showSuccess('Item deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete item'
                showError(errorMessage)
            }
        })
    }
}

const getPriorityClass = (priority) => {
    switch (priority) {
        case 'Low':
            return 'bg-green-100 text-green-800 border-green-200'
        case 'Medium':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200'
        case 'High':
            return 'bg-orange-100 text-orange-800 border-orange-200'
        case 'Urgent':
            return 'bg-red-100 text-red-800 border-red-200 shadow-sm animate-pulse'
        default:
            return 'bg-gray-100 text-gray-800 border-gray-200'
    }
}
</script>
